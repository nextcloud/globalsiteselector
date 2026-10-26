<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\TokenHandler;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class TokenHandlerTest extends TestCase {
	private IProvider&MockObject $tokenProvider;
	private ISecureRandom&MockObject $random;
	private AuditLogService&MockObject $auditLogService;
	private TokenHandler $tokenHandler;

	public function setUp(): void {
		parent::setUp();

		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);
		$this->tokenHandler = new TokenHandler($this->tokenProvider, $this->random, $this->auditLogService);
	}

	public function testGenerateAppTokenWritesAuditLog(): void {
		$deviceToken = $this->createMock(IToken::class);
		$deviceToken->method('getId')->willReturn(42);
		$deviceToken->method('jsonSerialize')->willReturn(['id' => 42]);

		$this->random->method('generate')->willReturn('secret-app-password');
		$this->tokenProvider->expects($this->once())
			->method('generateToken')
			->with('secret-app-password', 'alice', 'alice', null, 'Client login', IToken::PERMANENT_TOKEN)
			->willReturn($deviceToken);

		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::AppPasswordCreated, 'App password %s created for "%s" via global scale client login', [
				'tokenId' => 42,
				'userId' => 'alice',
			]);

		$result = $this->tokenHandler->generateAppToken('alice');

		$this->assertSame('secret-app-password', $result['token']);
	}

	public function testGenerateAppTokenFailureWritesNoAuditLog(): void {
		$this->random->method('generate')->willReturn('secret-app-password');
		$this->tokenProvider->method('generateToken')->willThrowException(new \RuntimeException('db error'));
		$this->auditLogService->expects($this->never())->method('log');

		$this->expectException(\RuntimeException::class);
		$this->tokenHandler->generateAppToken('alice');
	}
}
