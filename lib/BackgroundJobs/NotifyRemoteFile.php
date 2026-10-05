<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\GlobalSiteSelector\BackgroundJobs;

use OCA\Circles\CirclesManager;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Teams\ITeamManager;

/**
 * This is called to notify a remote instance that a local shared
 * document has been modified. This is a background job that might
 * be initiated if a file is shared to too many different instances.
 * Limit is set via {@see ConfigLexicon::INSTANCE_MAIN_THREAD}
 */
class NotifyRemoteFile extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private readonly GlobalShareService $globalShareService,
		private readonly ITeamManager $teamManager,
		private readonly CirclesManager $circlesManager,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		foreach (($argument['instances'] ?? []) as $instance => $shares) {
			$this->globalShareService->requestRemoteFileRefresh($instance, $shares);
		}

		if (!$this->teamManager->hasTeamSupport()) {
			return;
		}

		$this->circlesManager->startSuperSession();
		$instances = [];

		/**
		 * For each team a share has been created with, we get a list of remote instances.
		 * We group shares per instance before requesting a remote file refresh.
		 */
		foreach (($argument['teams'] ?? []) as $teamShare) {
			$team = $this->circlesManager->getCircle($teamShare['shareWith']);
			$teamInstances = [];

			foreach ($team->getMembers() as $member) {
				if (!$member->isLocal() && !in_array($member->getInstance(), $teamInstances)) {
					$teamInstances[] = $member->getInstance();
					if (!array_key_exists($member->getInstance(), $instances)) {
						$instances[$member->getInstance()] = [];
					}

					$instances[$member->getInstance()][] = $teamShare;
				}
			}
		}
		$this->circlesManager->stopSession();

		foreach ($instances as $instance => $teamShares) {
			$this->globalShareService->requestRemoteFileRefresh($instance, $teamShares);
		}

	}
}
