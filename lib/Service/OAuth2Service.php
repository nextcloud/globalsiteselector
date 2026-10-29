<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Service;

use Exception;
use OC\Authentication\Events\AppPasswordCreatedEvent;
use OC\Authentication\Token\IProvider;
use OC\Core\Controller\ClientFlowLoginController;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\OAuth2\Db\AccessToken;
use OCA\OAuth2\Db\AccessTokenMapper;
use OCA\OAuth2\Db\ClientMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

class OAuth2Service {

	public function __construct(
		private readonly ToolsService $toolsService,
		private readonly IRequest $request,
		private readonly IAppManager $appManager,
		private readonly ISession $session,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly IProvider $tokenProvider,
		private readonly ISecureRandom $random,
		private readonly AccessTokenMapper $accessTokenMapper,
		private readonly ICrypto $crypto,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ITimeFactory $timeFactory,
		private readonly IConfig $config,
		private readonly ClientMapper $clientMapper,
		private readonly AuditLogService $auditLogService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * return true if we stay on master
	 */
	public function manageOauth2(string $uid, string $target): bool {
		if (!$this->appManager->isAppLoaded('oauth2')
			|| !$this->appConfig->getValueBool(Application::APP_ID, ConfigLexicon::MANAGE_OAUTH2)) {
			return false;
		}

		// oauth2 authorization in initialized on master
		if ($this->toolsService->isPath(['/apps/oauth2/authorize'], $target)) {
			return true;
		}

		// if logged and requesting login flow grant, we redirect to client
		if ($this->toolsService->isPath(['/login/flow/grant'], $target)
			&& $this->userSession->isLoggedIn()) {
			$redirectUri = $this->finalizeOAuth2(
				$uid,
				$this->request->getParam('stateToken', ''),
				$this->request->getParam('clientIdentifier', ''),
				$this->request->getParam('providedRedirectUri', ''),
				$this->request->getHeader('user-agent'),
			);
			header('Location: ' . $redirectUri);
			die();
		}

		return false;
	}

	/**
	 * returns the uri to redirect the client to
	 * use of ReflectionProperty can be removed once we hit min-version=36
	 */
	public function finalizeOAuth2(
		string $uid,
		string $stateToken,
		string $clientIdentifier,
		string $providedRedirectUri,
		string $userAgent,
	): string {
		if (!$this->appManager->isAppLoaded('oauth2')
			|| !$this->appConfig->getValueBool(Application::APP_ID, ConfigLexicon::MANAGE_OAUTH2)) {
			$this->session->remove(ClientFlowLoginController::STATE_NAME);
			throw new \Exception('feature not available');
		}

		if (!$this->isValidToken($stateToken)) {
			$this->session->remove(ClientFlowLoginController::STATE_NAME);
			throw new \Exception('invalid token');
		}

		$this->session->remove(ClientFlowLoginController::STATE_NAME);

		$clientName = ($userAgent !== '') ? $userAgent : 'unknown';
		$client = false;
		if ($clientIdentifier !== '') {
			$client = $this->clientMapper->getByIdentifier($clientIdentifier);
			$clientName = ((new ReflectionProperty($client, 'name'))->isPublic()) ? $client->name : $client->getName();
		}

		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$generatedToken = $this->tokenProvider->generateToken(
			$token,
			$uid,
			$uid,
			null,
			$clientName,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);

		$oauthClientId = null;
		if ($client) {
			$oauthClientId = ((new ReflectionProperty($client, 'id'))->isPublic()) ? $client->id : $client->getId();
			$code = $this->random->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
			$accessToken = new AccessToken();
			if ((new ReflectionProperty($accessToken, 'clientId'))->isPublic()) {
				$accessToken->clientId = $oauthClientId;
				$accessToken->encryptedToken = $this->crypto->encrypt($token, $code);
				$accessToken->hashedCode = hash('sha512', $code);
				$accessToken->tokenId = $generatedToken->getId();
				$accessToken->codeCreatedAt = $this->timeFactory->now()->getTimestamp();
			} else {
				$accessToken->setClientId($oauthClientId);
				$accessToken->setEncryptedToken($this->crypto->encrypt($token, $code));
				$accessToken->setHashedCode(hash('sha512', $code));
				$accessToken->setTokenId($generatedToken->getId());
				$accessToken->setCodeCreatedAt($this->timeFactory->now()->getTimestamp());
			}
			$this->accessTokenMapper->insert($accessToken);

			$enableOcClients = $this->config->getSystemValueBool('oauth2.enable_oc_clients', false);

			$redirectUri = ((new ReflectionProperty($client, 'redirectUri'))->isPublic()) ? $client->redirectUri : $client->getRedirectUri();
			if ($enableOcClients && $redirectUri === 'http://localhost:*') {
				// Sanity check untrusted redirect URI provided by the client first
				if (!preg_match('/^http:\/\/localhost:[0-9]+$/', $providedRedirectUri)) {
					throw new Exception('fail sanity check on redirect uri');
				}

				$redirectUri = $providedRedirectUri;
			}

			if (parse_url($redirectUri, PHP_URL_QUERY)) {
				$redirectUri .= '&';
			} else {
				$redirectUri .= '?';
			}

			$redirectUri .= sprintf(
				'state=%s&code=%s',
				urlencode($this->session->get('oauth.state')),
				urlencode($code)
			);
			$this->session->remove('oauth.state');
		} else {
			$redirectUri = 'nc://login/server:' . $this->getServerPath() . '&user:' . urlencode($uid) . '&password:' . urlencode($token);
		}

		$this->eventDispatcher->dispatchTyped(new AppPasswordCreatedEvent($generatedToken));
		$message = ($oauthClientId === null)
			? 'Login flow granted for "%s": app password %s created'
			: 'Login flow granted for "%s": app password %s created for OAuth2 client %s';
		$this->auditLogService->log(Operation::LoginFlowGranted, $message, [
			'userId' => $uid,
			'tokenId' => $generatedToken->getId(),
			'oauthClientId' => $oauthClientId,
		]);

		return $redirectUri;
	}

	private function isValidToken(string $stateToken): bool {
		$currentToken = $this->session->get(ClientFlowLoginController::STATE_NAME);
		if (empty($stateToken) || !is_string($currentToken)) {
			return false;
		}
		return hash_equals($currentToken, $stateToken);
	}

	private function getServerPath(): string {
		$serverPostfix = '';
		$requestUri = $this->request->getRequestUri();

		if (str_contains($requestUri, '/index.php')) {
			$serverPostfix = substr($requestUri, 0, strpos($requestUri, '/index.php'));
		} elseif (str_contains($requestUri, '/login/flow')) {
			$serverPostfix = substr($requestUri, 0, strpos($requestUri, '/login/flow'));
		}

		$protocol = $this->request->getServerProtocol();

		if ($protocol !== 'https') {
			$xForwardedProto = $this->request->getHeader('X-Forwarded-Proto');
			$xForwardedSSL = $this->request->getHeader('X-Forwarded-Ssl');
			if ($xForwardedProto === 'https' || $xForwardedSSL === 'on') {
				$protocol = 'https';
			}
		}

		return $protocol . '://' . $this->request->getServerHost() . $serverPostfix;
	}
}
