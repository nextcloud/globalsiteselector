<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit\Service;

use OCA\GlobalSiteSelector\Exceptions\IsLocalAdminException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class GlobalScaleServiceTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private IClientService&MockObject $clientService;
	private IConfig&MockObject $config;
	private IURLGenerator&MockObject $urlGenerator;
	private ISecureRandom&MockObject $secureRandom;
	private GlobalSiteSelector&MockObject $gss;
	private Lookup&MockObject $lookup;
	private LoggerInterface&MockObject $logger;
	private IRequest&MockObject $request;
	private IUserConfig&MockObject $userConfig;
	private ITimeFactory&MockObject $time;

	public function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->gss = $this->getMockBuilder(GlobalSiteSelector::class)
			->disableOriginalConstructor()->getMock();
		$this->lookup = $this->getMockBuilder(Lookup::class)
			->disableOriginalConstructor()->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);
	}

	public function tearDown(): void {
		// Some tests freeze "now" for JWT::decode() via this testing hook (see below);
		// always reset it so it can't leak into unrelated tests.
		JWT::$timestamp = null;

		parent::tearDown();
	}

	private function getInstance(array $mockMethods = []): GlobalScaleService&MockObject {
		return $this->getMockBuilder(GlobalScaleService::class)
			->setConstructorArgs(
				[
					$this->appConfig,
					$this->clientService,
					$this->config,
					$this->urlGenerator,
					$this->secureRandom,
					$this->gss,
					$this->lookup,
					$this->logger,
					$this->request,
					$this->userConfig,
					$this->time,
				]
			)->onlyMethods($mockMethods)->getMock();
	}

	private function getUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getBackend')->willReturn(null);

		return $user;
	}

	public function testGetSecondaryRemoteLocationSkipsLocalAdmin(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['gss.master.admin', [], ['admin']],
			['gss.master.accounts', [], []],
		]);

		$service = $this->getInstance(['queryLookupServer']);
		$service->expects($this->never())->method('queryLookupServer');
		$this->userConfig->expects($this->never())->method('setValueArray');

		$this->expectException(IsLocalAdminException::class);

		$service->getSecondaryRemoteLocation($this->getUser('admin'));
	}

	public function testGetSecondaryRemoteLocationSkipsLocalAccount(): void {
		$this->config->method('getSystemValue')->willReturnMap([
			['gss.master.admin', [], []],
			['gss.master.accounts', [], ['localuser']],
		]);

		$service = $this->getInstance(['queryLookupServer']);
		$service->expects($this->never())->method('queryLookupServer');
		$this->userConfig->expects($this->never())->method('setValueArray');

		$this->expectException(IsLocalAdminException::class);

		$service->getSecondaryRemoteLocation($this->getUser('localuser'));
	}

	public function testGetSecondaryRemoteLocationKeepsSchemeIfAlreadyPresent(): void {
		$service = $this->getInstance(['queryLookupServer']);
		$service->method('queryLookupServer')->willReturn('http://nextcloud.example.com');

		$this->assertSame(
			'http://nextcloud.example.com',
			$service->getSecondaryRemoteLocation($this->getUser('regularuser'))
		);
	}

	public function testGetSecondaryRemoteLocationReturnsNullWhenNothingFound(): void {
		$service = $this->getInstance(['queryLookupServer']);
		$service->method('queryLookupServer')->willReturn('');

		$this->userConfig->expects($this->never())->method('setValueArray');

		$this->assertNull($service->getSecondaryRemoteLocation($this->getUser('regularuser')));
	}

	public function testGetSecondaryRemoteLocationFallsBackToDiscoveryModule(): void {
		FakeUserDiscoveryModule::$location = 'discovered.example.com';

		$this->config->method('getSystemValueString')->willReturnMap([
			['gss.user.discovery.module', '', FakeUserDiscoveryModule::class],
		]);

		$service = $this->getInstance(['queryLookupServer']);
		$service->method('queryLookupServer')->willReturn('');

		$this->lookup->expects($this->once())->method('sanitizeUid');
		$this->request->method('getServerProtocol')->willReturn('https');

		$this->assertSame(
			'https://discovered.example.com',
			$service->getSecondaryRemoteLocation($this->getUser('regularuser'))
		);
	}

	public function testGetSecondaryRemoteLocationDoesNotUseDiscoveryModuleWhenLookupSucceeds(): void {
		FakeUserDiscoveryModule::$location = 'discovered.example.com';

		$this->config->method('getSystemValueString')->willReturnMap([
			['gss.user.discovery.module', '', FakeUserDiscoveryModule::class],
		]);

		$service = $this->getInstance(['queryLookupServer']);
		$service->method('queryLookupServer')->willReturn('nextcloud.example.com');

		$this->lookup->expects($this->never())->method('sanitizeUid');
		$this->request->method('getServerProtocol')->willReturn('https');

		$this->assertSame(
			'https://nextcloud.example.com',
			$service->getSecondaryRemoteLocation($this->getUser('regularuser'))
		);
	}

	public function testSendToSecondaryThrowsWhenLocationNotFound(): void {
		$service = $this->getInstance(['getSecondaryRemoteLocation']);
		$service->method('getSecondaryRemoteLocation')->willReturn(null);

		$this->clientService->expects($this->never())->method('newClient');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/No secondary location found/');

		$service->sendToSecondary($this->getUser('regularuser'), '/apps/oauth2/api/v1/pushtoken', ['uid' => 'regularuser']);
	}

	public function testSendToSecondarySendsSignedPayloadWithDefaultExpiry(): void {
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';

		$service = $this->getInstance(['getSecondaryRemoteLocation']);
		$service->method('getSecondaryRemoteLocation')->willReturn('https://secondary.example.com');

		$this->config->method('getSystemValueString')->willReturnMap([
			['gss.jwt.key', '', $jwtKey],
		]);
		$this->config->method('getSystemValueBool')->willReturn(false);
		$this->time->method('getTime')->willReturn(1000);

		$client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($client);

		$capturedOptions = null;
		$client->expects($this->once())->method('post')
			->with(
				'https://secondary.example.com/apps/oauth2/api/v1/pushtoken',
				$this->callback(function (array $options) use (&$capturedOptions): bool {
					$capturedOptions = $options;
					return true;
				})
			)
			->willReturn($this->createMock(IResponse::class));

		$service->sendToSecondary(
			$this->getUser('regularuser'),
			'/apps/oauth2/api/v1/pushtoken',
			['uid' => 'regularuser', 'token' => 'sometoken']
		);

		$this->assertSame(['OCS-APIRequest' => 'true'], $capturedOptions['headers']);
		$this->assertTrue($capturedOptions['verify']);
		$this->assertSame(['format' => 'json'], $capturedOptions['query']);

		// The payload's "exp" (1300) is derived from the mocked getTime() (1000), a
		// timestamp long in the past by real wall-clock time: freeze JWT::decode()'s
		// notion of "now" to that same mocked time so the token isn't seen as expired.
		JWT::$timestamp = 1000;
		$decoded = (array)JWT::decode($capturedOptions['body']['jwt'], new Key($jwtKey, 'HS256'));
		$this->assertSame('regularuser', $decoded['uid']);
		$this->assertSame('sometoken', $decoded['token']);
		$this->assertSame(1300, $decoded['exp']);
	}

	public function testSendToSecondaryKeepsExplicitExpiry(): void {
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';

		$service = $this->getInstance(['getSecondaryRemoteLocation']);
		$service->method('getSecondaryRemoteLocation')->willReturn('https://secondary.example.com');

		$this->config->method('getSystemValueString')->willReturnMap([
			['gss.jwt.key', '', $jwtKey],
		]);
		$this->config->method('getSystemValueBool')->willReturn(false);

		$client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($client);

		$capturedOptions = null;
		$client->method('post')
			->with($this->anything(), $this->callback(function (array $options) use (&$capturedOptions): bool {
				$capturedOptions = $options;
				return true;
			}))
			->willReturn($this->createMock(IResponse::class));

		$this->time->expects($this->never())->method('getTime');

		$service->sendToSecondary(
			$this->getUser('regularuser'),
			'/apps/oauth2/api/v1/pushtoken',
			['uid' => 'regularuser', 'exp' => 5000]
		);

		// Same as above: the explicit "exp" (5000) is a fictional timestamp far in the
		// past by real wall-clock time, so freeze JWT::decode()'s notion of "now" to
		// something before it.
		JWT::$timestamp = 1000;
		$decoded = (array)JWT::decode($capturedOptions['body']['jwt'], new Key($jwtKey, 'HS256'));
		$this->assertSame(5000, $decoded['exp']);
	}

	public function testSendToSecondaryWrapsNetworkException(): void {
		$service = $this->getInstance(['getSecondaryRemoteLocation']);
		$service->method('getSecondaryRemoteLocation')->willReturn('https://secondary.example.com');

		$this->config->method('getSystemValueString')->willReturn('jwtkeybutlongenoughforsecurityasthisisnowimportant');
		$this->config->method('getSystemValueBool')->willReturn(false);
		$this->time->method('getTime')->willReturn(1000);

		$client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($client);
		$client->method('post')->willThrowException(new \Exception('connection refused'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/network issue/');

		$service->sendToSecondary($this->getUser('regularuser'), '/path', ['uid' => 'regularuser']);
	}

	public function testDecodePayloadReturnsOriginalPayload(): void {
		$jwtKey = 'jwtkeybutlongenoughforsecurityasthisisnowimportant';
		$this->config->method('getSystemValueString')->willReturnMap([
			['gss.jwt.key', '', $jwtKey],
		]);

		$service = $this->getInstance();
		$jwt = JWT::encode(['uid' => 'regularuser', 'token' => 'sometoken', 'exp' => time() + 300], $jwtKey, 'HS256');

		$decoded = $service->decodePayload($jwt);

		$this->assertSame('regularuser', $decoded['uid']);
		$this->assertSame('sometoken', $decoded['token']);
	}
}

/**
 * Instantiated by GlobalScaleService via Server::get($className), which
 * autowires arbitrary classes that aren't explicitly registered - a plain
 * constructor-less class is enough, no test double registration needed.
 * Deliberately does not implement IUserDiscoveryModule: GlobalScaleService
 * only duck-types against it (a docblock hint, no instanceof check), and
 * declaring the interface here trips up PHPUnit's test-file discovery.
 */
class FakeUserDiscoveryModule {
	public static string $location = '';

	public function getLocation(array $data): string {
		return self::$location;
	}
}
