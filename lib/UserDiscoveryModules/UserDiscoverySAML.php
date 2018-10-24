<?php
/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
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

	/** @var string */
	private $idpParameter;

	/**
	 * UserDiscoverySAML constructor.
	 *
	 * @param IConfig $config
	 */
	public function __construct(IConfig $config) {
		$this->idpParameter = $config->getSystemValue('gss.discovery.saml.slave.mapping', '');
	}


	/**
	 * read user location from SAML parameters
	 *
	 * @param array $data SAML Parameters to read the location from
	 * @return string
	 */
	public function getLocation($data) {
		$location = '';
		if (!empty($this->idpParameter) && isset($data['saml'][$this->idpParameter][0])) {
			$location = $data['saml'][$this->idpParameter][0];
		}

		return $location;
	}

}
