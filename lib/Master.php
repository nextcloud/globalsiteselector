<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
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


namespace OCA\GlobalSiteSelector;

use Firebase\JWT\JWT;

/**
 * Class Master
 *
 * Handle all operations in master mode to redirect the users to their login server
 *
 * @package OCA\GlobalSiteSelector
 */
class Master {

	/** @var GlobalSiteSelector */
	private $gss;

	/**
	 * Master constructor.
	 *
	 * @param GlobalSiteSelector $gss
	 */
	public function __construct(GlobalSiteSelector $gss) {
		$this->gss = $gss;
	}


	/**
	 * find users location and redirect them to the right server
	 *
	 * @param array $param
	 */
	public function handleLoginRequest($param) {
		$uid = $param['uid'];
		$password = $param['password'];

		$location = $this->queryLookupServer($uid);
		if (!empty($location)) {
			$this->redirectUser($uid, $password, $location);
		}
	}

	/**
	 * search for the user and return the location of the user
	 *
	 * @param $uid
	 * @return string
	 */
	private function queryLookupServer($uid) {
		// FIXME... Just for testing for now
		return rtrim('http://localhost/master', '/');
	}

	/**
	 * redirect user to the right Nextcloud server
	 *
	 * @param string $uid
	 * @param string $password
	 * @param string $location
	 */
	private function redirectUser($uid, $password, $location) {

		$token = [
			'uid' => $uid,
			'password' => $password,
			'exp' => time() + 300, // expires after 5 minutes
		];

		$jwt = JWT::encode($token, $this->gss->getJwtKey());

		$redirectUrl = $location . '/index.php/apps/globalsiteselector/autologin?jwt=' . $jwt;
		header('Location: ' . $redirectUrl);
		die();
	}

}
