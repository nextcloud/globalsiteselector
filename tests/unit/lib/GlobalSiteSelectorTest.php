<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\GlobalSiteSelector\Tests\Unit;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCP\IConfig;
use Test\TestCase;

class GlobalSiteSelectorTest extends TestCase {
	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var GlobalSiteSelector */
	private $gss;

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->gss = new GlobalSiteSelector($this->config);
	}

	public function testGetMode() {
		$this->config->expects($this->once())->method('getSystemValueString')
			->with('gss.mode', 'slave')->willReturn('result');

		$result = $this->gss->getMode();

		$this->assertSame('result', $result);
	}

	public function testGetJwtKey() {
		$this->config->expects($this->once())->method('getSystemValueString')
			->with('gss.jwt.key', '')->willReturn('result');

		$result = $this->gss->getJwtKey();

		$this->assertSame('result', $result);
	}

	public function testGetMasterUrl() {
		$this->config->expects($this->once())->method('getSystemValueString')
			->with('gss.master.url', '')->willReturn('result');

		$result = $this->gss->getMasterUrl();

		$this->assertSame('result', $result);
	}

	public function testGetLookupServerUrl() {
		$this->config->expects($this->once())->method('getSystemValueString')
			->with('lookup_server', '')->willReturn('result');

		$result = $this->gss->getLookupServerUrl();

		$this->assertSame('result', $result);
	}
}
