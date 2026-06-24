<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Core\Service;

use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Core\Data\LoginFlowV2Credentials;
use OC\Core\Data\LoginFlowV2Tokens;
use OC\Core\Db\LoginFlowV2;
use OC\Core\Db\LoginFlowV2Mapper;
use OC\Core\Exception\LoginFlowV2ClientForbiddenException;
use OC\Core\Exception\LoginFlowV2NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class LoginFlowV2Service {
	public function __construct(
		private LoginFlowV2Mapper $mapper,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
		private IProvider $tokenProvider,
	) {
	}

	/**
	 * @param string $pollToken
	 * @return LoginFlowV2Credentials
	 * @throws LoginFlowV2NotFoundException
	 */
	public function poll(string $pollToken): LoginFlowV2Credentials
    {
    }

	/**
	 * @param string $loginToken
	 * @return LoginFlowV2
	 * @throws LoginFlowV2NotFoundException
	 * @throws LoginFlowV2ClientForbiddenException
	 */
	public function getByLoginToken(string $loginToken): LoginFlowV2
    {
    }

	/**
	 * @param string $loginToken
	 * @return bool returns true if the start was successfull. False if not.
	 */
	public function startLoginFlow(string $loginToken): bool
    {
    }

	/**
	 * @param string $loginToken
	 * @param string $sessionId
	 * @param string $server
	 * @param string $userId
	 * @return bool true if the flow was successfully completed false otherwise
	 */
	public function flowDone(string $loginToken, string $sessionId, string $server, string $userId): bool
    {
    }

	public function flowDoneWithAppPassword(string $loginToken, string $server, string $loginName, string $appPassword): bool
    {
    }

	public function createTokens(string $userAgent): LoginFlowV2Tokens
    {
    }
}
