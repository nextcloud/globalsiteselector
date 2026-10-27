<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit;

use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\UserBackend;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserBackendTest extends TestCase {
	private IDBConnection&MockObject $db;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private IRootFolder&MockObject $rootFolder;
	private AuditLogService&MockObject $auditLogService;

	public function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);
	}

	private function getBackend(bool $userExists): UserBackend&MockObject {
		$backend = $this->getMockBuilder(UserBackend::class)
			->setConstructorArgs([
				$this->db,
				$this->createMock(ISession::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(IUserConfig::class),
				$this->groupManager,
				$this->userManager,
				$this->createMock(LoggerInterface::class),
				$this->rootFolder,
				$this->auditLogService,
			])
			->onlyMethods(['loadUser'])
			->getMock();
		$backend->method('loadUser')->willReturn($userExists);
		return $backend;
	}

	/**
	 * Setting up the home folder needs a real filesystem, the audit entry is written before it
	 */
	private function stopAtHomeSetup(): void {
		$this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('home setup'));
		$this->expectExceptionMessage('home setup');
	}

	public function testCreateUserWritesAuditLog(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('setValue')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);
		$this->db->method('getQueryBuilder')->willReturn($qb);
		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::AccountProvisioned, 'Account "%s" provisioned by global scale', [
				'userId' => 'alice',
			]);
		$this->stopAtHomeSetup();

		$this->getBackend(false)->createUserIfNotExists('alice', false);
	}

	public function testCreateExistingUserWritesNoAuditLog(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->auditLogService->expects($this->never())->method('log');
		$this->stopAtHomeSetup();

		$this->getBackend(true)->createUserIfNotExists('alice', false);
	}

	private function prepareGroups(array $currentGroups): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn($currentGroups);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->willReturn($this->createMock(IGroup::class));
	}

	public function testUpdateGroupsWritesAuditLog(): void {
		$this->prepareGroups(['staff', 'old']);
		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::UserGroupsSynchronized, 'Group memberships of "%s" synchronized by global scale provisioning', [
				'userId' => 'alice',
				'groupsAdded' => '["new","Équipe"]',
				'groupsRemoved' => '["old"]',
			]);

		$this->getBackend(true)->updateAttributes('alice', ['userData' => ['groups' => ['staff', 'admin', 'new', 'Équipe']]]);
	}

	public static function dataUpdateGroupsWithoutChange(): array {
		return [
			'same groups' => [['staff'], ['staff']],
			'only admin requested' => [['staff'], ['staff', 'admin']],
			'no groups sent' => [['staff'], null],
		];
	}

	#[DataProvider('dataUpdateGroupsWithoutChange')]
	public function testUpdateGroupsWithoutChangeWritesNoAuditLog(array $currentGroups, ?array $newGroups): void {
		$this->prepareGroups($currentGroups);
		$this->auditLogService->expects($this->never())->method('log');
		$userData = ($newGroups === null) ? [] : ['groups' => $newGroups];

		$this->getBackend(true)->updateAttributes('alice', ['userData' => $userData]);
	}
}
