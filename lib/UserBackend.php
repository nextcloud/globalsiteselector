<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

use OC\User\Backend;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use OCP\UserInterface;

class UserBackend implements IUserBackend, UserInterface, ICountUsersBackend {
	private string $dbName = 'global_scale_users';

	/** @var UserInterface[] */
	private static array $backends = [];

	public function __construct(
		private IDBConnection $db,
		private ISession $session,
		private IEventDispatcher $eventDispatcher,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
	) {
	}


	/**
	 * Backend name to be shown in user management
	 *
	 * @return string the name of the backend to be shown
	 * @since 0.11.0
	 */
	public function getBackendName(): string {
		return 'user_globalsiteselector';
	}

	/**
	 * Check if backend implements actions
	 *
	 * @param int $actions bitwise-or'ed actions
	 *
	 * Returns the supported actions as int to be
	 * compared with \OC\User\Backend::CREATE_USER etc.
	 *
	 * @since 4.5.0
	 */
	public function implementsActions($actions): bool {
		$availableActions = Backend::CHECK_PASSWORD;
		$availableActions |= Backend::GET_DISPLAYNAME;
		$availableActions |= Backend::COUNT_USERS;

		return (bool)($availableActions & $actions);
	}

	/**
	 * Creates a user if it does not exist.
	 *
	 * @param string $uid
	 */
	public function createUserIfNotExists(string $uid): void {
		if (!$this->userExistsInDatabase($uid)) {
			$values = [
				'uid' => $uid,
			];

			$qb = $this->db->getQueryBuilder();
			$qb->insert($this->dbName);
			foreach ($values as $column => $value) {
				$qb->setValue($column, $qb->createNamedParameter($value));
			}
			$qb->executeStatement();

			### Code taken from lib/private/User/Session.php - function prepareUserLogin() ###
			//trigger creation of user home and /files folder
			$userFolder = $this->rootFolder->getUserFolder($uid);
			try {
				// copy skeleton
				\OC_Util::copySkeleton($uid, $userFolder);
			} catch (NotPermittedException $ex) {
				// read only uses
			}
			// trigger any other initialization
			$user = $this->userManager->get($uid);
			$this->eventDispatcher->dispatch(IUser::class . '::firstLogin', new GenericEvent($user));
			$this->eventDispatcher->dispatchTyped(new UserFirstTimeLoggedInEvent($user));
		}
	}

	/**
	 * delete a user
	 *
	 * @param string $uid The username of the user to delete
	 *
	 * @return bool
	 * @since 4.5.0
	 */
	public function deleteUser($uid): bool {
		if ($this->userExistsInDatabase($uid)) {
			/* @var $qb IQueryBuilder */
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->dbName)
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->executeStatement();

			return true;
		}

