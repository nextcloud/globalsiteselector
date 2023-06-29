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


namespace OCA\GlobalSiteSelector\Tests\Unit;

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Master;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\IAppContainer;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class MasterTest extends TestCase {
	/** @var  GlobalSiteSelector|\PHPUnit_Framework_MockObject_MockObject */
	private $gss;

	/** @var  ICrypto|\PHPUnit_Framework_MockObject_MockObject */
	private $crypto;

	/** @var  Lookup|\PHPUnit_Framework_MockObject_MockObject */
	private $lookup;

	/** @var  IRequest|\PHPUnit_Framework_MockObject_MockObject */
	private $request;

	/** @var  IClientService | \PHPUnit_Framework_MockObject_MockObject */
	private $clientService;

	/** @var IConfig | \PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var \PHPUnit_Framework_MockObject_MockObject|LoggerInterface */
	private $logger;

	/** @var \PHPUnit_Framework_MockObject_MockObject|IAppContainer */
	private $container;

	/** @var ISession | \PHPUnit_Framework_MockObject_MockObject */
	private $session;

	public function setUp(): void {
		parent::setUp();

		$this->gss = $this->getMockBuilder(GlobalSiteSelector::class)
			->disableOriginalConstructor()->getMock();
		$this->crypto = $this->createMock(ICrypto::class);
		$this->lookup = $this->getMockBuilder(Lookup::class)
			->disableOriginalConstructor()->getMock();
		$this->request = $this->createMock(IRequest::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(IAppContainer::class);
		$this->session = $this->createMock(ISession::class);
	}

	/**
	 * @param array $mockMethods
	 * @return Master|\PHPUnit_Framework_MockObject_MockObject
	 */
	private function getInstance(array $mockMethods = []) {
		return $this->getMockBuilder(Master::class)
			->setConstructorArgs(
				[
					$this->session,
					$this->gss,
					$this->crypto,
					$this->lookup,
					$this->request,
					$this->clientService,
					$this->config,
					$this->logger
				]
			)->setMethods($mockMethods)->getMock();
	}

	public function testHandleLoginRequest() {
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

	public function testHandleLoginRequestException() {
		$location = '';
		$master = $this->getInstance(['queryLookupServer', 'redirectUser']);
		$master->expects($this->once())->method('queryLookupServer')
			->willReturn($location);

		$this->expectException(HintException::class);
		$master->expects($this->never())->method('redirectUser');
		$master->handleLoginRequest('user', 'password');
	}


	public function testCreateJWT() {
		$uid = 'user1';
		$plainPassword = 'password';
		$encryptedPassword = 'password-encrypted';
		$options = ['foo' => 'bar'];
		$jwtKey = 'jwtkey';

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
	 *
	 * @param string $url
	 * @param string $uid
	 * @param string $password
	 * @param string $expected
	 */
	public function testBuildBasicAuthUrl($url, $uid, $password, $expected) {
		$master = $this->getInstance();
		$result = $this->invokePrivate($master, 'buildBasicAuthUrl', [$url, $uid, $password]);
		$this->assertSame($expected, $result);
	}

	public function dataTestBuildBasicAuthUrl() {
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
	public function testNormalizeLocation($url, $expected) {
		$master = $this->getInstance();
		$this->request->expects($this->any())->method('getServerProtocol')->willReturn('https');
		$result = $this->invokePrivate($master, 'normalizeLocation', [$url]);
		$this->assertSame($expected, $result);
	}

	public function dataTestNormalizeLocation() {
		return [
			['localhost/nextcloud', 'https://localhost/nextcloud'],
			['https://localhost/nextcloud', 'https://localhost/nextcloud'],
			['http://localhost/nextcloud', 'http://localhost/nextcloud'],

		];
	}
}
