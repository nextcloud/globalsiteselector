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
use OCA\GlobalSiteSelector\Exceptions\ConfigurationException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\BeforeValidException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\ExpiredException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\SignatureInvalidException;
use OCP\AppFramework\Http;
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

		$this->gss->method('getValidJwtKey')->willReturn($jwtKey);
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
			'invalid' => ['invalid', '', 'invalid_jwt'],
			'expired' => [JWT::encode(['uid' => 'alice', 'exp' => time() - 300], $jwtKey, Application::JWT_ALGORITHM), 'alice', 'expired_jwt'],
			'malformed header' => [$header . '.' . $header . '.signature', '', 'invalid_jwt'],
			'wrong signature' => [JWT::encode(['uid' => 'alice'], 'anotherkeylongenoughforsecurityasthisisnowimportant', Application::JWT_ALGORITHM), '', 'invalid_jwt_signature'],
			'not yet valid' => [JWT::encode(['uid' => 'alice', 'nbf' => time() + 300], $jwtKey, Application::JWT_ALGORITHM), 'alice', 'jwt_not_yet_valid'],
		];
	}

	#[DataProvider('dataInitAccountWithInvalidJwt')]
	public function testInitAccountWithInvalidJwtWritesAuditLog(string $jwt, string $userId, string $reason): void {
		$this->gss->method('isSlave')->willReturn(true);
		$this->gss->method('getValidJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->userBackend->expects($this->never())->method('createUserIfNotExists');
		$this->expectAuditLog(Operation::AccountProvisioningFailed, 'Global scale account provisioning for "%s" rejected: %s', [
			'userId' => $userId,
			'reason' => $reason,
		]);

		$response = $this->getInstance()->initAccount($jwt);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public static function dataInitAccountRejected(): array {
		return [
			'missing uid' => [[], false, '', 'invalid_jwt_payload'],
			'uid not a string' => [['uid' => ['alice']], false, '', 'invalid_jwt_payload'],
			'existing account' => [['uid' => 'alice'], true, 'alice', 'account_exists'],
		];
	}

	#[DataProvider('dataInitAccountRejected')]
	public function testInitAccountRejectedWritesAuditLog(array $payload, bool $userExists, string $userId, string $reason): void {
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';
		$this->gss->method('isSlave')->willReturn(true);
		$this->gss->method('getValidJwtKey')->willReturn($jwtKey);
		$this->userManager->method('userExists')->willReturn($userExists);
		$this->userBackend->expects($this->never())->method('createUserIfNotExists');
		$this->expectAuditLog(Operation::AccountProvisioningFailed, 'Global scale account provisioning for "%s" rejected: %s', [
			'userId' => $userId,
			'reason' => $reason,
		]);

		$response = $this->getInstance()->initAccount(JWT::encode($payload + ['exp' => time() + 300], $jwtKey, Application::JWT_ALGORITHM));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function dataSharedFileRequestWithInvalidJwt(): array {
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';
		$header = JWT::urlsafeB64Encode(json_encode(['alg' => ['HS256']]));
		$wrongSignature = JWT::encode(['fileId' => 1], 'anotherkeylongenoughforsecurityasthisisnowimportant', Application::JWT_ALGORITHM);
		$notYetValid = JWT::encode(['fileId' => 1, 'nbf' => time() + 300], $jwtKey, Application::JWT_ALGORITHM);
		$data = [];
		foreach (['sharedFile', 'refreshSharedFile'] as $request) {
			$data[$request . ' invalid'] = [$request, 'invalid', 'invalid_jwt'];
			$data[$request . ' malformed header'] = [$request, $header . '.' . $header . '.signature', 'invalid_jwt'];
			$data[$request . ' wrong signature'] = [$request, $wrongSignature, 'invalid_jwt_signature'];
			$data[$request . ' not yet valid'] = [$request, $notYetValid, 'jwt_not_yet_valid'];
		}
		return $data;
	}

	#[DataProvider('dataSharedFileRequestWithInvalidJwt')]
	public function testSharedFileRequestWithInvalidJwtWritesAuditLog(string $request, string $jwt, string $reason): void {
		$this->gss->method('getValidJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->globalShareService->expects($this->never())->method('getSharedFiles');
		$this->globalShareService->expects($this->never())->method('refreshSharedTarget');
		$this->expectAuditLog(Operation::SharedFileRequestFailed, 'Global scale %s request rejected: %s', [
			'request' => $request,
			'reason' => $reason,
		]);

		$response = $this->getInstance()->$request($jwt);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertTrue($response->isThrottled());
	}

	public static function dataRefreshSharedFileWithInvalidShares(): array {
		return [
			'missing shares' => [[]],
			'shares not an array' => [['shares' => 'share']],
			'share not an array' => [['shares' => ['share']]],
			'share with an invalid field' => [['shares' => [['shareWith' => ['alice']]]]],
			'share with an invalid target' => [['shares' => [['target' => 'file']]]],
			'instance not a string' => [['instance' => ['node1.example'], 'shares' => []]],
		];
	}

	#[DataProvider('dataRefreshSharedFileWithInvalidShares')]
	public function testRefreshSharedFileWithInvalidPayloadWritesAuditLog(array $payload): void {
		$this->gss->method('getValidJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->globalShareService->expects($this->never())->method('refreshSharedTarget');
		$this->expectAuditLog(Operation::SharedFileRequestFailed, 'Global scale %s request rejected: %s', [
			'request' => 'refreshSharedFile',
			'reason' => 'invalid_jwt_payload',
		]);

		$response = $this->getInstance()->refreshSharedFile($this->signJwt($payload));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->isThrottled());
	}

	public function testRefreshSharedFileWithoutTargetWritesNoAuditLog(): void {
		$this->gss->method('getValidJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->globalShareService->expects($this->once())->method('refreshSharedTarget')
			->with('node1.example', 0, 1, 'token', null);
		$this->expectNoAuditLog();

		$response = $this->getInstance()->refreshSharedFile($this->signJwt([
			'instance' => 'node1.example',
			'shares' => [['id' => 1, 'shareToken' => 'token', 'target' => null]],
		]));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public static function dataSharedFileWithInvalidPayload(): array {
		return [
			'instance not a string' => [['instance' => ['node1.example']]],
			'target not an array' => [['target' => 'file']],
			'target with an invalid field' => [['target' => ['name' => ['file']]]],
		];
	}

	#[DataProvider('dataSharedFileWithInvalidPayload')]
	public function testSharedFileWithInvalidPayloadWritesAuditLog(array $payload): void {
		$this->gss->method('getValidJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->globalShareService->expects($this->never())->method('getSharedFiles');
		$this->expectAuditLog(Operation::SharedFileRequestFailed, 'Global scale %s request rejected: %s', [
			'request' => 'sharedFile',
			'reason' => 'invalid_jwt_payload',
		]);

		$response = $this->getInstance()->sharedFile($this->signJwt($payload));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->isThrottled());
	}

	public function testInitAccountWithInvalidJwtKeyWritesNoAuditLog(): void {
		$this->gss->method('isSlave')->willReturn(true);
		$this->gss->method('getValidJwtKey')->willThrowException(new ConfigurationException('invalid key'));
		$this->expectNoAuditLog();

		$this->expectException(ConfigurationException::class);
		$this->getInstance()->initAccount(JWT::encode(['uid' => 'alice'], 'jwtkeybutlongenoughforsecurityasthisisnowimportant', Application::JWT_ALGORITHM));
	}

	public function testSharedFileWithInvalidJwtKeyWritesNoAuditLog(): void {
		$this->gss->method('getValidJwtKey')->willThrowException(new ConfigurationException('invalid key'));
		$this->expectNoAuditLog();

		$this->expectException(ConfigurationException::class);
		$this->getInstance()->sharedFile(JWT::encode(['fileId' => 1], 'jwtkeybutlongenoughforsecurityasthisisnowimportant', Application::JWT_ALGORITHM));
	}

	public function testAutoLoginWithInvalidJwtKeyWritesNoAuditLog(): void {
		$this->prepareAutoLogin();
		$this->gss->method('getValidJwtKey')->willThrowException(new ConfigurationException('invalid key'));
		$this->expectNoAuditLog();

		$response = $this->getInstance()->autoLogin('jwt');
		$this->assertFalse($response->isThrottled());
	}

	public function testCreateAppTokenWithInvalidJwtKeyWritesNoAuditLog(): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->gss->method('getValidJwtKey')->willThrowException(new ConfigurationException('invalid key'));
		$this->expectNoAuditLog();

		$response = $this->getInstance()->createAppToken('jwt');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->isThrottled());
	}

	public static function dataInvalidJwtPayload(): array {
		return [
			'missing uid' => [['password' => 'encrypted'], false, ''],
			'missing password' => [['uid' => 'alice'], false, 'alice'],
			'password not decrypted' => [['uid' => 'alice', 'password' => 'encrypted'], true, 'alice'],
			'password not a string' => [['uid' => 'alice', 'password' => ['encrypted']], false, 'alice'],
			'options not a string' => [['uid' => 'alice', 'password' => 'encrypted', 'options' => ['target' => '/']], false, 'alice'],
			'options not json' => [['uid' => 'alice', 'password' => 'encrypted', 'options' => 'target'], false, 'alice'],
		];
	}

	#[DataProvider('dataInvalidJwtPayload')]
	public function testAutoLoginWithInvalidJwtPayloadWritesAuditLog(array $payload, bool $decryptFails, string $userId): void {
		$this->prepareAutoLogin();
		$this->mockSignedJwtPayload($decryptFails);
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => $userId,
			'reason' => 'invalid_jwt_payload',
		]);

		$response = $this->getInstance()->autoLogin($this->signJwt($payload));
		$this->assertTrue($response->isThrottled());
	}

	#[DataProvider('dataInvalidJwtPayload')]
	public function testCreateAppTokenWithInvalidJwtPayloadWritesAuditLog(array $payload, bool $decryptFails, string $userId): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->mockSignedJwtPayload($decryptFails);
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => $userId,
			'reason' => 'invalid_jwt_payload',
		]);

		$response = $this->getInstance()->createAppToken($this->signJwt($payload));
		$this->assertTrue($response->isThrottled());
	}

	public function testAutoLoginWithMalformedJwtHeaderWritesAuditLog(): void {
		$this->prepareAutoLogin();
		$this->mockSignedJwtPayload(false);
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => '',
			'reason' => 'invalid_jwt',
		]);

		$response = $this->getInstance()->autoLogin($this->malformedJwt());
		$this->assertTrue($response->isThrottled());
	}

	public function testCreateAppTokenWithMalformedJwtHeaderWritesAuditLog(): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->mockSignedJwtPayload(false);
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => '',
			'reason' => 'invalid_jwt',
		]);

		$response = $this->getInstance()->createAppToken($this->malformedJwt());
		$this->assertTrue($response->isThrottled());
	}

	public function testAutoLoginDoesNotHideTypeError(): void {
		$this->prepareAutoLogin();
		$this->expectNoAuditLog();
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException(new \TypeError('bug'));

		$this->expectException(\TypeError::class);
		$controller->autoLogin('jwt');
	}

	/**
	 * a JWT with an array as algorithm, on which the JWT library throws a TypeError
	 */
	private function malformedJwt(): string {
		$header = JWT::urlsafeB64Encode(json_encode(['alg' => ['HS256']]));
		return $header . '.' . $header . '.signature';
	}

	private function mockSignedJwtPayload(bool $decryptFails): void {
		$this->gss->method('getValidJwtKey')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		if ($decryptFails) {
			$this->crypto->method('decrypt')->willThrowException(new \Exception('HMAC does not match.'));
		}
	}

	private function signJwt(array $payload): string {
		return JWT::encode($payload + ['exp' => time() + 300], 'jwtkeybutlongenoughforsecurityasthisisnowimportant', Application::JWT_ALGORITHM);
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

	private function expiredException(string $uid): ExpiredException {
		$e = new ExpiredException('Expired token');
		$e->setPayload((object)['uid' => $uid]);
		return $e;
	}

	/**
	 * @param \Throwable|class-string<BeforeValidException> $exception an exception, or a JWT exception class to throw with a payload for alice
	 */
	private function jwtException(\Throwable|string $exception): \Throwable {
		if ($exception instanceof \Throwable) {
			return $exception;
		}

		$e = new $exception('Cannot handle token');
		$e->setPayload((object)['uid' => 'alice']);
		return $e;
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
			'userId' => 'alice',
			'reason' => 'expired_jwt',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException($this->expiredException('alice'));

		$controller->autoLogin('jwt');
	}

	public static function dataJwtFailures(): array {
		return [
			'wrong signature' => [new SignatureInvalidException('Signature verification failed'), '', 'invalid_jwt_signature'],
			'not yet valid' => [BeforeValidException::class, 'alice', 'jwt_not_yet_valid'],
		];
	}

	#[DataProvider('dataJwtFailures')]
	public function testAutoLoginWithJwtFailureWritesAuditLog(\Throwable|string $exception, string $userId, string $reason): void {
		$this->prepareAutoLogin();
		$this->expectAuditLog(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => $userId,
			'reason' => $reason,
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException($this->jwtException($exception));

		$response = $controller->autoLogin('jwt');
		$this->assertTrue($response->isThrottled());
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

	#[DataProvider('dataJwtFailures')]
	public function testCreateAppTokenWithJwtFailureWritesAuditLog(\Throwable|string $exception, string $userId, string $reason): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => $userId,
			'reason' => $reason,
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException($this->jwtException($exception));

		$response = $controller->createAppToken('jwt');
		$this->assertTrue($response->isThrottled());
	}

	public function testCreateAppTokenWithExpiredJwtWritesAuditLog(): void {
		$this->gss->method('getMode')->willReturn('slave');
		$this->expectAuditLog(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => 'alice',
			'reason' => 'expired_jwt',
		]);
		$controller = $this->getInstance(['decodeJwt']);
		$controller->method('decodeJwt')->willThrowException($this->expiredException('alice'));

		$controller->createAppToken('jwt');
	}
}
