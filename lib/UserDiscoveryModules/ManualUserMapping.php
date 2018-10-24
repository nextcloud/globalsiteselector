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
 * Class ManualUserMapping
 *
 * allows you to manage a local file which maps a arbitrary SAML parameter to
 * a initial location of the user.
 *
 * Therefore you have to define to values in the config.php
 *
 * 'gss.discovery.manual.mapping.file' => '/path/to/file'
 * 'gss.discovery.manual.mapping.parameter' => 'idp-parameter'
 *
 * @package OCA\GlobalSiteSelector\UserDiscoveryModules
 */
class ManualUserMapping implements IUserDiscoveryModule {

	/** @var string */
	private $idpParameter;
	/** @var string */
	private $file;

	/**
	 * ManualUserMapping constructor.
	 *
	 * @param IConfig $config
	 */
	public function __construct(IConfig $config) {
		$this->idpParameter = $config->getSystemValue('gss.discovery.manual.mapping.parameter', '');
		$this->file = $config->getSystemValue('gss.discovery.manual.mapping.file', '');
	}


	/**
	 * get the initial user location
	 *
	 * @param array $data arbitrary data, whatever the module needs
	 * @return string
	 */
	public function getLocation($data) {

		$location = '';
		$dictionary = $this->getDictionary();
		$key = $this->getKey($data);

		if (!empty($key) && is_array($dictionary)) {
			$location = isset($dictionary[$key]) ? $dictionary[$key] : '';
		}

		return $location;
	}

	/**
	 * get dictionary which maps idp parameters to nextcloud nodes
	 *
	 * @return array
	 */
	private function getDictionary() {
		$dictionary = [];
		$isValidFile = !empty($this->file) && file_exists($this->file);
		if ($isValidFile) {
			$mapString = file_get_contents($this->file);
			$dictionary = json_decode($mapString, true);
		}

		return is_array($dictionary) ? $dictionary : [];
	}

	/**
	 * get key from IDP parameter
	 *
	 * @param array $data idp parameters
	 * @return string
	 */
	private function getKey($data) {
		$key = '';
		if (!empty($this->idpParameter) && isset($data['saml'][$this->idpParameter][0])) {
			$key = $data['saml'][$this->idpParameter][0];
		}

		return $this->normalizeKey($key);
	}

	/**
	 * the keys are build like email addresses, we only need the "domain part"
	 *
	 * @param $key
	 * @return string
	 */
	private function normalizeKey($key) {
		$normalized = '';
		$pos = strrpos($key, '@');
		if ($pos !== false) {
			$normalized = substr($key, $pos+1);
		}
		return $normalized;
	}
}
