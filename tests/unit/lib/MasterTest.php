<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit;

use OC\Core\Service\LoginFlowV2Service;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Master;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\HintException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Server;
use OCP\ServerVersion;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class MasterTest extends TestCase {
	private GlobalSiteSelector&MockObject $gss;
	private ICrypto&MockObject $crypto;
	private Lookup&MockObject $lookup;
	private IRequest&MockObject $request;
	private IClientService&MockObject $clientService;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private ISession&MockObject $session;
	private LoginFlowV2Service&MockObject $loginflow;
	private GlobalScaleService&MockObject $globalScaleService;
	private ServerVersion $serverVersion;

	public function setUp(): void {
		parent::setUp();

		$this->gss = $this->getMockBuilder(GlobalSiteSelector::class)
			->disableOriginalConstructor()->getMock();
		$this->crypto = $this->createMock(ICrypto::class);
		$this->lookup = $this->getMockBuilder(Lookup::class)
			->disableOriginalConstructor()->getMock();
		$this->loginflow = $this->createMock(LoginFlowV2Service::class);
		$this->serverVersion = Server::get(ServerVersion::class);
		$this->request = $this->createMock(IRequest::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->session = $this->createMock(ISession::class);
		$this->globalScaleService = $this->getMockBuilder(GlobalScaleService::class)
			->disableOriginalConstructor()->getMock();
	}

	private function getInstance(array $mockMethods = []): Master&MockObject {
		return $this->getMockBuilder(Master::class)
			->setConstructorArgs(
				[
					$this->session,
					$this->gss,
					$this->crypto,
					$this->loginflow,
					$this->serverVersion,
					$this->lookup,
					$this->request,
					$this->clientService,
					$this->appConfig,
					$this->config,
					$this->logger,
					$this->globalScaleService,
				]
			)->onlyMethods($mockMethods)->getMock();
	}

	private function getUser(string $uid, $backend = null): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getBackend')->willReturn($backend);

		return $user;
	}

	public function testHandleLoginRequest(): void {
		$location = 'https://nextcloud.com';
		$user = $this->getUser('user');

		$master = $this->getInstance(['redirectUser']);

		$this->request->method('getPathInfo')->willReturn('');
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturn('');

		$this->globalScaleService->expects($this->once())->method('getSecondaryRemoteLocation')
			->with($user)
			->willReturn($location);

		$master->expects($this->once())->method('redirectUser')
			->with('user', 'password', $location, ['target' => '/', 'params' => []]);

		$master->handleLoginRequest($user, 'password');
	}

	public function testHandleLoginRequestException(): void {
		$user = $this->getUser('user');

		$master = $this->getInstance(['redirectUser']);

		$this->request->method('getPathInfo')->willReturn('');
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturn('');

		$this->globalScaleService->method('getSecondaryRemoteLocation')
			->with($user)
			->willReturn(null);

		$master->expects($this->never())->method('redirectUser');

		$this->expectException(HintException::class);
		$master->handleLoginRequest($user, 'password');
	}

	public function testHandleLoginRequestIgnoresValidJwtUnlessIgnored(): void {
		$user = $this->getUser('user');

		$master = $this->getInstance(['redirectUser', 'isValidJwt']);

		$this->request->method('getParam')->willReturn('some-jwt');

		$master->expects($this->once())->method('isValidJwt')
			->with('some-jwt')
			->willReturn(true);

		$this->globalScaleService->expects($this->never())->method('getSecondaryRemoteLocation');
		$master->expects($this->never())->method('redirectUser');

		$master->handleLoginRequest($user, 'password');
	}

	public function testHandleLoginRequestIgnoreJwtSkipsJwtCheck(): void {
		$location = 'https://nextcloud.com';
		$user = $this->getUser('user');

		$master = $this->getInstance(['redirectUser', 'isValidJwt']);

		$this->request->method('getPathInfo')->willReturn('');
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturn('some-jwt');

		$master->expects($this->never())->method('isValidJwt');

		$this->globalScaleService->method('getSecondaryRemoteLocation')
			->willReturn($location);

		$master->expects($this->once())->method('redirectUser');

		$master->handleLoginRequest($user, 'password', true);
	}

	public function testCreateJWT(): void {
		$uid = 'user1';
		$plainPassword = 'password';
		$encryptedPassword = 'password-encrypted';
		$options = ['foo' => 'bar'];
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';

		$master = $this->getInstance();

		$this->gss->expects($this->any())->method('getJwtKey')->willReturn($jwtKey);
		$this->crypto->expects($this->once())->method('encrypt')->with($plainPassword, $jwtKey)
			->willReturn($encryptedPassword);

		$token = $this->invokePrivate($master, 'createJwt', [$uid, $plainPassword, $options]);

		$decoded = (array)JWT::decode($token, new Key($jwtKey, Application::JWT_ALGORITHM));

		$this->assertSame($uid, $decoded['uid']);
		$this->assertSame($encryptedPassword, $decoded['password']);
		$this->assertSame(json_encode($options), $decoded['options']);
	}

	/**
	 * @dataProvider dataTestBuildBasicAuthUrl
	 */
	public function testBuildBasicAuthUrl(string $url, string $uid, string $password, string $expected): void {
		$master = $this->getInstance();
		$result = $this->invokePrivate($master, 'buildBasicAuthUrl', [$url, $uid, $password]);
		$this->assertSame($expected, $result);
	}

	public function dataTestBuildBasicAuthUrl(): array {
		return [
			['http://nextcloud.com', 'user', 'password', 'http://user:password@nextcloud.com'],
			['https://nextcloud.com', 'user', 'password', 'https://user:password@nextcloud.com'],
			['nextcloud.com', 'user', 'password', 'https://user:password@nextcloud.com'],
		];
	}
}
