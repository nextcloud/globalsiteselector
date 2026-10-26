<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit\Service;

use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Tests\AuditLogHelper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Test\TestCase;

class AuditLogServiceTest extends TestCase {
	public function testLog(): void {
		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with($this->callback(function (CriticalActionPerformedEvent $event): bool {
				$this->assertSame('App password %s created for "%s"', $event->getLogMessage());
				$this->assertSame(['tokenId' => 42, 'userId' => 'alice'], $event->getParameters());
				$this->assertFalse($event->getObfuscateParameters());
				$this->assertTrue(AuditLogHelper::hasOperation($event, 'globalsiteselector.app_password.created'));
				return true;
			}));

		$service = new AuditLogService($eventDispatcher);
		$service->log(Operation::AppPasswordCreated, 'App password %s created for "%s"', ['tokenId' => 42, 'userId' => 'alice']);
	}
}
