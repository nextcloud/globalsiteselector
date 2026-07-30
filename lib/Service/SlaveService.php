<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Service;

use Exception;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Exceptions\ConfigurationException;
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
	private readonly string $lookupServer;
	private readonly string $operationMode;
	private readonly string $authKey;
	private readonly ICache $cacheDisplayName;
	private readonly int $cacheDisplayNameTtl;

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IClientService $clientService,
		private readonly IUserManager $userManager,
		private readonly IAccountManager $accountManager,
		private readonly IConfig $config,
		private readonly Lookup $lookup,
		GlobalSiteSelector $gss,
		ICacheFactory $cacheFactory,
	) {
		$this->lookupServer = rtrim($gss->getLookupServerUrl(), '/');
		$this->operationMode = $gss->getMode();
		$this->authKey = $gss->getJwtKey();

		$this->cacheDisplayName = $cacheFactory->createDistributed(self::CACHE_DISPLAY_NAME);
		$ttl = (int)$this->config->getAppValue('globalsiteselector', 'cache_displayname');
		$this->cacheDisplayNameTtl = ($ttl === 0) ? self::CACHE_DISPLAY_NAME_TTL : $ttl;
	}

	public function updateUserById(string $userId): void {
		$user = $this->userManager->get($userId);
		if (is_null($user)) {
			return;
		}

		$this->updateUser($user);
	}

	public function updateUser(IUser $user): void {
		try {
			$this->checkConfiguration();
		} catch (ConfigurationException) {
			return;
		}

		$userData = [];
		$userData[$user->getCloudId()] = $this->getAccountData($user);
		$this->updateUsersOnLookup($userData);
	}

	/**
	 * get single user's display name
	 *
	 * @param bool $cacheOnly - only get data from cache, do not request lus
	 *
	 */
	public function getUserDisplayName(string $userId, bool $cacheOnly = false): string {
		$userId = trim($userId, '/');
		$details = $this->getUsersDisplayName([$userId], $cacheOnly);

		return $details[$userId] ?? '';
	}

	/**
	 * get multiple users' display name
	 *
	 * @param bool $cacheOnly - only get data from cache, do not request lus
	 *
	 */
	public function getUsersDisplayName(array $userIds, bool $cacheOnly = false): array {
		return $this->getDetails(
			array_map(fn (string $userId): string => trim($userId, '/'), $userIds), $cacheOnly
		);
	}

	/**
	 * get details for a list of userIds from the LUS.
	 * Will first get data from cache, and will cache data returned by lus
	 *
	 * @param bool $cacheOnly - only get data from cache, do not request lus
	 *
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
			try {
				$details = json_decode(
					$this->getLookup('/gs/users', ['users' => $users]),
					true,
					512, JSON_THROW_ON_ERROR
				);
			} catch (Exception) {
				// if configuration issue or request is not complete, we return known details.
				return $knownDetails;
			}
		}

		// cache displayName on returned result
		foreach ($details as $userId => $displayName) {
			$this->cacheDisplayName->set($userId, $displayName, $this->cacheDisplayNameTtl);
		}

		return array_merge($knownDetails, $details);
	}

	protected function updateUsersOnLookup(array $users): void {
		$this->logger->debug(
			'Batch updating users: {users}',
			['users' => $users]
		);

		$this->postLookup('/gs/users', ['users' => $users]);
	}

	protected function postLookup(string $path, array $data): void {
		try {
			$this->checkConfiguration();
		} catch (ConfigurationException $e) {
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
			$this->logger->warning(
				'Could not send user to lookup server',
				['exception' => $e]
			);
		}
	}

	/**
	 *
	 * @throws ConfigurationException
	 */
	protected function getLookup(string $path, array $data): string {
		$this->checkConfiguration();

		$dataBatch = array_merge(['authKey' => $this->authKey], $data);

		$httpClient = $this->clientService->newClient();
		try {
			$response = $httpClient->get(
				$this->lookupServer . $path,
				$this->lookup->configureClient(['body' => json_encode($dataBatch)])
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'Could not get data from lookup server',
				['exception' => $e]
			);

			return '';
		}

		return (string)$response->getBody();
	}

	/**
	 * @throws ConfigurationException
	 */
	protected function checkConfiguration(): void {
		if (empty($this->lookupServer)
			|| empty($this->operationMode)
			|| empty($this->authKey)
		) {
			$this->logger->error('app not configured correctly');
			throw new ConfigurationException('globalsiteselector app not configured correctly');
		}

		if ($this->operationMode !== 'slave') {
			throw new ConfigurationException('not configured as slave');
		}
	}

	/**
	 * get user data from account manager
	 *
	 *
	 */
	public function getAccountData(IUser $user): array {
		$data = [ // we get basic values from IUser
			'userid' => $user->getUID(),
			'name' => $user->getDisplayName()
		];

		// we ignore properties (like mail address) if instance is set as not priority
		if ((string)$this->config->getAppValue(Application::APP_ID, 'ignore_properties', '0') === '1') {
			return $data;
		}

		$properties = $this->accountManager->getAccount($user)->getProperties();
		foreach ($properties as $property) {
			// display name can be wrong in account properties ...
			if ($property->getName() !== IAccountManager::PROPERTY_DISPLAYNAME) {
				$data[$property->getName()] = $property->getValue();
			}
		}

		$data['userid'] = $user->getUID();

		return $data;
	}
}
