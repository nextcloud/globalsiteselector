<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

use Exception;
use OC\Core\Controller\ClientFlowLoginV2Controller;
use OC\Core\Service\LoginFlowV2Service;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Exceptions\IsLocalAdminException;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\HintException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\ServerVersion;
use OCP\Util;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Master
 *
 * Handle all operations in master mode to redirect the users to their login server
 *
 * @package OCA\GlobalSiteSelector
 */
class Master {
	public function __construct(
		private readonly ISession $session,
		private readonly GlobalSiteSelector $gss,
		private readonly ICrypto $crypto,
		private readonly LoginFlowV2Service $loginFlowV2Service,
		private readonly ServerVersion $serverVersion,
		private readonly Lookup $lookup,
		private readonly IRequest $request,
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly GlobalScaleService $globalScaleService,
	) {
	}

	/**
	 * find users location and redirect them to the right server
	 *
	 *
	 * @throws ContainerExceptionInterface
	 * @throws HintException
	 * @throws NotFoundExceptionInterface
	 */
	public function handleLoginRequest(
		IUser $user,
		?string $password,
		bool $ignoreJwt = false,
	): void {
		$backend = $user->getBackend();
		$this->logger->debug(
			'start handle login request',
			[
				'uid' => $user->getUID(),
				'backend' => ($backend === null) ? null : $backend::class
			]
		);

		/** ignoring request from slave with valid jwt */
		if (!$ignoreJwt && $this->isValidJwt($this->request->getParam('jwt', ''))) {
			$this->logger->debug('ignore request with valid jwt');

			return;
		}

		// Use the entry script that actually handled this request (index.php,
		// remote.php, ...) instead of hardcoding index.php: this event also
		// fires for DAV/OCS requests served through remote.php, and hardcoding
		// index.php there produces a target URL that doesn't exist on the slave.
		$target = (!$this->request->getPathInfo()) ? '/' : $this->request->getScriptName() . $this->request->getPathInfo();
		$options = [
			'target' => $target,
			'params' => $this->request->getParams(),
		];

		$redirectUrl = $this->request->getParam('redirect_url', '');

		$ssoUserData = $this->globalScaleService->getSsoUserData($user);
		if ($ssoUserData !== null && $ssoUserData['backend'] === 'saml') {
			$this->logger->debug('handleLoginRequest: backend is SAML');

			$password = '';
			// we only send the formatted user data to the slave
			$options['backend'] = 'saml';
			$options['userData'] = $ssoUserData['formatted'];
			$options['saml'] = [
				'idp' => $this->session->get('user_saml.Idp')
			];
		} elseif ($ssoUserData !== null && $ssoUserData['backend'] === 'oidc') {
			$this->logger->debug('handleLoginRequest: backend is OIDC');

			$password = '';
			// we only send the formatted user data to the slave
			$options['backend'] = 'oidc';
			$options['userData'] = $ssoUserData['formatted'];
			$options['oidc'] = [
				// keep in sync with \OCA\UserOIDC\Controller\LoginController::PROVIDERID
				'providerId' => $this->session->get('oidc.providerid')
			];
			$state = $this->request->getParam('state') ?? '';
			$sessionKeySuffix = ($state !== '') ? '-' . $state : '';
			// keep in sync with \OCA\UserOIDC\Controller\LoginController::REDIRECT_AFTER_LOGIN
			$redirect = $this->session->get('oidc.redirect') ?? $this->session->get('oidc.redirect' . $sessionKeySuffix) ?? '/';
			$options['target'] = $this->forceRelativeUrl($redirect);

			// Fix: restore the slave flow path into options.target after all backend blocks.
			//
			// Application.php passes the slave flow path as redirect_url in the /login
			// query string, e.g. redirect_url=%2Findex.php%2Flogin%2Fv2%2Fflow%2FF
			//
			// By the time handleLoginRequest() fires, the current request is the OIDC
			// callback (/apps/user_oidc/code?state=...&code=...) with no redirect_url.
			// We recover it from oidc.redirect (stored in the session by UserOIDC before
			// the OIDC redirect). oidc.redirect is the full pre-OIDC request URL:
			//   https://master/index.php/login?redirect_url=%2Findex.php%2Flogin%2Fv2%2Fflow%2FF
			// We parse its query string to extract redirect_url = /index.php/login/v2/flow/F
			$oidcRedirect = (string)($this->session->get('oidc.redirect') ?? '');
			if ($oidcRedirect !== '') {
				parse_str(parse_url($oidcRedirect, PHP_URL_QUERY) ?? '', $oidcRedirectParams);
				$redirectUrl = $oidcRedirectParams['redirect_url'] ?? $redirectUrl;
			}
		} else {
			$this->logger->debug('handleLoginRequest: backend is not SAML or OIDC');
		}

		if ($this->isPath(['/login/flow', '/login/v2/flow'], $redirectUrl ?? '')) {
			$options['target'] = $redirectUrl;
			$this->logger->debug('handleLoginRequest: overriding target with slave flow path: ' . $options['target']);
		}

		try {
			$location = $this->globalScaleService->getSecondaryRemoteLocation($user);
		} catch (IsLocalAdminException) {
			return;
		}
		if ($location !== null) {
			$this->logger->debug(
				'handleLoginRequest: redirecting user: ' . $user->getUID() . ' to ' . $location
			);

			$this->redirectUser($user->getUID(), $password, $location, $options);
		} else {
			$this->logger->debug('handleLoginRequest: Could not find location for account ' . $user->getUID());

			throw new HintException('Unknown Account');
		}
	}

