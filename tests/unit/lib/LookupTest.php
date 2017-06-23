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


use OCA\GlobalSiteSelector\Lookup;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use Test\TestCase;

class LookupTest extends TestCase {

	/** @var  IClientService|\PHPUnit_Framework_MockObject_MockObject */
	private $httpClientService;

	/** @var  IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var  ILogger|\PHPUnit_Framework_MockObject_MockObject */
	private $logger;

	/** @var  ICloudIdManager | \PHPUnit_Framework_MockObject_MockObject */
	private $cloudIdManager;

	public function setUp() {
		parent::setUp();

		$this->httpClientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
	}

	/**
	 * get Lookup instance
	 *
	 * @param array $mockMethods
	 * @return Lookup|\PHPUnit_Framework_MockObject_MockObject
	 */
	private function getInstance(array $mockMethods = []) {
		return $this->getMockBuilder(Lookup::class)
			->setConstructorArgs(
				[
					$this->httpClientService,
					$this->config,
					$this->logger,
					$this->cloudIdManager
				]
			)->setMethods($mockMethods)->getMock();
	}

	/**
	 * @param string $lookupServerUrl
	 * @param string $lookupServerResult
	 * @param string $userLocation
	 * @param string $expected
	 *
	 * @dataProvider dataTestSearch
	 */
	public function testSearch($lookupServerUrl, $lookupServerResult, $userLocation, $expected) {
		$this->config->expects($this->any())->method('getSystemValue')
			->with('lookup_server', '')->willReturn($lookupServerUrl);

		$lookup = $this->getInstance(['queryLookupServer', 'getUserLocation']);
		$lookup->expects($this->any())->method('queryLookupServer')
			->with('uid')->willReturn($lookupServerResult);
		if (isset($lookupServerResult['federationId'])) {
			$lookup->expects($this->any())->method('getUserLocation')->with($lookupServerResult['federationId'])
				->willReturn($userLocation);
		}

		$result = $lookup->search('uid');

		$this->assertSame($expected, $result);

	}

	public function dataTestSearch() {
		return [
			['', [], 'location', ''],
			['', ['location' => 'https://nextcloud.com'], 'location', ''],
			['https://lookup.nextcloud.com', ['federationId' => 'user@https://nextcloud.com'], 'https://nextcloud.com', 'https://nextcloud.com'],
			['https://lookup.nextcloud.com', [], 'location', ''],
		];
	}

	public function testGetUserLocation() {
		$lookup = $this->getInstance();
		$cloudId = $this->createMock(ICloudId::class);
		$federationId = 'user@nextcloud.com';
		$location = 'nextcloud.com';

		$cloudId->expects($this->once())->method('getRemote')
			->willReturn($location . '/');

		$this->cloudIdManager->expects($this->once())->method('resolveCloudId')
			->with($federationId)
		->willReturn($cloudId);

		$result = $this->invokePrivate($lookup, 'getUserLocation', ['user@nextcloud.com']);

		$this->assertSame($location, $result);
	}

}
