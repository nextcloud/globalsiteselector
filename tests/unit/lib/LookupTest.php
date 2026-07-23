<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class LookupTest extends TestCase {
	private MockObject&IClientService $httpClientService;
	private MockObject&Iconfig $config;
	private MockObject&LoggerInterface $logger;
	private MockObject&ICloudIdManager $cloudIdManager;
	private MockObject&GlobalSiteSelector $gss;

	public function setUp(): void {
		parent::setUp();

		$this->httpClientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->gss = $this->createMock(GlobalSiteSelector::class);
		$this->cloudIdManager = $this->createMock(ICloudIdManager::class);
	}

	/**
	 * Get Lookup instance
	 */
	private function getInstance(array $mockMethods = []): Lookup&MockObject {
		return $this->getMockBuilder(Lookup::class)
			->setConstructorArgs(
				[
					$this->httpClientService,
					$this->logger,
					$this->cloudIdManager,
					$this->gss,
					$this->config
				]
			)->onlyMethods($mockMethods)->getMock();
	}

	#[DataProvider('dataTestSearch')]
	public function testSearch(string $lookupServerUrl, array $lookupServerResult, string $userLocation, string $expected): void {
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

	public function dataTestSearch(): array {
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
