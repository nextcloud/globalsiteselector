<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\GlobalSiteSelector;

use Exception;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class Slave {
	public const SAML_IDP = 'saml_idp';
	public const OIDC_PROVIDER_ID = 'oidc_provider_id';

	private IUserManager $userManager;
	private IClientService $clientService;
	private SlaveService $slaveService;
	private Lookup $lookup;
	private LoggerInterface $logger;
	private string $lookupServer;
	private string $operationMode;
	private string $authKey;
	private GlobalSiteSelector $gss;
	private IConfig $config;
	private static array $toRemove = []; // remember users which should be removed


	public function __construct(
		IUserManager $userManager,
		IClientService $clientService,
		SlaveService $slaveService,
		Lookup $lookup,
		GlobalSiteSelector $gss,
		LoggerInterface $logger,
		IConfig $config,
	) {
		$this->userManager = $userManager;
		$this->clientService = $clientService;
		$this->slaveService = $slaveService;
		$this->lookup = $lookup;
		$this->logger = $logger;
		$this->lookupServer = $gss->getLookupServerUrl();
		$this->operationMode = $gss->getMode();
		$this->authKey = $gss->getJwtKey();
		$this->lookupServer = rtrim($this->lookupServer, '/');
		$this->lookupServer .= '/gs/users';
		$this->gss = $gss;
		$this->config = $config;
	}

	public function createUser(array $params): void {
		if (!$this->checkConfiguration()) {
			return;
		}

		$uid = $params['uid'];

		$this->logger->debug(
			'Adding new user: {uid}',
			[
				'app' => Application::APP_ID,
				'uid' => $uid,
			]
		);

		$user = $this->userManager->get($uid);
		$userData = [];
		if ($user !== null) {
			$userData[$user->getCloudId()] = $this->slaveService->getAccountData($user);
			$this->addUsers($userData);
		}
	}

	/**
	 * update existing user if personal data change
	 *
	 * @param IUser $user
	 */
	public function updateUser(IUser $user): void {
		if (!$this->checkConfiguration()) {
			return;
		}

		$this->logger->debug(
			'Updating user: {uid}',
			[
				'app' => Application::APP_ID,
				'uid' => $user->getUID(),
			]
		);

		$userData = [];
		$userData[$user->getCloudId()] = $this->slaveService->getAccountData($user);
		$this->addUsers($userData);
	}

	/**
	 * the server indicated that the admin want to remove a user, remember the
	 * federated cloud id so that we can remove the user from the lookup server
	 * once they were deleted
	 *
	 * @param array $params
	 */
	public function preDeleteUser(array $params): void {
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
	public function deleteUser(array $params): void {
		if (!$this->checkConfiguration()) {
			return;
		}

		$uid = $params['uid'];

		$this->logger->debug(
			'Removing user: {uid}',
			[
				'app' => Application::APP_ID,
				'uid' => $uid,
			]
		);

		if (isset(self::$toRemove[$uid])) {
			$this->removeUsers([self::$toRemove[$uid]]);
			unset(self::$toRemove[$uid]);
		}
	}

	/**
	 * update the lookup server with all known users on this instance. This
	 * is triggered by a cronjob
	 */
	public function batchUpdate(): void {
		if (!$this->checkConfiguration()) {
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
						$usersData[$user->getCloudId()] = $this->slaveService->getAccountData($user);
					}
				}
				$offset += $limit;
				$this->addUsers($usersData);
			} while (count($users) >= $limit);
		}
	}

	/**
	 * send users to the lookup server
	 *
	 * @param array $users
	 */
	protected function addUsers(array $users): void {
		$dataBatch = ['authKey' => $this->authKey, 'users' => $users];

		$this->logger->debug(
			'Batch updating users: {users}',
			[
				'app' => Application::APP_ID,
				'users' => $users,
			]
		);

		$httpClient = $this->clientService->newClient();
		try {
			$httpClient->post(
				$this->lookupServer,
				$this->lookup->configureClient(['body' => json_encode($dataBatch)])
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'Could not send user to lookup server',
				[
					'app' => Application::APP_ID,
					'exception' => $e,
				]
			);
		}
	}

	/**
	 * remove users from the lookup server
	 *
	 * @param array $users
	 */
	protected function removeUsers(array $users): void {
		$dataBatch = ['authKey' => $this->authKey, 'users' => $users];

		$this->logger->debug(
			'Batch deleting users: {users}',
			[
				'app' => Application::APP_ID,
				'users' => $users,
			]
		);

		$httpClient = $this->clientService->newClient();
		try {
			$httpClient->delete(
				$this->lookupServer,
				$this->lookup->configureClient(['body' => json_encode($dataBatch)])
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'Could not remove user from the lookup server',
				[
					'app' => Application::APP_ID,
					'exception' => $e,
				]
			);
		}
	}

	protected function checkConfiguration(): bool {
		if (!$this->gss->isSlave()) {
			return false;
		}

		if (empty($this->lookupServer)
			|| empty($this->operationMode)
			|| empty($this->authKey)
		) {
			$this->logger->error(
				'global site selector app not configured correctly',
				[
					'app' => Application::APP_ID,
				]
			);

			return false;
		}

		return true;
	}

	/**
	 * Operation mode - slave or master
	 *
	 * @return string
	 */
	public function getOperationMode(): string {
		return $this->operationMode;
	}

	/**
	 * send user back to master
	 */
	public function handleLogoutRequest(IUser $user) {
		$token = [
			'logout' => 'true',
			'saml.idp' => $this->config->getUserValue(
				$user->getUID(),
				Application::APP_ID,
				self::SAML_IDP,
				null),
			'oidc.providerId' => $this->config->getUserValue(
				$user->getUID(),
				Application::APP_ID,
				self::OIDC_PROVIDER_ID,
				null),
			'exp' => time() + 300 // expires after 5 minute
		];

		if ($user->getBackend() instanceof \OC\User\Database
			&& (int)$this->config->getAppValue(
				Application::APP_ID,
				'local_account_stays_on_slave',
				0
			) === 1) {
			return;
		}

		$jwt = JWT::encode($token, $this->gss->getJwtKey(), Application::JWT_ALGORITHM);
		$location = $this->config->getSystemValueString('gss.master.url', '');

		if ($location === '') {
			$this->logger->error(
				'Can not redirect to master for logout, "gss.master.url" not set in config.php',
				[
					'app' => Application::APP_ID,
				]
			);

			return;
		}

		$redirectUrl = $location . '/index.php/apps/globalsiteselector/autologout?jwt=' . $jwt;

		header('Location: ' . $redirectUrl);
		die();
	}
}
