<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\SetupChecks;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class LongJwtKeySetupCheck implements ISetupCheck {
	public function __construct(
		private readonly GlobalSiteSelector $gss,
	) {
	}

	public function getName(): string {
		return 'Globalscale Jwt key';
	}

	public function getCategory(): string {
		return 'globalscale';
	}

	public function run(): SetupResult {
		if (strlen($this->gss->getJwtKey()) > 31) {
			return SetupResult::success('gss.jwt.key is set to a long enough string');
		}

		return SetupResult::error(
			'Current value for \'gss.jwt.key\' in `config.php` is too short, which will be a blocker in Nextcloud 34. '
			. 'Please set a new key longer than 31 chars. '
			. 'Warning: This key being shared between the Lookup Server and all instances of the Globalscale - portal and nodes - it needs to be modified at all location simultaneously');
	}
}
