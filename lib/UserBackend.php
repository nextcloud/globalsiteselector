<?php
/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\GlobalSiteSelector;

use OC\User\Backend;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\User\Backend\ICountUsersBackend;
use OCP\UserInterface;
use Symfony\Component\EventDispatcher\GenericEvent;


class UserBackend implements IUserBackend, UserInterface, ICountUsersBackend {

	/** @var string  name of the database table to store the users logged in from the master node */
	private $dbName = 'global_scale_users';

	/** @var IDBConnection */
	private $db;

	/** @var ISession */
	private $session;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IUserManager */
	private $userManager;

	/** @var \OCP\UserInterface[] */
	private static $backends = [];

	/**
	 * UserBackend constructor.
	 *
	 * @param IDBConnection $db
	 * @param ISession $session
	 * @param IGroupManager $groupManager
	 * @param IUserManager $userManager
	 */
	public function __construct(IDBConnection $db,
								ISession $session,
								IGroupManager $groupManager,
								IUserManager $userManager
	) {
		$this->db = $db;
		$this->session = $session;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
	}


	/**
	 * Backend name to be shown in user management
	 *
	 * @return string the name of the backend to be shown
	 * @since 0.11.0
	 */
	public function getBackendName() {
		return 'user_globalsiteselector';
	}

	/**
	 * Check if backend implements actions
	 * @param int $actions bitwise-or'ed actions
	 * @return boolean
	 *
	 * Returns the supported actions as int to be
	 * compared with \OC\User\Backend::CREATE_USER etc.
	 * @since 4.5.0
	 */
	public function implementsActions($actions) {
		$availableActions = Backend::CHECK_PASSWORD;
		$availableActions |= Backend::GET_DISPLAYNAME;
		$availableActions |= Backend::COUNT_USERS;
		return (bool)($availableActions & $actions);
	}

	/**
	 * Creates an user if it does not exists
	 *
	 * @param string $uid
	 */
	public function createUserIfNotExists($uid) {
		if(!$this->userExistsInDatabase($uid)) {
			$values = [
				'uid' => $uid,
			];

			/* @var $qb IQueryBuilder */
			$qb = $this->db->getQueryBuilder();
			$qb->insert($this->dbName);
			foreach($values as $column => $value) {
				$qb->setValue($column, $qb->createNamedParameter($value));
			}
			$qb->execute();

			### Code taken from lib/private/User/Session.php - function prepareUserLogin() ###
			//trigger creation of user home and /files folder
			$userFolder = \OC::$server->getUserFolder($uid);
			try {
				// copy skeleton
				\OC_Util::copySkeleton($uid, $userFolder);
			} catch (NotPermittedException $ex) {
				// read only uses
			}
			// trigger any other initialization
			$user = $this->userManager->get($uid);
			\OC::$server->getEventDispatcher()->dispatch(IUser::class . '::firstLogin', new GenericEvent($user));
		}
	}

	/**
	 * delete a user
	 * @param string $uid The username of the user to delete
	 * @return bool
	 * @since 4.5.0
	 */
	public function deleteUser($uid) {
		if($this->userExistsInDatabase($uid)) {
			/* @var $qb IQueryBuilder */
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->dbName)
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->execute();
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
	 * @return string[] an array of all uids
	 * @since 4.5.0
	 */
	public function getUsers($search = '', $limit = null, $offset = null) {
		/* @var $qb IQueryBuilder */
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'displayname')
			->from($this->dbName)
			->where(
				$qb->expr()->iLike('uid', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'))
			)
			->setMaxResults($limit);
		if($offset !== null) {
			$qb->setFirstResult($offset);
		}
		$result = $qb->execute();
		$users = $result->fetchAll();
		$result->closeCursor();

		$uids = [];
		foreach($users as $user) {
			$uids[] = $user['uid'];
		}

		return $uids;
	}


	/**
	 * counts the users in the database
	 *
	 * @return int|bool
	 */
	public function countUsers() {

		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('uid'))
			->from($this->dbName);
		$result = $query->execute();

		return $result->fetchColumn();
	}

