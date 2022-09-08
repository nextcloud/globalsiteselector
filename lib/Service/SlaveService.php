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
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class SlaveService {
	private LoggerInterface $logger;
	private IClientService $clientService;
	private IUserManager $userManager;
	private IAccountManager $accountManager;
	private IConfig $config;
	private Lookup $lookup;
	private string $lookupServer;
	private string $operationMode;
	private string $authKey;

	public function __construct(
		LoggerInterface $logger,
		IClientService $clientService,
		IUserManager $userManager,
		IAccountManager $accountManager,
		IConfig $config,
		Lookup $lookup,
		GlobalSiteSelector $gss
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


	protected function updateUsersOnLookup(array $users): void {
		if (!$this->checkConfiguration()) {
			return;
		}

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
