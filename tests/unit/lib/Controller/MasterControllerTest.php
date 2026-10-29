<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit\Controller;

use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Controller\MasterController;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Service\OAuth2Service;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class MasterControllerTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private GlobalSiteSelector&MockObject $gss;
	private GlobalScaleService&MockObject $globalScaleService;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private Lookup&MockObject $lookup;
	private AuditLogService&MockObject $auditLogService;
	private MasterController $controller;

	public function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->gss = $this->createMock(GlobalSiteSelector::class);
		$this->globalScaleService = $this->createMock(GlobalScaleService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->lookup = $this->createMock(Lookup::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);

		$this->controller = new MasterController(
			'globalsiteselector',
			$this->createMock(IRequest::class),
			$this->createMock(IURLGenerator::class),
			$this->userSession,
			$this->groupManager,
			$this->gss,
			$this->globalScaleService,
			$this->createMock(OAuth2Service::class),
			$this->config,
			$this->appConfig,
			$this->lookup,
			$this->auditLogService,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function prepareModerator(bool $isModerator): void {
		$moderator = $this->createMock(IUser::class);
		$moderator->method('getUID')->willReturn('moderator');
		$this->gss->method('isMaster')->willReturn(true);
		$this->appConfig->method('getAppValueBool')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($moderator);
		$this->config->method('getSystemValueString')->with('gss.moderators', '')->willReturn('moderators');
		$this->groupManager->method('isInGroup')->with('moderator', 'moderators')->willReturn($isModerator);
		$this->lookup->method('search')->willReturn('');
		$this->lookup->method('getInstances')->willReturn(['Node1.example']);
	}

	private function mockRemoteStatus(int $status): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$this->globalScaleService->method('sendToLocation')->willReturn($response);
	}

	public function testPrepAccountWritesAuditLog(): void {
		$this->prepareModerator(true);
		$this->mockRemoteStatus(Http::STATUS_OK);
		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::AccountPrepared, 'Account "%s" prepared on instance %s', [
				'userId' => 'alice',
				'instance' => 'node1.example',
			]);

		$response = $this->controller->prepAccount('alice', 'Node1.example', 'Alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testPrepAccountRemoteFailureWritesNoAuditLog(): void {
		$this->prepareModerator(true);
		$this->mockRemoteStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
		$this->auditLogService->expects($this->never())->method('log');

		$response = $this->controller->prepAccount('alice', 'node1.example');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testPrepAccountByNonModeratorWritesAuditLog(): void {
		$this->prepareModerator(false);
		$this->globalScaleService->expects($this->never())->method('sendToLocation');
		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::ModeratorAccessDenied, 'Global scale moderator access denied for "%s"', [
				'userId' => 'moderator',
			]);

		$response = $this->controller->prepAccount('alice', 'node1.example');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testPrepAccountWithDisabledFeatureWritesNoAuditLog(): void {
		$this->gss->method('isMaster')->willReturn(true);
		$this->appConfig->method('getAppValueBool')->willReturn(false);
		$this->auditLogService->expects($this->never())->method('log');

		$response = $this->controller->prepAccount('alice', 'node1.example');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testPrepAccountOnUnknownInstanceWritesNoAuditLog(): void {
		$this->prepareModerator(true);
		$this->globalScaleService->expects($this->never())->method('sendToLocation');
		$this->auditLogService->expects($this->never())->method('log');

		$response = $this->controller->prepAccount('alice', 'unknown.example');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
