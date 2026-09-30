<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Service;

use Exception;
use OC\Authentication\Events\AppPasswordCreatedEvent;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OC\Core\Controller\ClientFlowLoginController;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\OAuth2\Db\AccessToken;
use OCA\OAuth2\Db\AccessTokenMapper;
use OCA\OAuth2\Db\ClientMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IToken;
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
use OCP\Util;
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
		private readonly IInitialStateService $initialStateService,
		private readonly Defaults $defaults,
		private readonly IProvider $tokenProvider,
		private readonly ISecureRandom $random,
		private readonly AccessTokenMapper $accessTokenMapper,
		private readonly ICrypto $crypto,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ITimeFactory $timeFactory,
		private readonly IConfig $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly ClientMapper $clientMapper,
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

		// we emulate login flow grant
		if ($this->toolsService->isPath(['/login/flow/grant'], $target)
			&& $this->userSession->isLoggedIn()) {
			echo $this->handleFlowGrant(
				$this->request->getParam('stateToken', ''),
				$this->request->getParam('clientIdentifier', ''),
				(int)$this->request->getParam('direct', 0),
				$this->request->getParam('providedRedirectUri', ''),
				$this->request->getHeader('user-agent'),
				$uid,
			)->render();
			die();
		}

		return false;
	}

	/**
	 * use of ReflectionProperty can be removed once we hit min-version=36
	 */
	private function handleFlowGrant(
		string $stateToken,
		string $clientIdentifier,
		int $direct,
		string $providedRedirectUri,
		string $userAgent,
		string $userId,
	): Response {
		if (!$this->isValidToken($stateToken)) {
			$this->logger->warning('State token does not match');
			return new StandaloneTemplateResponse(
				'core',
				'403',
				['message' => 'State token does not match'],
				'guest',
				Http::STATUS_FORBIDDEN
			);
		}

		$clientName = ($userAgent !== '') ? $userAgent : 'unknown';

		$client = null;
		if ($clientIdentifier !== '') {
			$client = $this->clientMapper->getByIdentifier($clientIdentifier);
			$clientName = ((new ReflectionProperty($client, 'name'))->isPublic()) ? $client->name : $client->getName();
		}

		$csp = new ContentSecurityPolicy();
		if ($client) {
			$csp->addAllowedFormActionDomain(((new ReflectionProperty($client, 'redirectUri'))->isPublic()) ? $client->redirectUri : $client->getRedirectUri());
		} else {
			$csp->addAllowedFormActionDomain('nc://*');
		}

		$this->initialStateService->provideInitialState('core', 'loginFlowState', 'grant');
		$this->initialStateService->provideInitialState('core', 'loginFlowGrant', [
			// the last step is host by the app instead of core to keep current session active
			'actionUrl' => $this->urlGenerator->linkToRouteAbsolute('globalsiteselector.Master.finalizeOAuthFlow'),
			'client' => $clientName,
			'clientIdentifier' => $clientIdentifier,
			'instanceName' => $this->defaults->getName(),
			'stateToken' => $stateToken,
			'serverHost' => $this->getServerPath(),
			'oauthState' => $this->session->get('oauth.state'),
			'direct' => $direct,
			'providedRedirectUri' => $providedRedirectUri,
			'userDisplayName' => $userId,
			'userId' => $userId,
		]);

		// we need to load basic scripts
		Util::addScript('core', 'common');
		Util::addScript('core', 'main');
		Util::addTranslations('core');
		Util::addScript('core', 'login_flow');
		$response = new TemplateResponse('core', 'loginflow', renderAs: 'guest');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * use of ReflectionProperty can be removed once we hit min-version=36
	 */
	public function finalizeOAuth2(
		string $stateToken,
		string $clientIdentifier,
		string $providedRedirectUri,
		string $userAgent,
	): Response {
		if (!$this->appManager->isAppLoaded('oauth2')
			|| !$this->appConfig->getValueBool(Application::APP_ID, ConfigLexicon::MANAGE_OAUTH2)) {
			throw new \Exception('feature not available');
		}

		if (!$this->isValidToken($stateToken)) {
			$this->session->remove(ClientFlowLoginController::STATE_NAME);
			throw new \Exception('invalid token');
		}

		$this->session->remove(ClientFlowLoginController::STATE_NAME);

		$sessionId = $this->session->getId();
		$sessionToken = $this->tokenProvider->getToken($sessionId);
		$loginName = $sessionToken->getLoginName();
		$uid = $sessionToken->getUID();
		if ($uid === '') {
			throw new \Exception('missing uid');
		}

		try {
			$password = $this->tokenProvider->getPassword($sessionToken, $sessionId);
		} catch (PasswordlessTokenException) {
			$password = null;
		}

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
			$loginName,
			$password,
			$clientName,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);

		if ($client) {
			$code = $this->random->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
			$accessToken = new AccessToken();
			if ((new ReflectionProperty($accessToken, 'clientId'))->isPublic()) {
				$accessToken->clientId = ((new ReflectionProperty($client, 'id'))->isPublic()) ? $client->id : $client->getId();
				$accessToken->encryptedToken = $this->crypto->encrypt($token, $code);
				$accessToken->hashedCode = hash('sha512', $code);
				$accessToken->tokenId = $generatedToken->getId();
				$accessToken->codeCreatedAt = $this->timeFactory->now()->getTimestamp();
			} else {
				$accessToken->setClientId(((new ReflectionProperty($client, 'id'))->isPublic()) ? $client->id : $client->getId());
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
			$redirectUri = 'nc://login/server:' . $this->getServerPath() . '&user:' . urlencode($loginName) . '&password:' . urlencode($token);

			// Clear the token from the login here
			$this->tokenProvider->invalidateToken($sessionId);
		}

		$this->eventDispatcher->dispatchTyped(new AppPasswordCreatedEvent($generatedToken));

		return new RedirectResponse($redirectUri);
	}

	private function isValidToken(string $stateToken): bool {
		$currentToken = $this->session->get(ClientFlowLoginController::STATE_NAME);
		if (!is_string($currentToken)) {
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
