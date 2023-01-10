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


namespace OCA\GlobalSiteSelector\Service;

use Exception;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCP\Accounts\IAccountManager;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class SlaveService {
	private const CACHE_DISPLAY_NAME = 'gss/displayName';
	private const CACHE_DISPLAY_NAME_TTL = 3600;

	private LoggerInterface $logger;
	private IClientService $clientService;
	private IUserManager $userManager;
	private IAccountManager $accountManager;
	private IConfig $config;
	private Lookup $lookup;
	private string $lookupServer;
	private string $operationMode;
	private string $authKey;
	private ICache $cacheDisplayName;
	private int $cacheDisplayNameTtl;

	public function __construct(
		LoggerInterface $logger,
		IClientService $clientService,
		IUserManager $userManager,
		IAccountManager $accountManager,
		IConfig $config,
		Lookup $lookup,
		GlobalSiteSelector $gss,
		ICacheFactory $cacheFactory
	) {
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->userManager = $userManager;
		$this->accountManager = $accountManager;
		$this->config = $config;
		$this->lookup = $lookup;

		$this->lookupServer = rtrim($gss->getLookupServerUrl(), '/');
		$this->operationMode = $gss->getMode();
		$this->authKey = $gss->getJwtKey();

		$this->cacheDisplayName = $cacheFactory->createDistributed(self::CACHE_DISPLAY_NAME);
		$ttl = (int) $this->config->getAppValue('globalsiteselector', 'cache_displayname');
		$this->cacheDisplayNameTtl = ($ttl === 0) ? self::CACHE_DISPLAY_NAME_TTL : $ttl;
	}


	public function updateUserById(string $userId): void {
		$user = $this->userManager->get($userId);
		if (is_null($user)) {
			return;
		}

		$this->updateUser($user);
	}

	/**
	 * @param IUser $user
	 */
	public function updateUser(IUser $user): void {
		if ($this->checkConfiguration() === false) {
			return;
		}

		$userData = [];
		$userData[$user->getCloudId()] = $this->getAccountData($user);
		$this->updateUsersOnLookup($userData);
	}


	/**
	 * get single user's display name
	 *
	 * @param string $userId
	 * @param bool $cacheOnly - only get data from cache, do not request lus
	 *
	 * @return string
	 */
	public function getUserDisplayName(string $userId, bool $cacheOnly = false): string {
		$userId = trim($userId, '/');
		$details = $this->getUsersDisplayName([$userId], $cacheOnly);
		if (array_key_exists($userId, $details)) {
			return $details[$userId];
		}

		return '';
	}

	/**
	 * get multiple users' display name
	 *
	 * @param array $userIds
	 * @param bool $cacheOnly - only get data from cache, do not request lus
	 *
	 * @return array
	 */
	public function getUsersDisplayName(array $userIds, bool $cacheOnly = false): array {
		return $this->getDetails(
			array_map(function (string $userId): string {
				return trim($userId, '/');
			}, $userIds), $cacheOnly
		);
	}

	/**
	 * get details for a list of userIds from the LUS.
	 * Will first get data from cache, and will cache data returned by lus
	 *
	 * @param array $users
	 * @param bool $cacheOnly - only get data from cache, do not request lus
	 *
	 * @return array
	 */
	protected function getDetails(array $users, bool $cacheOnly = false): array {
		$knownDetails = [];
		foreach ($users as $userId) {
			$knownName = $this->cacheDisplayName->get($userId);
			if ($knownName !== null) {
				$knownDetails[$userId] = $knownName;
			}
		}

		if ($cacheOnly) {
			return $knownDetails;
		}

		$details = [];
		$users = array_diff($users, array_keys($knownDetails));
		if (!empty($users)) {
			$details = json_decode($this->getLookup('/gs/users', ['users' => $users]), true);
		}

		if (!is_array($details)) {
			$details = [];
		}

		// cache displayName
		foreach ($details as $userId => $displayName) {
			$this->cacheDisplayName->set($userId, $displayName, $this->cacheDisplayNameTtl);
		}

		return array_merge($knownDetails, $details);
	}



	protected function updateUsersOnLookup(array $users): void {
		$this->logger->debug('Batch updating users: {users}',
			['users' => $users]
		);

		$this->postLookup('/gs/users', ['users' => $users]);
	}



	protected function postLookup(string $path, array $data): void {
		if (!$this->checkConfiguration()) {
			return;
		}

		$dataBatch = array_merge(['authKey' => $this->authKey], $data);

		$httpClient = $this->clientService->newClient();
		try {
			$httpClient->post(
				$this->lookupServer . $path,
				$this->lookup->configureClient(['body' => json_encode($dataBatch)])
			);
		} catch (Exception $e) {
			$this->logger->warning('Could not send user to lookup server',
				['exception' => $e]
			);
		}
	}



	protected function getLookup(string $path, array $data): string {
		if (!$this->checkConfiguration()) {
			return '';
		}

		$dataBatch = array_merge(['authKey' => $this->authKey], $data);

		$httpClient = $this->clientService->newClient();
		try {
			$response = $httpClient->get(
				$this->lookupServer . $path,
				$this->lookup->configureClient(['body' => json_encode($dataBatch)])
			);
		} catch (Exception $e) {
			$this->logger->warning('Could not get data from lookup server',
				['exception' => $e]
			);
		}

		return $response->getBody();
	}


	protected function checkConfiguration(): bool {
		if (empty($this->lookupServer)
			|| empty($this->operationMode)
			|| empty($this->authKey)
		) {
			$this->logger->error('global site selector app not configured correctly');

			return false;
		}

		if ($this->operationMode !== 'slave') {
			return false;
		}

		return true;
	}


	protected function getAccountData(IUser $user): array {
		$properties = $data = [];

		if ((string)$this->config->getAppValue(
			Application::APP_ID,
			'ignore_properties', '0'
		) !== '1') {
			$properties = $this->accountManager->getAccount($user)->getProperties();
		}

		foreach ($properties as $property) {
			if ($property->getName() === IAccountManager::PROPERTY_DISPLAYNAME) {
				$data['name'] = $property->getValue();
			} elseif ($property->getValue() !== '') {
				$data[$property->getName()] = $property->getValue();
			}
		}

		$data['userid'] = $user->getUID();

		return $data;
	}
}
