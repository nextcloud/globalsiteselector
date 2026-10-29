<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit\Controller;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\User\Session;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Controller\SlaveController;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\ExpiredException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SlaveControllerTest extends TestCase {
	private MockObject&IRequest $request;
	private GlobalSiteSelector $gss;
	private MockObject&LoggerInterface $logger;
	private MockObject&IUserSession $userSession;
	private MockObject&IURLGenerator $urlGenerator;
	private MockObject&ICrypto $crypto;
	private TokenHandler $tokenHandler;
	private MockObject&IUserManager $userManager;
	private UserBackend $userBackend;
	private MockObject&ISession $session;
	private MockObject&Slave $slave;
	private MockObject&GlobalScaleService $globalScaleService;
	private MockObject&GlobalShareService $globalShareService;
	private MockObject&SlaveService $slaveService;
	private MockObject&IConfig $config;
	private MockObject&IProvider $tokenProvider;
	private MockObject&AuditLogService $auditLogService;

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
		$this->slave = $this->createMock(Slave::class);
		$this->slaveService = $this->createMock(SlaveService::class);
		$this->globalScaleService = $this->createMock(GlobalScaleService::class);
		$this->globalShareService = $this->createMock(GlobalShareService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);
	}

	private function getInstance(array $mockMathods = []): SlaveController&MockObject {
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
					$this->slave,
					$this->slaveService,
					$this->globalScaleService,
					$this->globalShareService,
					$this->config,
					$this->tokenProvider,
					$this->auditLogService,
					$this->logger
				]
			)->onlyMethods($mockMathods)->getMock();
	}

	public function testDecodeJwt(): void {
		$controller = $this->getInstance();
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';
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

	public static function dataInitAccountWithInvalidJwt(): array {
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';
		$header = JWT::urlsafeB64Encode(json_encode(['alg' => ['HS256']]));
		return [
			'invalid' => ['invalid', 'invalid_jwt', \UnexpectedValueException::class],
			'expired' => [JWT::encode(['uid' => 'alice', 'exp' => time() - 300], $jwtKey, Application::JWT_ALGORITHM), 'expired_jwt', \UnexpectedValueException::class],
			'malformed header' => [$header . '.' . $header . '.signature', 'invalid_jwt', \TypeError::class],
		];
	}

	#[DataProvider('dataInitAccountWithInvalidJwt')]
	public function testInitAccountWithInvalidJwtWritesAuditLog(string $jwt, string $reason, string $exception): void {
		$this->gss->method('isSlave')->willReturn(true);
		$this->gss->method('getJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->userBackend->expects($this->never())->method('createUserIfNotExists');
		$this->expectAuditLog(Operation::AccountProvisioningFailed, 'Global scale account provisioning rejected: %s', [
			'reason' => $reason,
		]);

		$this->expectException($exception);
		$this->getInstance()->initAccount($jwt);
	}

	public function testInitAccountWithoutJwtKeyWritesNoAuditLog(): void {
		$this->gss->method('isSlave')->willReturn(true);
		$this->gss->method('getJwtKey')->willReturn('');
		$this->expectNoAuditLog();

		$this->expectException(\InvalidArgumentException::class);
		$this->getInstance()->initAccount('invalid');
	}

	private function prepareAutoLogin(): void {
		$this->gss->method('getMasterUrl')->willReturn('https://master.example');
		$this->gss->method('isMaster')->willReturn(false);
	}

	private function prepareSessionCreation(): void {
		$this->userSession = $this->createMock(Session::class);
		$this->session->method('getId')->willReturn('session-id');
		$sessionToken = $this->createMock(IToken::class);
		$sessionToken->method('getScopeAsArray')->willReturn([]);
		$this->tokenProvider->method('getToken')->with('session-id')->willReturn($sessionToken);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);
	}

	private function mockUser(bool $enabled): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function expectAuditLog(Operation $operation, string $message, array $parameters): void {
		$this->auditLogService->expects($this->once())
			->method('log')
			->with($operation, $message, $parameters);
	}

	private function expectNoAuditLog(): void {
		$this->auditLogService->expects($this->never())->method('log');
	}

	public static function dataAutoLoginSucceeded(): array {
		return [
			'saml' => ['saml', 'saml'],
			'oidc' => ['oidc', 'oidc'],
			'password' => ['', 'password'],
		];
	}

	#[DataProvider('dataAutoLoginSucceeded')]
	public function testAutoLoginSucceededWritesAuditLog(string $backend, string $expectedBackend): void {
		$this->prepareAutoLogin();
		$this->prepareSessionCreation();
		$this->userManager->method('get')->with('alice')->willReturn($this->mockUser(true));
		$this->userSession->method('login')->with('alice', 'password')->willReturn(true);
		$this->expectAuditLog(Operation::AutoLoginSucceeded, 'User "%s" logged in via global scale autologin (%s)', [
			'userId' => 'alice',
			'backend' => $expectedBackend,
		]);

		$controller = $this->getInstance(['decodeJwt', 'autoprovisionIfNeeded']);
		$controller->method('decodeJwt')->willReturn(['alice', 'password', ['target' => '/apps/files', 'backend' => $backend]]);

		$controller->autoLogin('jwt');
	}

	public function testAutoLoginWithInvalidJwtWritesAuditLog(): void {
		$this->prepareAutoLogin();
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => '',
			'reason' => 'invalid_jwt',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException(new \UnexpectedValueException('Signature verification failed'));

		$controller->autoLogin('jwt');
	}

	public function testAutoLoginWithWrongPasswordWritesAuditLog(): void {
		$this->prepareAutoLogin();
		$this->userSession->method('login')->with('alice', 'wrong')->willReturn(false);
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => 'alice',
			'reason' => 'login_failed',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willReturn(['alice', 'wrong', ['target' => '/']]);

		$controller->autoLogin('jwt');
	}

	public function testAutoLoginWithDisabledAccountWritesAuditLog(): void {
		$this->prepareAutoLogin();
		$this->userManager->method('get')->with('alice')->willReturn($this->mockUser(false));
		$this->slave->expects($this->once())->method('deleteUser')->with(['uid' => 'alice']);
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => 'alice',
			'reason' => 'account_disabled',
		]);
		$controller = $this->getInstance(['decodeJwt', 'autoprovisionIfNeeded']);
		$controller->method('decodeJwt')->willReturn(['alice', '', ['target' => '/', 'backend' => 'saml']]);

		$controller->autoLogin('jwt');
	}

	public function testAutoLoginWithExpiredJwtWritesAuditLog(): void {
		$this->prepareAutoLogin();
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => '',
			'reason' => 'expired_jwt',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException(new ExpiredException('Expired token'));

		$controller->autoLogin('jwt');
	}

	public function testAutoLoginWithoutJwtWritesNoAuditLog(): void {
		$this->prepareAutoLogin();
		$this->expectNoAuditLog();
		$controller = $this->getInstance(['decodeJwt']);
		$controller->expects($this->never())->method('decodeJwt');

		$controller->autoLogin('');
	}

	public function testCreateAppTokenSucceededWritesNoFailureAuditLog(): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->userManager->method('userExists')->with('alice')->willReturn(true);
		$this->userSession->method('login')->with('alice', 'password')->willReturn(true);
		$this->tokenHandler->expects($this->once())->method('generateAppToken')->with('alice')->willReturn(['token' => 'secret']);
		$this->expectNoAuditLog();
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willReturn(['alice', 'password', []]);

		$controller->createAppToken('jwt');
	}

	public static function dataCreateAppTokenRejected(): array {
		return [
			'wrong password' => [true, false, 'login_failed'],
			'unknown account' => [false, true, 'user_not_found'],
		];
	}

	#[DataProvider('dataCreateAppTokenRejected')]
	public function testCreateAppTokenRejectedWritesAuditLog(bool $userExists, bool $loginResult, string $reason): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->userManager->method('userExists')->with('alice')->willReturn($userExists);
		$this->userSession->method('login')->willReturn($loginResult);
		$this->tokenHandler->expects($this->never())->method('generateAppToken');
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => 'alice',
			'reason' => $reason,
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willReturn(['alice', 'password', []]);

		$controller->createAppToken('jwt');
	}

	public function testCreateAppTokenWithInvalidJwtWritesAuditLog(): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => '',
			'reason' => 'invalid_jwt',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException(new \UnexpectedValueException('Signature verification failed'));

		$controller->createAppToken('jwt');
	}

	public function testCreateAppTokenWithExpiredJwtWritesAuditLog(): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => '',
			'reason' => 'expired_jwt',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException(new ExpiredException('Expired token'));

		$controller->createAppToken('jwt');
	}
}
