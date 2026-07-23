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
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\HintException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
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
					$this->logger
				]
			)->onlyMethods($mockMethods)->getMock();
	}

	public function testHandleLoginRequest(): void {
		$location = 'nextcloud.com';
		$master = $this->getInstance(['queryLookupServer', 'redirectUser']);
		$master->expects($this->once())->method('queryLookupServer')
			->willReturn($location);

		$this->request->method('getServerProtocol')
			->willReturn('https');
		$master->expects($this->once())->method('redirectUser')
			->with('user', 'password', 'https://' . $location);

		$master->handleLoginRequest('user', 'password');
	}

	public function testHandleLoginRequestException(): void {
		$location = '';
		$master = $this->getInstance(['queryLookupServer', 'redirectUser']);
		$master->expects($this->once())->method('queryLookupServer')
			->willReturn($location);

		$this->expectException(HintException::class);
		$master->expects($this->never())->method('redirectUser');
		$master->handleLoginRequest('user', 'password');
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

	/**
	 * @dataProvider dataTestNormalizeLocation
	 *
	 * @param $url
	 * @param $expected
	 */
	public function testNormalizeLocation(string $url, string $expected): void {
		$master = $this->getInstance();
		$this->request->expects($this->any())->method('getServerProtocol')->willReturn('https');
		$result = $this->invokePrivate($master, 'normalizeLocation', [$url]);
		$this->assertSame($expected, $result);
	}

	public function dataTestNormalizeLocation(): array {
		return [
			['localhost/nextcloud', 'https://localhost/nextcloud'],
			['https://localhost/nextcloud', 'https://localhost/nextcloud'],
			['http://localhost/nextcloud', 'http://localhost/nextcloud'],
		];
	}
}
