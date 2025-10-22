<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\GlobalSiteSelector\Tests\Unit\Controller;

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Controller\SlaveController;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SlaveControllerTest extends TestCase {
	private IRequest $request;
	private GlobalSiteSelector $gss;
	private LoggerInterface $logger;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private ICrypto $crypto;
	private TokenHandler $tokenHandler;
	private IUserManager $userManager;
	private UserBackend $userBackend;
	private ISession $session;
	private SlaveService $slaveService;
	private IConfig $config;

	public function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->gss = $this->getMockBuilder(GlobalSiteSelector::class)
			->disableOriginalConstructor()->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->tokenHandler = $this->getMockBuilder(TokenHandler::class)
			->disableOriginalConstructor()->getMock();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userBackend = $this->getMockBuilder(UserBackend::class)
			->disableOriginalConstructor()->getMock();
		$this->session = $this->createMock(ISession::class);
		$this->slaveService = $this->createMock(SlaveService::class);
		$this->config = $this->createMock(IConfig::class);
	}

	/**
	 * @param array $mockMathods
	 * @return SlaveController|\PHPUnit_Framework_MockObject_MockObject
	 */
	private function getInstance(array $mockMathods = []) {
		return $this->getMockBuilder(SlaveController::class)
			->setConstructorArgs(
				[
					'gss-tests',
					$this->request,
					$this->gss,
					$this->userSession,
					$this->urlGenerator,
					$this->crypto,
					$this->tokenHandler,
					$this->userManager,
					$this->userBackend,
					$this->session,
					$this->slaveService,
					$this->config,
					$this->logger
				]
			)->onlyMethods($mockMathods)->getMock();
	}

	public function testDecodeJwt() {
		$controller = $this->getInstance();
		$jwtKey = 'jwtkey';
		$encryptedPassword = 'password-encrypted';
		$plainPassword = 'password';

		$token = [
			'uid' => 'user',
			'password' => $encryptedPassword,
			'options' => json_encode(['option1' => 'foo']),
			'exp' => time() + 300, // expires after 5 minutes
		];

		$jwt = JWT::encode($token, $jwtKey, Application::JWT_ALGORITHM);

		$this->gss->expects($this->any())->method('getJwtKey')->willReturn($jwtKey);
		$this->crypto->expects($this->once())->method('decrypt')->with($encryptedPassword, $jwtKey)
			->willReturn($plainPassword);

		[$uid, $password, $options] = $this->invokePrivate($controller, 'decodeJwt', [$jwt]);

		$this->assertSame('user', $uid);
		$this->assertSame($plainPassword, $password);
		$this->assertSame($options, ['option1' => 'foo']);
	}
}
