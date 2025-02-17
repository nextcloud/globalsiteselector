<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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

	public function __construct(
		private IProvider $tokenProvider,
		private ISecureRandom $random,
	) {
	}

	/**
	 * generate app token
	 *
	 * @param string $uid
	 *
	 * @return array
	 */
	public function generateAppToken($uid) {
		// generate random token
		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$deviceToken = $this->tokenProvider->generateToken($token, $uid, $uid, null, 'Client login', IToken::PERMANENT_TOKEN);
		$tokenData = $deviceToken->jsonSerialize();

		return [
			'token' => $token,
			'deviceToken' => $tokenData,
		];
	}
}
