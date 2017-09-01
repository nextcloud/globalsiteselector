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


namespace OCA\GlobalSiteSelector\BackgroundJobs;


use OC\Accounts\AccountManager;
use OC\BackgroundJob\Job;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;

class UpdateLookupServer extends Job {

	/** @var IUserManager */
	private $userManager;

	/** @var AccountManager */
	private $accountManager;

	/** @var IClientService */
	private $clientService;

	/** @var ILogger */
	private $logger;

	/** @var string */
	private $lookupServer;

	/** @var string */
	private $operationMode;

	/** @var string */
	private $authKey;

	/**
	 * UpdateLookupServer constructor.
	 *
	 * @param IUserManager $userManager
	 * @param AccountManager $accountManager
	 * @param IClientService $clientService
	 * @param GlobalSiteSelector $gss
	 * @param ILogger $logger
	 */
	public function __construct(IUserManager $userManager,
								AccountManager $accountManager,
								IClientService $clientService,
								GlobalSiteSelector $gss,
								ILogger $logger) {
		$this->userManager = $userManager;
		$this->accountManager = $accountManager;
		$this->clientService = $clientService;
		$this->logger = $logger;
		$this->lookupServer = $gss->getLookupServerUrl();
		$this->operationMode = $gss->getMode();
		$this->authKey = $gss->getJwtKey();
		$this->lookupServer = rtrim($this->lookupServer, '/');
		$this->lookupServer .= '/gs/users';
	}

	protected function run($argument) {
		$backends = $this->userManager->getBackends();
		foreach ($backends as $backend) {
			$limit = 200;
			$offset = 0;
			$batch = ['authKey' => $this->authKey, 'users' => []];

			do {
				$users = $backend->getUsers('', $limit, $offset);
				foreach ($users as $uid) {
					$user = $this->userManager->get($uid);
					if ($user !== null) {
						$batch['users'][$user->getCloudId()] = $this->getAccountData($user);
					}
				}
				$offset += $limit;
				$this->sendBatch($batch);
			} while (count($users) >= $limit);
		}
	}

	/**
	 * run the job, then remove it from the jobList
	 *
	 * @param JobList $jobList
	 * @param ILogger|null $logger
	 */
	public function execute($jobList, ILogger $logger = null) {

		if ($this->shouldRun()) {
			parent::execute($jobList, $logger);
		}
	}

	/**
	 * re-add background job with updated arguments
	 *
	 * @param IJobList $jobList
	 */
	protected function reAddJob(IJobList $jobList) {
		$jobList->add(UpdateLookupServer::class, ['lastRun' => time()]);
	}

	/**
	 * check if it is time for the next update (update happens every 24 hours)
	 *
	 * @return bool
	 */
	protected function shouldRun() {
		$lastRun = $this->lastRun;
		$currentTime = time();

		if (empty($this->lookupServer)
			|| empty($this->operationMode)
			|| empty($this->authKey)
		) {
			$this->logger->error('globle side selector app not configured correctly', ['app' => 'globalsiteselector']);
			return false;
		}


		// update every 24 hours and only if the app runs in slave mode
		if ($this->operationMode !== 'slave' || $lastRun > $currentTime - 86400) {
			return false;
		}

		return true;
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
			} else {
				$data[$key] = $value['value'];
			}
		}
		unset($data['avatar']);
		return $data;
	}

	/**
	 * data send to the lookup server
	 *
	 * @param $dataBatch
	 */
	protected function sendBatch($dataBatch) {
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
			$this->logger->warning('Could not send user to lookup server, will retry later');
		}
	}
}