		return false;
	}

	/**
	 * Get a list of all users
	 *
	 * @param string $search
	 * @param null|int $limit
	 * @param null|int $offset
	 *
	 * @return string[] an array of all uids
	 * @since 4.5.0
	 */
	public function getUsers($search = '', $limit = null, $offset = null): array {
		/* @var $qb IQueryBuilder */
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'displayname')
			->from($this->dbName)
			->where(
				$qb->expr()->iLike(
					'uid', $qb->createNamedParameter(
						'%' . $this->db->escapeLikeParameter($search) . '%'
					)
				)
			)
			->setMaxResults($limit);
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		$result = $qb->executeQuery();
		$users = $result->fetchAll();
		$result->closeCursor();

		$uids = [];
		foreach ($users as $user) {
			$uids[] = $user['uid'];
		}

		return $uids;
	}


	/**
	 * counts the users in the database
	 *
	 * @return int|bool
	 */
	public function countUsers(): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('uid'))
			->from($this->dbName);
		$result = $query->executeQuery();

		return $result->fetchColumn();
	}

	/**
	 * check if a user exists
	 *
	 * @param string $uid the username
	 *
	 * @return boolean
	 * @since 4.5.0
	 */
	public function userExists($uid): bool {
		if ($backend = $this->getActualUserBackend($uid)) {
			return $backend->userExists($uid);
		} else {
			return $this->userExistsInDatabase($uid);
		}
	}

	public function setDisplayName(string $uid, string $displayName): bool {
		if ($backend = $this->getActualUserBackend($uid)) {
			return $backend->setDisplayName($uid, $displayName);
		}

		if ($this->userExistsInDatabase($uid)) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->dbName)
				->set('displayname', $qb->createNamedParameter($displayName))
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->executeStatement();

			return true;
		}

		return false;
	}

	/**
	 * Get display name of the user
	 *
	 * @param string $uid user ID of the user
	 *
	 * @return string display name
	 * @since 4.5.0
	 */
	public function getDisplayName($uid): string {
		if ($backend = $this->getActualUserBackend($uid)) {
			return $backend->getDisplayName($uid);
		} else {
			if ($this->userExistsInDatabase($uid)) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('displayname')
					->from($this->dbName)
					->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
					->setMaxResults(1);
				$result = $qb->executeQuery();
				$users = $result->fetchAll();
				if (isset($users[0]['displayname'])) {
					return $users[0]['displayname'];
				}
			}
		}

		return false;
	}

	/**
	 * Get a list of all display names and user ids.
	 *
	 * @param string $search
	 * @param string|null $limit
	 * @param string|null $offset
	 *
	 * @return array an array of all displayNames (value) and the corresponding uids (key)
	 * @since 4.5.0
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'displayname')
			->from($this->dbName)
			->where(
				$qb->expr()->iLike(
					'uid', $qb->createNamedParameter(
						'%' . $this->db->escapeLikeParameter($search) . '%'
					)
				)
			)
			->orWhere(
				$qb->expr()->iLike(
					'displayname', $qb->createNamedParameter(
						'%' . $this->db->escapeLikeParameter($search) . '%'
					)
				)
			)
			->setMaxResults($limit);
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		$result = $qb->executeQuery();
		$users = $result->fetchAll();
		$result->closeCursor();

		$uids = [];
		foreach ($users as $user) {
			$uids[$user['uid']] = $user['displayname'];
		}

		return $uids;
	}

	/**
	 * Check if a user list is available or not
	 *
	 * @return boolean if users can be listed or not
	 * @since 4.5.0
	 */
	public function hasUserListings(): bool {
		return true;
	}

	/**
	 * In case the user has been authenticated by Apache true is returned.
	 *
	 * @return boolean whether Apache reports a user as currently logged in.
	 * @since 6.0.0
	 */
	public function isSessionActive(): bool {
		return ($this->getCurrentUserId() !== '');
	}


	/**
	 * Return the id of the current user
	 *
	 * @return string
	 * @since 6.0.0
	 */
	public function getCurrentUserId(): string {
		$uid = $this->session->get('globalScale.uid');

		if (!empty($uid) && $this->userExists($uid)) {
			$this->session->set('last-password-confirm', time());

			return $uid;
		}

		return '';
	}


	/**
	 * Check if the provided token is correct
	 *
	 * @param string $uid The username
	 * @param string $password The password
	 *
	 * @return string
	 *
	 * There is no password, authentication happens on the global site selector master
	 */
	public function checkPassword(string $uid, string $password) {
		// if the user was successfully authenticated by the global site selector
		// master and forwarded to the client the uid is stored in the session.
		// In this case we can trust the global site selector that the password was
		// checked.
		$currentUid = $this->session->get('globalScale.uid');
		if ($currentUid === $uid) {
			return $uid;
		}

		return false;
	}

	/**
	 * Gets the actual user backend of the user
	 *
	 * @param string $uid
	 *
	 * @return null|UserInterface
	 */
	public function getActualUserBackend(string $uid): ?UserInterface {
		foreach (self::$backends as $backend) {
			if ($backend->userExists($uid)) {
				return $backend;
			}
		}

		return null;
	}

	/**
	 * Registers the used backends, used later to get the actual user backend
	 * of the user.
	 *
	 * @param UserInterface[] $backends
	 */
	public function registerBackends(array $backends): void {
		foreach ($backends as $backend) {
			if (!($backend instanceof UserBackend)) {
				self::$backends[] = $backend;
			}
		}
	}


	public function updateAttributes(string $uid, array $attributes): void {
		$user = $this->userManager->get($uid);

		$userData = $attributes['userData'];

		$newEmail = $userData['email'];
		$newDisplayName = $userData['displayName'];
		$newQuota = $userData['quota'];
		$newGroups = $userData['groups'];

		if ($user !== null) {
			$currentEmail = (string)$user->getEMailAddress();
			if ($newEmail !== null
				&& $currentEmail !== $newEmail) {
				$user->setEMailAddress($newEmail);
			}
			$currentDisplayName = (string)$this->getDisplayName($uid);
			if ($newDisplayName !== null
				&& $currentDisplayName !== $newDisplayName) {
				\OC_Hook::emit(
					'OC_User', 'changeUser',
					[
						'user' => $user,
						'feature' => 'displayName',
						'value' => $newDisplayName
					]
				);
				$this->setDisplayName($uid, $newDisplayName);
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
	}


	/**
	 * Whether $uid exists in the database
	 *
	 * @param string $uid
	 *
	 * @return bool
	 */
	protected function userExistsInDatabase(string $uid): bool {
		/* @var $qb IQueryBuilder */
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')
			->from($this->dbName)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$users = $result->fetchAll();
		$result->closeCursor();

		return !empty($users);
	}
}
