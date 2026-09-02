<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\GlobalSiteSelector\BackgroundJobs;

use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

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
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		$instances = $argument['instances'] ?? [];
		foreach ($instances as $instance => $shares) {
			$this->globalShareService->requestRemoteFileRefresh($instance, $shares);
		}
	}
}
