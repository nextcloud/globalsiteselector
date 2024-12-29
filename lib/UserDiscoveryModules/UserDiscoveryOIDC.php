<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024 Julien Veyssier <julien-nc@posteo.net>
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
