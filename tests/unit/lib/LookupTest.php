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

	public function setUp() {
		parent::setUp();

		$this->httpClientService = $this->createMock(IClientService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(ILogger::class);
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
					$this->logger
				]
			)->setMethods($mockMethods)->getMock();
	}

	/**
	 * @param string $location
	 * @param string $lookupServerResult
	 * @param string $expected
	 *
	 * @dataProvider dataTestSearch
	 */
	public function testSearch($location, $lookupServerResult, $expected) {
		$this->config->expects($this->any())->method('getSystemValue')
			->with('lookup_server', '')->willReturn($location);

		$lookup = $this->getInstance(['queryLookupServer']);
		$lookup->expects($this->any())->method('queryLookupServer')
			->with('uid')->willReturn($lookupServerResult);

		$this->assertSame($expected, $lookup->search('uid'));

	}

	public function dataTestSearch() {
		return [
			['', [], ''],
			['', ['location' => 'https://nextcloud.com'], ''],
			['https://lookup.nextcloud.com', ['location' => 'https://nextcloud.com'], 'https://nextcloud.com'],
			['https://lookup.nextcloud.com', [], ''],
		];
	}

}
