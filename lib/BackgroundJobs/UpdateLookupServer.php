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


use OC\BackgroundJob\Job;
use OCA\GlobalSiteSelector\Slave;
use OCP\BackgroundJob\IJobList;
use OCP\ILogger;

class UpdateLookupServer extends Job {

	/** @var Slave */
	private $slave;


	/**
	 * UpdateLookupServer constructor.
	 *
	 * @param Slave $slave
	 */
	public function __construct(Slave $slave) {
		$this->slave = $slave;
	}

	protected function run($argument) {
		$this->slave->batchUpdate();
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
		$lastRun = (int)$this->lastRun;
		$currentTime = time();

		// update every 24 hours and only if the app runs in slave mode
		if ($this->slave->getOperationMode() !== 'slave' || $lastRun > $currentTime - 86400) {
			return false;
		}

		return true;
	}
}
