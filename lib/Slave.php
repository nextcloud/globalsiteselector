<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
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


use OC\Accounts\AccountManager;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;

class Slave {

	/** @var AccountManager */
	private $accountManager;

	/** @var IUserManager */
	private $userManager;

	/** @var IClientService */
	private $clientService;

	/** @var ILogger */
	private $logger;

	/** @var ICloudIdManager */
	private $cloudIdManager;

	/** @var string */
	private $lookupServer;

	/** @var string */
	private $operationMode;

	/** @var string */
	private $authKey;

	/**
	 * remember users which should be removed
	 *
	 * @var array
	 */
	private static $toRemove = [];

	/**
	 * Slave constructor.
	 *
	 * @param AccountManager $accountManager
	 * @param IUserManager $userManager
	 * @param IClientService $clientService
	 * @param GlobalSiteSelector $gss
	 * @param ILogger $logger
	 * @param ICloudIdManager $cloudIdManager
	 */
	public function __construct(AccountManager $accountManager,
								IUserManager $userManager,
								IClientService $clientService,
								GlobalSiteSelector $gss,
								ILogger $logger,
								ICloudIdManager $cloudIdManager
	) {
		$this->accountManager = $accountManager;
		$this->userManager = $userManager;
		$this->clientService = $clientService;
		$this->logger = $logger;
		$this->cloudIdManager = $cloudIdManager;
		$this->lookupServer = $gss->getLookupServerUrl();
		$this->operationMode = $gss->getMode();
		$this->authKey = $gss->getJwtKey();
		$this->lookupServer = rtrim($this->lookupServer, '/');
		$this->lookupServer .= '/gs/users';
	}

	public function createUser(array $params) {
		if ($this->checkConfiguration() === false)  {
			return;
		}

		$uid = $params['uid'];
		$user = $this->userManager->get($uid);
		$userData = [];
		if ($user !== null) {
			$userData[$user->getCloudId()] = $this->getAccountData($user);
			$this->addUsers($userData);
		}
	}

	/**
	 * update existing user if personal data change
	 *
	 * @param IUser $user
	 */
	public function updateUser(IUser $user) {
		if ($this->checkConfiguration() === false)  {
			return;
		}

		$userData = [];
		if ($user !== null) {
			$userData[$user->getCloudId()] = $this->getAccountData($user);
			$this->addUsers($userData);
		}
	}

	/**
	 * the server indicated that the admin want to remove a user, remember the
	 * federated cloud id so that we can remove the user from the lookup server
	 * once they were deleted
	 *
	 * @param array $params
	 */
	public function preDeleteUser(array $params) {
		$uid = $params['uid'];
		$user = $this->userManager->get($uid);
		if ($user !== null) {
			self::$toRemove[$uid] = $user->getCloudId();
		}
	}

	/**
	 * remove user from lookup server
	 *
	 * @param array $params
	 */
	public function deleteUser(array $params) {
		if ($this->checkConfiguration() === false)  {
			return;
		}

		$uid = $params['uid'];
		if (isset(self::$toRemove[$uid])) {
			$this->removeUsers([self::$toRemove[$uid]]);
			unset(self::$toRemove[$uid]);
		}
	}

	/**
	 * update the lookup server with all known users on this instance. This
	 * is triggered by a cronjob
	 */
	public function batchUpdate() {
		if ($this->checkConfiguration() === false)  {
			return;
		}

		$backends = $this->userManager->getBackends();
		foreach ($backends as $backend) {
			$limit = 200;
			$offset = 0;
			$usersData = [];
			do {
				$users = $backend->getUsers('', $limit, $offset);
				foreach ($users as $uid) {
					$user = $this->userManager->get($uid);
					if ($user !== null) {
						$usersData[$user->getCloudId()] = $this->getAccountData($user);
					}
				}
				$offset += $limit;
				$this->addUsers($usersData);
			} while (count($users) >= $limit);
		}
	}

	/**
	 * get user data from account manager
	 *
	 * @param IUser $user
	 * @return array
	 */
	protected function getAccountData(IUser $user) {
		$rawData = $this->accountManager->getUser($user);
		$data = [];
		foreach ($rawData as $key => $value) {
			if ($key === 'displayname') {
				$data['name'] = $value['value'];
			} elseif (isset($value['value'])) {
				$data[$key] = $value['value'];
			}
		}

		$data['userid'] = $user->getUID();

		return $data;
	}

	/**
	 * send users to the lookup server
	 *
	 * @param array $users
	 */
	protected function addUsers(array $users) {
		$dataBatch = ['authKey' => $this->authKey, 'users' => $users];

		$httpClient = $this->clientService->newClient();
		try {
			$httpClient->post($this->lookupServer,
				[
					'body' => json_encode($dataBatch),
					'timeout' => 10,
					'connect_timeout' => 3,
				]
			);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['message' => 'Could not send user to lookup server', 'app' => 'globalsiteselector', 'level' => \OCP\Util::WARN]);
		}
	}

	/**
	 * remove users from the lookup server
	 *
	 * @param array $users
	 */
	protected function removeUsers(array $users) {
		$dataBatch = ['authKey' => $this->authKey, 'users' => $users];

		$httpClient = $this->clientService->newClient();
		try {
			$httpClient->delete($this->lookupServer,
				[
					'body' => json_encode($dataBatch),
					'timeout' => 10,
					'connect_timeout' => 3,
				]
			);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['message' => 'Could not remove user from the lookup server', 'app' => 'globalsiteselector', 'level' => \OCP\Util::WARN]);
		}
	}

	protected function checkConfiguration() {
		if (empty($this->lookupServer)
			|| empty($this->operationMode)
			|| empty($this->authKey)
		) {
			$this->logger->error('globle side selector app not configured correctly', ['app' => 'globalsiteselector']);
			return false;
		}

	}

	/**
	 * Operation mode - slave or master
	 * @return string
	 */
	public function getOperationMode() {
		return $this->operationMode;
	}
}
