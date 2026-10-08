<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCP\Cache\CappedMemoryCache;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\ILimitAwareCountUsersBackend;
use OCP\User\Backend\ISetDisplayNameBackend;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use OCP\UserInterface;
use Override;
use Psr\Log\LoggerInterface;

class UserBackend extends ABackend implements IUserBackend, UserInterface, ICheckPasswordBackend, IGetDisplayNameBackend, ISetDisplayNameBackend, ILimitAwareCountUsersBackend {
	private string $dbName = 'global_scale_users';

	/** @var CappedMemoryCache<array{displayname?: string}|false> $cache */
	private CappedMemoryCache $cache;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ISession $session,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IUserConfig $userConfig,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder $rootFolder,
	) {
		$this->cache = new CappedMemoryCache();
	}

	#[\Override]
	public function getBackendName(): string {
		return 'user_globalsiteselector';
	}

	/**
	 * Creates a user if it does not exist.
	 */
	public function createUserIfNotExists(string $uid, bool $firstLoginIfNeeded = true): void {
		if (!$this->loadUser($uid)) {
			$values = [
				'uid' => $uid,
			];

			$qb = $this->db->getQueryBuilder();
			$qb->insert($this->dbName);
			foreach ($values as $column => $value) {
				$qb->setValue($column, $qb->createNamedParameter($value));
			}
			$qb->executeStatement();
			unset($this->cache[$uid]);
		}

		### Code taken from lib/private/User/Session.php - function prepareUserLogin() ###
		//trigger creation of user home and /files folder
		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			// copy skeleton
			\OC_Util::copySkeleton($uid, $userFolder);
		} catch (NotPermittedException) {
			// read only uses
		}

		// emulate first login if needed
		if ($firstLoginIfNeeded
			&& !$this->userConfig->getValueBool($uid, Application::APP_ID, ConfigLexicon::FIRST_LOGIN)) {
			// trigger any other initialization
			$user = $this->userManager->get($uid);
			$this->eventDispatcher->dispatch(IUser::class . '::firstLogin', new GenericEvent($user));
			$this->eventDispatcher->dispatchTyped(new UserFirstTimeLoggedInEvent($user));

			$this->userConfig->setValueBool($uid, Application::APP_ID, ConfigLexicon::FIRST_LOGIN, true);
		}
	}

	public function updateAttributes(string $uid, array $attributes): void {
		$user = $this->userManager->get($uid);

		$userData = $attributes['userData'];

		$newEmail = $userData['email'] ?? null;
		$newDisplayName = $userData['displayName'] ?? null;
		$newQuota = $userData['quota'] ?? null;
		$newGroups = $userData['groups'] ?? null;

		$currentEmail = $user?->getEMailAddress();
		if ($newEmail !== null
			&& $currentEmail !== $newEmail) {
			$user->setEMailAddress($newEmail);
		}
		$currentDisplayName = $this->getDisplayName($uid);
		if ($user !== null && $newDisplayName !== null && $currentDisplayName !== $newDisplayName) {
			$this->setDisplayName($uid, $newDisplayName);
			$user->setDisplayName($newDisplayName);
			$this->eventDispatcher->dispatchTyped(new UserChangedEvent($user, 'displayname', $newDisplayName, $currentDisplayName));
			\OC_Hook::emit(
				'OC_User', 'changeUser',
				[
					'user' => $user,
					'feature' => 'displayName',
					'value' => $newDisplayName
				]
			);
		}

		if ($newQuota !== null) {
			$user->setQuota($newQuota);
		}

		if ($newGroups !== null) {
			$groupManager = $this->groupManager;
			$oldGroups = $groupManager->getUserGroupIds($user);

			$groupsToAdd = array_unique(array_diff($newGroups, $oldGroups));
			$groupsToRemove = array_diff($oldGroups, $newGroups);

			foreach ($groupsToAdd as $group) {
				if (strtolower((string)$group) === 'admin') {
					continue;
				}

				if (!($groupManager->groupExists($group))) {
					$groupManager->createGroup($group);
				}
				$groupManager->get($group)->addUser($user);
			}

			foreach ($groupsToRemove as $group) {
				$groupManager->get($group)->removeUser($user);
			}
		}
	}

	#[Override]
	public function deleteUser($uid): bool {
		if (!$this->loadUser($uid)) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$affected = $qb->delete($this->dbName)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();

		if (isset($this->cache[$uid])) {
			unset($this->cache[$uid]);
		}

		return $affected > 0;
	}

	#[Override]
	public function getUsers($search = '', $limit = null, $offset = null): array {
		$limit = $this->fixLimit($limit);

		$users = $this->getDisplayNames($search, $limit, $offset);
		$userIds = array_map(strval(...), array_keys($users));
		sort($userIds, SORT_STRING | SORT_FLAG_CASE);
		return $userIds;
	}

	#[Override]
	public function countUsers(int $limit = 0): int|false {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('uid'))
			->from($this->dbName);
		return $query->executeQuery()->fetchOne();
	}

	#[Override]
	public function userExists($uid): bool {
		return $this->loadUser($uid);
	}

	#[Override]
	public function setDisplayName(string $uid, string $displayName): bool {
		if (mb_strlen($displayName) > 64) {
			throw new \InvalidArgumentException('Invalid displayname');
		}

		if (!$this->loadUser($uid)) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->dbName)
			->set('displayname', $qb->createNamedParameter($displayName))
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();

		$this->cache[$uid] = ['displayname' => $displayName];

		return true;
	}

	#[Override]
	public function getDisplayName($uid): string {
		$this->loadUser($uid);
		return empty($this->cache[$uid]['displayname']) ? $uid : $this->cache[$uid]['displayname'];
	}

	#[Override]
	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		$limit = $this->fixLimit($limit);

		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'displayname')
			->from($this->dbName, 'u')
			->leftJoin('u', 'preferences', 'p', $qb->expr()->andX(
				$qb->expr()->eq('userid', 'uid'),
				$qb->expr()->eq('appid', $qb->expr()->literal('settings')),
				$qb->expr()->eq('configkey', $qb->expr()->literal('email')))
			)
			// sqlite doesn't like re-using a single named parameter here
			->where($qb->expr()->iLike('uid', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orWhere($qb->expr()->iLike('displayname', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orWhere($qb->expr()->iLike('configvalue', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orderBy($qb->func()->lower('displayname'), 'ASC')
			->setMaxResults($limit);

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		$result = $qb->executeQuery();
		$displayNames = [];
		while ($row = $result->fetch()) {
			$displayNames[(string)$row['uid']] = (string)$row['displayname'];
			$this->cache[(string)$row['uid']] = ['displayname' => (string)$row['displayname']];
		}
		$result->closeCursor();

		return $displayNames;
	}

	#[Override]
	public function hasUserListings(): bool {
		return true;
	}

	/**
	 * TODO check if this is actually called
	 *
	 * @see IApacheBackend
	 */
	public function isSessionActive(): bool {
		return ($this->getCurrentUserId() !== '');
	}

	/**
	 * TODO check if this is actually called
	 *
	 * @see IApacheBackend
	 */
	public function getCurrentUserId(): string {
		$uid = $this->session->get('globalScale.uid');

		if (!empty($uid) && $this->userExists($uid)) {
			$this->session->set('last-password-confirm', time());

			return $uid;
		}

		return '';
	}

	#[Override]
	public function checkPassword(string $loginName, string $password): string|false {
		// if the user was successfully authenticated by the global site selector
		// master and forwarded to the client the uid is stored in the session.
		// In this case we can trust the global site selector that the password was
		// checked.
		$currentUid = $this->session->get('globalScale.uid');
		if ($currentUid === $loginName) {
			return $loginName;
		}

		return false;
	}

	public function loadUser(string $loginName): bool {
		if (isset($this->cache[$loginName])) {
			return $this->cache[$loginName] !== false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('displayname')
			->from($this->dbName)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($loginName)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$user = $result->fetch();
		if ($user !== false) {
			$this->cache[$loginName]['displayname'] = $user['displayname'];
			return true;
		} else {
			$this->cache[$loginName] = false;
			return false;
		}
	}

	private function fixLimit(mixed $limit): ?int {
		if (is_int($limit) && $limit >= 0) {
			return $limit;
		}

		return null;
	}
}
