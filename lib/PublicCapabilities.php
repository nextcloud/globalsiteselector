<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

use OCP\Capabilities\IPublicCapability;

class PublicCapabilities implements IPublicCapability {
	public function getCapabilities(): array {
		return [
			'globalscale' => [
				'enabled' => true,
				'desktoplogin' => 1,
			]
		];
	}
}
