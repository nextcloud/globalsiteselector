<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\UserDiscoveryModules;

use OCP\IConfig;

/**
 * Class UserDiscoverySAML
 *
 * discover initial user location with a dedicated SAML parameter
 *
 * Therefore you have to define to values in the config.php
 *
 * 'gss.discovery.saml.slave.mapping' => 'idp-parameter'
 *
 * @package OCA\GlobalSiteSelector\UserDiscoveryModule
 */
class UserDiscoverySAML implements IUserDiscoveryModule {
	private string $idpParameter;

	public function __construct(IConfig $config) {
		$this->idpParameter = $config->getSystemValueString('gss.discovery.saml.slave.mapping', '');
	}


	/**
	 * read user location from SAML parameters
	 *
	 * @param array $data SAML Parameters to read the location from
	 *
	 * @return string
	 */
	public function getLocation(array $data): string {
		$location = '';
		if (!empty($this->idpParameter) && isset($data['saml'][$this->idpParameter][0])) {
			$location = $data['saml'][$this->idpParameter][0];
		}

		return $location;
	}
}
