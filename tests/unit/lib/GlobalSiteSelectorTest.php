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


use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCP\IConfig;
use Test\TestCase;

class GlobalSiteSelectorTest extends TestCase {

	/** @var  IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var  GlobalSiteSelector */
	private $gss;

	public function setUp() {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->gss = new GlobalSiteSelector($this->config);
	}

	public function testGetMode() {
		$this->config->expects($this->once())->method('getSystemValue')
			->with('gss.mode', 'slave')->willReturn('result');

		$result = $this->gss->getMode();

		$this->assertSame('result', $result);
	}

	public function testGetJwtKey() {
		$this->config->expects($this->once())->method('getSystemValue')
			->with('gss.jwt.key', '')->willReturn('result');

		$result = $this->gss->getJwtKey();

		$this->assertSame('result', $result);
	}

	public function testGetMasterUrl() {
		$this->config->expects($this->once())->method('getSystemValue')
			->with('gss.master.url', '')->willReturn('result');

		$result = $this->gss->getMasterUrl();

		$this->assertSame('result', $result);
	}

	public function testGetLookupServerUrl() {
		$this->config->expects($this->once())->method('getSystemValue')
			->with('lookup_server', '')->willReturn('result');

		$result = $this->gss->getLookupServerUrl();

		$this->assertSame('result', $result);
	}

}
