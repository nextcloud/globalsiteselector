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
use OCA\GlobalSiteSelector\UserDiscoveryModules\IUserDiscoveryModule;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\Authentication\IApacheBackend;
use OCP\HintException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ICrypto;
use OCP\Server;
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
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * find users location and redirect them to the right server
	 *
	 * @param string $uid
	 * @param string|null $password
	 * @param IApacheBackend|null $backend
	 *
	 * @throws ContainerExceptionInterface
	 * @throws HintException
	 * @throws NotFoundExceptionInterface
	 */
	public function handleLoginRequest(
		string $uid,
		?string $password,
		?IApacheBackend $backend = null,
	): void {
		$this->logger->debug(
			'start handle login request',
			[
				'uid' => $uid,
				'backend' => ($backend === null) ? null : get_class($backend)
			]
		);

		/** ignoring request from slave with valid jwt */
		if ($this->isValidJwt($this->request->getParam('jwt', ''))) {
			$this->logger->debug('ignore request with valid jwt');

			return;
		}

		$target = (!$this->request->getPathInfo()) ? '/' : '/index.php' . $this->request->getPathInfo();
		$this->logger->debug('handleLoginRequest: target is: ' . $target);

		$options = [
			'target' => $target,
			'params' => $this->request->getParams(),
		];

		$discoveryData = [];

		$userDiscoveryModule = $this->config->getSystemValueString('gss.user.discovery.module', '');
		$this->logger->debug('handleLoginRequest: discovery module is: ' . $userDiscoveryModule);

		$redirectUrl = $this->request->getParam('redirect_url', '');

		$isSamlOrOidc = false;
		if (class_exists('\OCA\User_SAML\UserBackend')
			&& $backend instanceof \OCA\User_SAML\UserBackend) {
			$isSamlOrOidc = true;
			$this->logger->debug('handleLoginRequest: backend is SAML');

			$options['backend'] = 'saml';
			$options['userData'] = $backend->getUserData();
			$uid = $options['userData']['formatted']['uid'];
			$password = '';
			$discoveryData['saml'] = $options['userData']['raw'];
			// we only send the formatted user data to the slave
			$options['userData'] = $options['userData']['formatted'];
			$options['saml'] = [
				'idp' => $this->session->get('user_saml.Idp')
			];

			$this->logger->debug('handleLoginRequest: backend is SAML.', ['options' => $options]);
		} elseif (class_exists('\OCA\UserOIDC\Controller\LoginController')
			&& class_exists('\OCA\UserOIDC\User\Backend')
			&& $backend instanceof \OCA\UserOIDC\User\Backend
			&& method_exists($backend, 'getUserData')
		) {
			// TODO double check if we need to behave the same when saml or oidc is used
			$isSamlOrOidc = true;
			$this->logger->debug('handleLoginRequest: backend is OIDC');

			$options['backend'] = 'oidc';
			$options['userData'] = $backend->getUserData();
			$uid = $options['userData']['formatted']['uid'];
			$password = '';
			$discoveryData['oidc'] = $options['userData']['raw'];
			// we only send the formatted user data to the slave
			$options['userData'] = $options['userData']['formatted'];
			$options['oidc'] = [
				'providerId' => $this->session->get(\OCA\UserOIDC\Controller\LoginController::PROVIDERID)
			];
			// 	TODO: switch 'oidc.redirect' to \OCA\UserOIDC\Controller\LoginController::REDIRECT_AFTER_LOGIN once switched to public
			$state = $this->request->getParam('state') ?? '';
			$sessionKeySuffix = ($state !== '') ? '-' . $state : '';
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

			$this->logger->debug('handleLoginRequest: backend is OIDC.', ['options' => $options]);
		} else {
			$this->logger->debug('handleLoginRequest: backend is not SAML or OIDC');
		}

		if ($this->isPath(['/login/flow', '/login/v2/flow'], $redirectUrl ?? '')) {
			$options['target'] = $redirectUrl;
			$this->logger->debug('handleLoginRequest: overriding target with slave flow path: ' . $options['target']);
		}

		$this->logger->debug('handleLoginRequest: uid is: ' . $uid);

		// let local account login, everyone else will redirected to a client
		$masterAdmins = $this->config->getSystemValue('gss.master.admin', []);     // old syntax
		$localAccounts = $this->config->getSystemValue('gss.master.accounts', []); // new one
		$masterAdmins = (is_array($masterAdmins)) ? $masterAdmins : [];
		$localAccounts = (is_array($localAccounts)) ? $localAccounts : [];

		if (in_array($uid, array_merge($masterAdmins, $localAccounts), true)) {
			$this->logger->debug('handleLoginRequest: this user is a local account so ignore');
			return;
		}

		// first ask the lookup server if we already know the user
		// is from SAML or OIDC, only search on userId, ignore email.
		$location = $this->queryLookupServer($uid, $isSamlOrOidc);
		$this->logger->debug('handleLoginRequest: location according to lookup server: ' . $location);

		// if not we fall-back to a initial user deployment method, if configured
		if (empty($location) && !empty($userDiscoveryModule)) {
			try {
				$this->logger->debug('handleLoginRequest: obtaining location from discovery module ' . $userDiscoveryModule);

				/** @var IUserDiscoveryModule $module */
				$module = Server::get($userDiscoveryModule);
				$location = $module->getLocation($discoveryData);
				$this->lookup->sanitizeUid($uid);

				$this->logger->debug(
					'handleLoginRequest: location according to discovery module: ' . $location
				);
			} catch (Exception $e) {
				$this->logger->warning(
					'could not load user discovery module: ' . $userDiscoveryModule,
					['exception' => $e->getMessage()]
				);
			}
		}

		if (!empty($location)) {
			$this->logger->debug(
				'handleLoginRequest: redirecting user: ' . $uid . ' to ' . $this->normalizeLocation($location)
			);

			$this->redirectUser($uid, $password, $this->normalizeLocation($location), $options);
		} else {
			$this->logger->debug('handleLoginRequest: Could not find location for account ' . $uid);

			throw new HintException('Unknown Account');
		}
	}

	/**
	 * format URL
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	protected function normalizeLocation($url) {
		if (substr($url, 0, 7) === 'http://' || substr($url, 0, 8) === 'https://') {
			return $url;
		}

		return $this->request->getServerProtocol() . '://' . $url;
	}

	/**
	 * search for the user and return the location of the user
	 *
	 * @param $uid
	 *
	 * @return string
	 */
	protected function queryLookupServer(string &$uid, bool $matchUid = false): string {
		return $this->lookup->search($uid, $matchUid);
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
		$isDirectWebDavAccess = strpos($requestUri, 'remote.php/webdav') !== false;
		$isDirectWebDavAccess = $isDirectWebDavAccess || strpos($requestUri, 'remote.php/dav') !== false;
		// direct webdav access with old client or general purpose webdav clients
		if ($isClient && $isDirectWebDavAccess) {
			$this->logger->debug('redirectUser: client direct webdav request');
			$redirectUrl = $location . '/remote.php/webdav/';
		} elseif ($isClient && !$isDirectWebDavAccess) {
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
		} else {
			$this->logger->debug('redirectUser: direct login so forward to target node');
			$jwt = $this->createJwt($uid, $password, $options);
			$redirectUrl = $location . '/index.php/apps/globalsiteselector/autologin?jwt=' . $jwt;
		}

		$this->logger->debug('redirectUser: redirecting to: ' . $redirectUrl);
		header('Location: ' . $redirectUrl, true, 302);
		die();
	}

	/**
	 * generate JWT
	 *
	 * @param string $uid
	 * @param string $password
	 * @param array $options
	 *
	 * @return string
	 */
	protected function createJwt($uid, $password, $options) {
		$token = [
			'uid' => $uid,
			'password' => $this->crypto->encrypt($password, $this->gss->getJwtKey()),
			'options' => json_encode($options),
			'exp' => time() + 300, // expires after 5 minutes
		];

		$jwt = JWT::encode($token, $this->gss->getJwtKey(), Application::JWT_ALGORITHM);

		return $jwt;
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
	 *
	 * @return string
	 */
	protected function buildBasicAuthUrl($url, $uid, $password) {
		if (strpos($url, 'http://') === 0) {
			$protocol = 'http://';
		} elseif (strpos($url, 'https://') === 0) {
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
			if (str_starts_with($path, $entry) || str_starts_with($path, '/index.php' . $entry)) {
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
