<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Tests\Unit\Service;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Core\Controller\ClientFlowLoginController;
use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Service\OAuth2Service;
use OCA\GlobalSiteSelector\Service\ToolsService;
use OCA\OAuth2\Db\AccessTokenMapper;
use OCA\OAuth2\Db\Client;
use OCA\OAuth2\Db\ClientMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IToken as OCPIToken;
use OCP\Defaults;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IInitialStateService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Test\TestCase;

class OAuth2ServiceTest extends TestCase {
	private IRequest&MockObject $request;
	private IAppManager&MockObject $appManager;
	private ISession&MockObject $session;
	private IAppConfig&MockObject $appConfig;
	private IProvider&MockObject $tokenProvider;
	private ISecureRandom&MockObject $random;
	private AccessTokenMapper&MockObject $accessTokenMapper;
	private ICrypto&MockObject $crypto;
	private ITimeFactory&MockObject $timeFactory;
	private IConfig&MockObject $config;
	private ClientMapper&MockObject $clientMapper;
	private AuditLogService&MockObject $auditLogService;
	private OAuth2Service $service;

	public function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->session = $this->createMock(ISession::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->config = $this->createMock(IConfig::class);
		$this->clientMapper = $this->createMock(ClientMapper::class);
		$this->auditLogService = $this->createMock(AuditLogService::class);

		$this->service = new OAuth2Service(
			$this->createMock(ToolsService::class),
			$this->request,
			$this->appManager,
			$this->session,
			$this->createMock(IUserSession::class),
			$this->appConfig,
			$this->createMock(IInitialStateService::class),
			$this->createMock(Defaults::class),
			$this->tokenProvider,
			$this->random,
			$this->accessTokenMapper,
			$this->crypto,
			$this->createMock(IEventDispatcher::class),
			$this->timeFactory,
			$this->config,
			$this->createMock(IURLGenerator::class),
			$this->clientMapper,
			$this->auditLogService,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function enableFeature(bool $enabled = true): void {
		$this->appManager->method('isAppLoaded')->with('oauth2')->willReturn(true);
		$this->appConfig->method('getValueBool')->willReturn($enabled);
	}

	private function prepareValidSession(): void {
		$this->session->method('get')->willReturnMap([
			[ClientFlowLoginController::STATE_NAME, 'state-token'],
			['oauth.state', 'oauth-state'],
		]);
		$this->session->method('getId')->willReturn('session-id');

		$sessionToken = $this->createMock(OCPIToken::class);
		$sessionToken->method('getLoginName')->willReturn('alice');
		$sessionToken->method('getUID')->willReturn('alice');
		$this->tokenProvider->method('getToken')->with('session-id')->willReturn($sessionToken);
		$this->tokenProvider->method('getPassword')->willReturn('password');

		$generatedToken = $this->createMock(IToken::class);
		$generatedToken->method('getId')->willReturn(42);
		$this->tokenProvider->method('generateToken')->willReturn($generatedToken);
		$this->random->method('generate')->willReturn('secret');

		$this->request->method('getRequestUri')->willReturn('/index.php/apps/globalsiteselector/oauth2/login/flow');
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('master.example');
		$this->request->method('getHeader')->willReturn('');
	}

	private function createClient(int $id): Client {
		$client = new Client();
		if ((new ReflectionProperty($client, 'name'))->isPublic()) {
			$client->id = $id;
			$client->name = 'Client';
			$client->redirectUri = 'https://client.example/callback';
		} else {
			$client->setId($id);
			$client->setName('Client');
			$client->setRedirectUri('https://client.example/callback');
		}
		return $client;
	}

	public function testFinalizeWithoutClientWritesAuditLog(): void {
		$this->enableFeature();
		$this->prepareValidSession();
		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::LoginFlowGranted, 'Login flow granted for "%s": app password %s created', [
				'userId' => 'alice',
				'tokenId' => 42,
				'oauthClientId' => null,
			]);

		$this->service->finalizeOAuth2('state-token', '', '', 'Desktop client');
	}

	public function testFinalizeWithClientWritesAuditLog(): void {
		$this->enableFeature();
		$this->prepareValidSession();
		$this->clientMapper->method('getByIdentifier')->with('client-identifier')->willReturn($this->createClient(7));
		$this->crypto->method('encrypt')->willReturn('encrypted');
		$this->timeFactory->method('now')->willReturn(new \DateTimeImmutable());
		$this->config->method('getSystemValueBool')->willReturn(false);
		$this->accessTokenMapper->expects($this->once())->method('insert');
		$this->auditLogService->expects($this->once())
			->method('log')
			->with(Operation::LoginFlowGranted, 'Login flow granted for "%s": app password %s created for OAuth2 client %s', [
				'userId' => 'alice',
				'tokenId' => 42,
				'oauthClientId' => 7,
			]);

		$this->service->finalizeOAuth2('state-token', 'client-identifier', '', 'Desktop client');
	}

	public function testFinalizeWithInvalidStateTokenWritesNoAuditLog(): void {
		$this->enableFeature();
		$this->prepareValidSession();
		$this->tokenProvider->expects($this->never())->method('generateToken');
		$this->auditLogService->expects($this->never())->method('log');

		try {
			$this->service->finalizeOAuth2('wrong-token', '', '', 'Desktop client');
			$this->fail('Expected exception');
		} catch (\Exception $e) {
			$this->assertSame('invalid token', $e->getMessage());
		}
	}

	public function testFinalizeWithFeatureDisabledWritesNoAuditLog(): void {
		$this->enableFeature(false);
		$this->tokenProvider->expects($this->never())->method('generateToken');
		$this->auditLogService->expects($this->never())->method('log');

		try {
			$this->service->finalizeOAuth2('state-token', '', '', 'Desktop client');
			$this->fail('Expected exception');
		} catch (\Exception $e) {
			$this->assertSame('feature not available', $e->getMessage());
		}
	}
}