	/**
	 * redirect user to the right Nextcloud server
	 *
	 * @param string $uid
	 * @param string $password
	 * @param string $location
	 * @param array $options can contain additional parameters, e.g. from SAML
	 *
	 * @throws Exception
	 */
	protected function redirectUser($uid, $password, $location, array $options = []) {
		$isClient = $this->request->isUserAgent(
			[
				IRequest::USER_AGENT_CLIENT_IOS,
				IRequest::USER_AGENT_CLIENT_ANDROID,
				IRequest::USER_AGENT_CLIENT_DESKTOP,
				'/mirall|csyncoC/', // <-- Support also not compliant Desktop Clients
				'/^.*\(Android\)$/'
			]
		) || $this->isPath(['/login/flow/grant', '/login/v2/grant'], $options['target'] ?? '');

		$requestUri = $this->request->getRequestUri();

		// check for both possible direct webdav end-points
		$isDirectWebDavAccess = str_starts_with($requestUri, '/remote.php/webdav')
			|| str_starts_with($requestUri, '/remote.php/dav');

		$isDirectOCS = str_starts_with($requestUri, '/ocs/v2.php')
			|| str_starts_with($requestUri, '/ocs/v1.php');

		$authHeader = $this->request->getHeader('Authorization');
		$redirectWebDav = $this->appConfig->getValueBool(Application::APP_ID, ConfigLexicon::REDIRECT_WEBDAV);
		$authHeaderLower = strtolower($authHeader);
		// Basic (third-party WebDAV clients) as well as Bearer (OAuth2 app
		// tokens, e.g. from a GSS-unaware OIDC/OAuth2 client) can both be
		// forwarded as-is to the slave: neither depends on a browser session,
		// unlike the JWT-autologin bounce below.
		$hasForwardableAuth = $authHeader !== ''
			&& (str_starts_with($authHeaderLower, 'basic ') || str_starts_with($authHeaderLower, 'bearer '));
		$hasForwardableAuthDav = $redirectWebDav && $hasForwardableAuth;

		// default redirect status code; overridden below for the 307 forward.
		$statusCode = 302;

		// direct webdav access with old client or general purpose webdav clients
		if ($isClient && $isDirectWebDavAccess) {
			$this->logger->debug('redirectUser: client direct webdav request');
			$redirectUrl = $location . '/remote.php/webdav/';
		} elseif ($isClient) {
			$this->logger->debug('redirectUser: client request generating apptoken');
			$appToken = $this->getAppToken($location, $uid, $password, $options);

			$loginV2Token = $this->session->get(ClientFlowLoginV2Controller::TOKEN_NAME);
			if ($loginV2Token !== null && $location !== '') {
				$result = $this->loginFlowV2Service->flowDoneWithAppPassword($loginV2Token, $location, $uid, $appToken);
				echo $this->handleFlowDone($result)->render();
				die();
			} else {
				// fallback to v1
				$redirectUrl = 'nc://login/server:' . $location . '&user:' . urlencode($uid) . '&password:' . urlencode($appToken);
			}
		} elseif ($isDirectWebDavAccess && $hasForwardableAuthDav) {
			// Third-party WebDAV clients authenticated with HTTP Basic or
			// Bearer (curl, rclone, davfs2, sabre/dav based clients, OAuth2
			// clients, generic DAV consumers, etc.): forward the request as-is
			// to the slave with a 307 (RFC 9110 §15.4.8) so that PUT,
			// PROPFIND, MKCOL, DELETE, COPY and MOVE are not downgraded to
			// GET, and the original request URI is preserved end-to-end. The
			// client re-issues the same request to the slave, including the
			// Authorization header it already presented to the master.
			$this->logger->debug('redirectUser: third-party webdav request with forwardable auth, forwarding with 307');
			$redirectUrl = rtrim($location, '/') . $requestUri;
			$statusCode = 307;
		} elseif ($isDirectOCS) {
			$redirectUrl = rtrim($location, '/') . $requestUri;
			$statusCode = 307;
		} else {
			$this->logger->debug('redirectUser: direct login so forward to target node');
			$jwt = $this->createJwt($uid, $password, $options);
			$redirectUrl = $location . '/index.php/apps/globalsiteselector/autologin?jwt=' . $jwt;
		}

		$this->logger->debug('redirectUser: redirecting to: ' . $redirectUrl);
		header('Location: ' . $redirectUrl, true, $statusCode);
		die();
	}

