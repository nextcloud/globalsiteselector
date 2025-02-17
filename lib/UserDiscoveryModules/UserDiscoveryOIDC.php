<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\UserDiscoveryModules;

use OCP\IConfig;

/**
 * Class UserDiscoveryOIDC
 *
 * Discover initial user location with a dedicated OIDC attribute
 *
 * Therefore you have to define two values in the config.php file:
 *
 * 'gss.discovery.oidc.slave.mapping' => 'token-attribute'
 * 'gss.user.discovery.module' => '\OCA\GlobalSiteSelector\UserDiscoveryModules\UserDiscoveryOIDC'
 *
 * @package OCA\GlobalSiteSelector\UserDiscoveryModule
 */
class UserDiscoveryOIDC implements IUserDiscoveryModule {
	private string $tokenLocationAttribute;

	public function __construct(IConfig $config) {
		$this->tokenLocationAttribute = $config->getSystemValueString('gss.discovery.oidc.slave.mapping', '');
	}


	/**
	 * read user location from OIDC token attribute
	 *
	 * @param array $data OIDC attributes to read the location from
	 *
	 * @return string
	 */
	public function getLocation(array $data): string {
		$location = '';
		if (!empty($this->tokenLocationAttribute) && isset($data['oidc'][$this->tokenLocationAttribute])) {
			$location = $data['oidc'][$this->tokenLocationAttribute];
		}

		return $location;
	}
}
