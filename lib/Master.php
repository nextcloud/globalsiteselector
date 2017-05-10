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
use OCP\IRequest;
use OCP\Security\ICrypto;

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

	/** @var ICrypto */
	private $crypto;

	/** @var Lookup */
	private $lookup;

	/** @var IRequest */
	private $request;

	/**
	 * Master constructor.
	 *
	 * @param GlobalSiteSelector $gss
	 * @param ICrypto $crypto
	 * @param Lookup $lookup
	 * @param IRequest $request
	 */
	public function __construct(GlobalSiteSelector $gss,
								ICrypto $crypto,
								Lookup $lookup,
								IRequest $request
	) {
		$this->gss = $gss;
		$this->crypto = $crypto;
		$this->lookup = $lookup;
		$this->request = $request;
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
	protected function queryLookupServer($uid) {
		return $this->lookup->search($uid);
	}

	/**
	 * redirect user to the right Nextcloud server
	 *
	 * @param string $uid
	 * @param string $password
	 * @param string $location
	 */
	protected function redirectUser($uid, $password, $location) {

		$isClient = $this->request->isUserAgent(
			[
				IRequest::USER_AGENT_CLIENT_IOS,
				IRequest::USER_AGENT_CLIENT_ANDROID,
				IRequest::USER_AGENT_CLIENT_DESKTOP
			]
		);

		if($isClient) {
			// TODO login nextcloud clients
		}

		$jwt = $this->createJwt($uid, $password);
		$redirectUrl = $location . '/index.php/apps/globalsiteselector/autologin?jwt=' . $jwt;

		header('Location: ' . $redirectUrl);
		die();
	}

	/**
	 * generate JWT
	 *
	 * @param string $uid
	 * @param string $password
	 * @return string
	 */
	protected function createJwt($uid, $password) {
		$token = [
			'uid' => $uid,
			'password' => $this->crypto->encrypt($password, $this->gss->getJwtKey()),
			'exp' => time() + 300, // expires after 5 minutes
		];

		$jwt = JWT::encode($token, $this->gss->getJwtKey());

		return $jwt;
	}

}
