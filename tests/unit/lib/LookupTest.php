<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\GlobalSiteSelector\Tests\Unit;

use OCA\GlobalSiteSelector\Lookup;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class LookupTest extends TestCase {
	private IClientService $httpClientService;
	private IConfig $config;
	private LoggerInterface $logger;
	private ICloudIdManager $cloudIdManager;

	public function setUp(): void {
		parent::setUp();

		$this->httpClientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
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
					$this->logger,
					$this->cloudIdManager,
					$this->config
				]
			)->onlyMethods($mockMethods)->getMock();
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
		$this->config->expects($this->any())->method('getSystemValueString')
			->with('lookup_server', '')->willReturn($lookupServerUrl);

		$lookup = $this->getInstance(['queryLookupServer', 'getUserLocation']);
		$lookup->expects($this->any())->method('queryLookupServer')
			->with('uid')->willReturn($lookupServerResult);
		if (isset($lookupServerResult['federationId'])) {
			$lookup->expects($this->any())->method('getUserLocation')->with($lookupServerResult['federationId'])
				->willReturn($userLocation);
		}

		$userId = 'uid';
		$result = $lookup->search($userId);

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

	// method is not private anymore
	// maybe rewrite test with different 'gss.username_format'
	//
	//	public function testGetUserLocation() {
	//		$lookup = $this->getInstance();
	//		$cloudId = $this->createMock(ICloudId::class);
	//		$federationId = 'user@nextcloud.com';
	//		$location = 'nextcloud.com';
	//
	//		$cloudId->expects($this->once())->method('getRemote')
	//			->willReturn($location . '/');
	//
	//		$this->cloudIdManager->expects($this->once())->method('resolveCloudId')
	//			->with($federationId)
	//		->willReturn($cloudId);
	//
	//		$result = $this->invokePrivate($lookup, 'getUserLocation', ['user@nextcloud.com']);
	//
	//		$this->assertSame($location, $result);
	//	}
}