	/**
	 * generate JWT
	 *
	 * @param string $uid
	 * @param array $options
	 *
	 */
	protected function createJwt($uid, string $password, $options): string {
		if (!$this->gss->isJwtKeyValid()) {
			$this->logger->error(
				'gss.jwt.key is too short: HS256 requires at least '
				. GlobalSiteSelector::MIN_JWT_KEY_LENGTH . ' characters (per RFC 7518). '
				. 'Current key length: ' . strlen($this->gss->getJwtKey()) . '. '
				. 'Please update gss.jwt.key in config.php on all nodes.',
				['app' => Application::APP_ID]
			);
		}

		$token = [
			'uid' => $uid,
			'password' => $this->crypto->encrypt($password, $this->gss->getJwtKey()),
			'options' => json_encode($options),
			'exp' => time() + 300, // expires after 5 minutes
		];

		return JWT::encode($token, $this->gss->getJwtKey(), Application::JWT_ALGORITHM);
	}

	/**
	 * get app token from the server the user is located
	 *
	 * @param string $location
	 * @param string $uid
	 * @param string $password
	 * @param array $options
	 *
	 * @return string
	 * @throws Exception
	 */
	protected function getAppToken($location, $uid, $password, $options) {
		$client = $this->clientService->newClient();
		$jwt = $this->createJwt($uid, $password, $options);

		$response = $client->get(
			$location . '/ocs/v2.php/apps/globalsiteselector/v1/createapptoken',
			$this->lookup->configureClient(
				[
					'headers' => [
						'OCS-APIRequest' => 'true'
					],
					'verify' => !$this->config->getSystemValueBool('gss.selfsigned.allow', false),
					'query' => [
						'format' => 'json',
						'jwt' => $jwt
					]
				]
			)
		);

		$body = $response->getBody();

		$data = json_decode($body, true);
		$jsonErrorCode = json_last_error();
		if ($jsonErrorCode !== JSON_ERROR_NONE) {
			$info = 'getAppToken - Decoding the JSON failed ' . $jsonErrorCode . ' ' . json_last_error_msg();
			throw new Exception($info);
		}
		if (!isset($data['ocs']['data']['token'])) {
			$info = 'getAppToken - data doesn\'t contain token: ' . json_encode($data);
			throw new Exception($info);
		}

		return $data['ocs']['data']['token'];
	}

	/**
	 * add basic auth information to the URL
	 *
	 * @param string $url
	 * @param string $uid
	 * @param string $password
	 */
	protected function buildBasicAuthUrl($url, $uid, $password): string {
		if (str_starts_with($url, 'http://')) {
			$protocol = 'http://';
		} elseif (str_starts_with($url, 'https://')) {
			$protocol = 'https://';
		} else {
			// no protocol given, switch to https as default
			$url = 'https://' . $url;
			$protocol = 'https://';
		}

		$basicAuth = $protocol . $uid . ':' . $password . '@';

		return str_replace($protocol, $basicAuth, $url);
	}

	public function isValidJwt(?string $jwt): bool {
		if (($jwt ?? '') === '') {
			return false;
		}

		try {
			JWT::decode($jwt, new Key($this->gss->getJwtKey(), Application::JWT_ALGORITHM));

			return true;
		} catch (Exception $e) {
			$this->logger->debug('issue while decoding jwt', ['exception' => $e]);
		}

		return false;
	}

	private function forceRelativeUrl(string $url): string {
		if (str_starts_with($url, '/')) {
			return $url;
		}

		$parsed = parse_url($url);
		$url = $parsed['path'];
		$url .= (!array_key_exists('query', $parsed)) ? '' : '?' . $parsed['query'];
		$url .= (!array_key_exists('fragment', $parsed)) ? '' : '#' . $parsed['fragment'];

		return $url;
	}

	private function isPath(array $search, string $path): bool {
		if ($path === '') {
			return false;
		}

		foreach ($search as $entry) {
			if (str_starts_with($path, (string)$entry) || str_starts_with($path, '/index.php' . $entry)) {
				return true;
			}
		}

		return false;
	}

	private function handleFlowDone(bool $result): StandaloneTemplateResponse {
		if ($result) {
			// login flow v2 templates were moved in NC33
			if ($this->serverVersion->getMajorVersion() >= 33) {
				Util::addScript('core', 'login_flow');
				return new StandaloneTemplateResponse('core', 'loginflow', renderAs: 'guest');
			}

			return new StandaloneTemplateResponse('core', 'loginflowv2/done', renderAs: 'guest');
		}

		return new StandaloneTemplateResponse('core', '403', ['message' => 'Could not complete login'], 'guest');
	}
}