	/**
	 * check if a user exists
	 * @param string $uid the username
	 * @return boolean
	 * @since 4.5.0
	 */
	public function userExists($uid) {
		if($backend = $this->getActualUserBackend($uid)) {
			return $backend->userExists($uid);
		} else {
			return $this->userExistsInDatabase($uid);
		}
	}

	public function setDisplayName($uid, $displayName) {
		if($backend = $this->getActualUserBackend($uid)) {
			return $backend->setDisplayName($uid, $displayName);
		}

		if ($this->userExistsInDatabase($uid)) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->dbName)
				->set('displayname', $qb->createNamedParameter($displayName))
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->execute();
			return true;
		}

		return false;
	}

	/**
	 * Get display name of the user
	 *
	 * @param string $uid user ID of the user
	 * @return string display name
	 * @since 4.5.0
	 */
	public function getDisplayName($uid) {
		if($backend = $this->getActualUserBackend($uid)) {
			return $backend->getDisplayName($uid);
		} else {
			if($this->userExistsInDatabase($uid)) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('displayname')
					->from($this->dbName)
					->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
					->setMaxResults(1);
				$result = $qb->execute();
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
	 * @return array an array of all displayNames (value) and the corresponding uids (key)
	 * @since 4.5.0
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'displayname')
			->from($this->dbName)
			->where(
				$qb->expr()->iLike('uid', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'))
			)
			->orWhere(
				$qb->expr()->iLike('displayname', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'))
			)
			->setMaxResults($limit);
		if($offset !== null) {
			$qb->setFirstResult($offset);
		}
		$result = $qb->execute();
		$users = $result->fetchAll();
		$result->closeCursor();

		$uids = [];
		foreach($users as $user) {
			$uids[$user['uid']] = $user['displayname'];
		}

		return $uids;
	}

	/**
	 * Check if a user list is available or not
	 * @return boolean if users can be listed or not
	 * @since 4.5.0
	 */
	public function hasUserListings() {
		return true;
	}

	/**
	 * In case the user has been authenticated by Apache true is returned.
	 *
	 * @return boolean whether Apache reports a user as currently logged in.
	 * @since 6.0.0
	 */
	public function isSessionActive() {
		if($this->getCurrentUserId() !== '') {
			return true;
		}
		return false;
	}


	/**
	 * Return the id of the current user
	 * @return string
	 * @since 6.0.0
	 */
	public function getCurrentUserId() {
		$uid = $this->session->get('globalScale.uid');

		if(!empty($uid) && $this->userExists($uid)) {
			$this->session->set('last-password-confirm', time());
			return $uid;
		}

		return '';
	}


	/**
	 * Check if the provided token is correct
	 * @param string $uid The username
	 * @param string $password The password
	 * @return string
	 *
	 * There is no password, authentication happens on the global site selector master
	 */
	public function checkPassword($uid, $password) {
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
	 * @return null|UserInterface
	 */
	public function getActualUserBackend($uid) {
		foreach(self::$backends as $backend) {
			if($backend->userExists($uid)) {
				return $backend;
			}
		}

		return null;
	}

	/**
	 * Registers the used backends, used later to get the actual user backend
	 * of the user.
	 *
	 * @param \OCP\UserInterface[] $backends
	 */
	public function registerBackends(array $backends) {
		foreach ($backends as $backend) {
			if (!($backend instanceof UserBackend)) {
				self::$backends[] = $backend;
			}
		}
	}


	public function updateAttributes($uid, array $attributes) {

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
				\OC_Hook::emit('OC_User', 'changeUser',
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
	 * @return bool
	 */
	protected function userExistsInDatabase($uid) {
		/* @var $qb IQueryBuilder */
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')
			->from($this->dbName)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->setMaxResults(1);
		$result = $qb->execute();
		$users = $result->fetchAll();
		$result->closeCursor();

		return !empty($users);
	}


}
