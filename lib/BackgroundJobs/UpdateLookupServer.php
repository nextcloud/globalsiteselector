<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\BackgroundJobs;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Slave;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class UpdateLookupServer extends TimedJob {


	public function __construct(
		ITimeFactory $time,
		IConfig $config,
		private GlobalSiteSelector $globalSiteSelector,
		private Slave $slave,
	) {
		parent::__construct($time);

		$this->setInterval($config->getSystemValueInt('gss.updatels.interval', 86400));
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument) {
		if (!$this->globalSiteSelector->isSlave()) {
			return;
		}

		$this->slave->batchUpdate();
	}
}
