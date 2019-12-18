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


use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCP\Security\ISecureRandom;

/**
 * Class TokenHandler
 *
 * Handle app tokens, needed for client login
 *
 * @package OCA\GlobalSiteSelector
 */
class TokenHandler {

	/** @var IProvider */
	private $tokenProvider;

	/** @var ISecureRandom */
	private $random;

	public function __construct(ISecureRandom $ramdom, IProvider $tokenProvider) {
		$this->random = $ramdom;
		$this->tokenProvider = $tokenProvider;
	}

	/**
	 * generate app token
	 *
	 * @param string $uid
	 * @return array
	 */
	public function generateAppToken($uid) {
		// generate random token
		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$deviceToken = $this->tokenProvider->generateToken($token, $uid, $uid, null, 'Client login', IToken::PERMANENT_TOKEN);
		$tokenData = $deviceToken->jsonSerialize();

		return [
			'token' => $token,
			'deviceToken' => $tokenData,
		];


	}

}
