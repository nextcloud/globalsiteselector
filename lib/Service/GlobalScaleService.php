<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Service;

use Exception;
use JsonException;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\Exceptions\IsLocalAdminException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\UserDiscoveryModules\IUserDiscoveryModule;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\GlobalScale\IGlobalScaleService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Security\ISecureRandom;
use OCP\Server;
use Psr\Log\LoggerInterface;

trait TGlobalScaleService {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly IConfig $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly ISecureRandom $secureRandom,
		private readonly GlobalSiteSelector $gss,
		private readonly Lookup $lookup,
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
		private readonly IUserConfig $userConfig,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * return local global scale identity token
	 * if none set yet, generate it
	 */
	public function getLocalToken(): string {
		if (!$this->appConfig->hasKey(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN)) {
			$this->appConfig->setValueString(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN, $this->secureRandom->generate(5, 'abcdefghijklmnopqrstuvwxyz0123456789'));
		}

		return $this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN);
	}

	/**
	 * return local address as known by lus
	 */
	public function getLocalAddress(): ?string {
		return $this->getAddressFromToken($this->getLocalToken());
	}

	/**
	 * confirm a specific global scale token identify local instance
	 */
	public function isLocalToken(string $token): bool {
		return ($this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN) === $token);
	}

	/**
	 * confirm that a url (or a host) is related to local instance
	 */
	public function isLocalAddress(string $address): bool {
		if (str_contains($address, '://')) {
			$address = parse_url($address, PHP_URL_HOST);
		}
		return ($this->getLocalAddress() === $address);
	}

	/**
	 * get global scale identity token from each instance of the global scale
	 */
	public function refreshTokenFromGlobalScale(): void {
		if (!$this->gss->isSlave()) {
			return;
		}

		foreach ($this->lookup->getInstances() as $address) {
			$this->refreshTokenFromAddress($address);
		}
	}

	/**
	 * request global scale token from a remote instance using public discovery and store it in local cache
	 */
	public function refreshTokenFromAddress(string $address): void {
		if (!$this->gss->isSlave()) {
			return;
		}

		if (str_contains($address, '://')) {
			$address = parse_url($address, PHP_URL_HOST);
		}

		$token = $this->getRemotePublicDiscovery($address)['token'] ?? '';
		if ($token === '' || strlen((string)$token) < 5) {
			return;
		}

		$tokens = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS);
		if (($tokens[$address] ?? '') === $token) {
			return;
		}

		$tokens[$address] = $token;
		$this->appConfig->setValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS, $tokens);
	}

	/**
	 * get address from a global scale token
	 */
	public function getAddressFromToken(string $token): ?string {
		$tokens = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS);
		$address = array_search($token, $tokens, true);
		if (!$address) {
			return null;
		}
		return $address;
	}

	/**
	 * returns global scale token from a specific address
	 */
	public function getTokenFromAddress(string $address): ?string {
		$tokens = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS);
		return $tokens[$address] ?? null;
	}

	/**
	 * returns discovery data from a remote address
	 */
	public function getRemotePublicDiscovery(string $address): array {
		return $this->requestGssOcs($address, 'Slave.discovery');
	}

	/**
	 * get data from a remote globalsiteselector ocs endpoint.
	 *
	 * @param string $address remote global scale instance
	 * @param string $route route name to the ocs endpoint
	 * @param array $data added to the request
	 * @param int $responseCode contains the response code from the request
	 *
	 * @return array decoded version of the json response
	 */
	public function requestGssOcs(string $address, string $route, array $data = [], int &$responseCode = 0): array {
		$client = $this->clientService->newClient();
		try {
			$response = $client->get(
				'https://' . $address . parse_url($this->urlGenerator->linkToOCSRouteAbsolute('globalsiteselector.' . $route), PHP_URL_PATH),
				[
					'headers' => ['OCS-APIRequest' => 'true'],
					'verify' => !$this->config->getSystemValueBool('gss.selfsigned.allow', false),
					'query' => array_merge($data, ['format' => 'json'])
				]
			);
		} catch (Exception $e) {
			$this->logger->warning('could not reach remote gss ocs', ['exception' => $e]);
			return [];
		}

		try {
			$responseCode = $response->getStatusCode();
			return json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['ocs']['data'] ?? [];
		} catch (JsonException $e) {
			$this->logger->warning('could not decode json', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Return the formatted SAML/OIDC identity data for a user, if their account is
	 * backed by one of those backends.
	 *
	 * This is cached since this requires calling the backend's getUserData(),
	 * which reads from the current session and is not always available.
	 *
	 * @return array{backend: 'saml'|'oidc', formatted: array, raw: array}|null
	 */
	public function getSsoUserData(IUser $user): ?array {
		$uid = $user->getUID();

		$cached = $this->userConfig->getValueArray($uid, Application::APP_ID, ConfigLexicon::SSO_USER_DATA, [], lazy: true);
		if ($cached !== []) {
			return $cached;
		}

		$backend = $user->getBackend();
		$data = null;

		try {
			if (class_exists('\OCA\User_SAML\UserBackend')
				&& $backend instanceof \OCA\User_SAML\UserBackend) {
				$userData = $backend->getUserData();
				$data = ['backend' => 'saml', 'formatted' => $userData['formatted'], 'raw' => $userData['raw']];
			} elseif (class_exists('\OCA\UserOIDC\Controller\LoginController')
				&& class_exists('\OCA\UserOIDC\User\Backend')
				&& $backend instanceof \OCA\UserOIDC\User\Backend
				&& method_exists($backend, 'getUserData')
			) {
				$userData = $backend->getUserData();
				$data = ['backend' => 'oidc', 'formatted' => $userData['formatted'], 'raw' => $userData['raw']];
			}
		} catch (Exception $e) {
			$this->logger->debug('getSsoUserData: could not read SAML/OIDC session data for ' . $uid, ['exception' => $e]);
			return null;
		}

		if ($data !== null) {
			$this->userConfig->setValueArray($uid, Application::APP_ID, ConfigLexicon::SSO_USER_DATA, $data, lazy: true);
		}

		return $data;
	}

	/**
	 * Find the secondary (slave) location for a user, if any.
	 *
	 * @throws IsLocalAdminException If the user is one of the local admin and shouldn't be redirected
	 */
	public function getSecondaryRemoteLocation(IUser $user): ?string {
		$uid = $user->getUID();
		$discoveryData = [];
		$isSamlOrOidc = false;

		$ssoUserData = $this->getSsoUserData($user);
		if ($ssoUserData !== null) {
			$isSamlOrOidc = true;
			$this->logger->debug('getSecondaryRemoteLocation: backend is ' . $ssoUserData['backend']);

			$uid = $ssoUserData['formatted']['uid'];
			$discoveryData[$ssoUserData['backend']] = $ssoUserData['raw'];
		} else {
			$this->logger->debug('getSecondaryRemoteLocation: backend is not SAML or OIDC');
		}

		$this->logger->debug('getSecondaryRemoteLocation: uid is: ' . $uid);

		// let local account login, everyone else will be redirected to a client
		$masterAdmins = $this->config->getSystemValue('gss.master.admin', []);     // old syntax
		$localAccounts = $this->config->getSystemValue('gss.master.accounts', []); // new one
		$masterAdmins = (is_array($masterAdmins)) ? $masterAdmins : [];
		$localAccounts = (is_array($localAccounts)) ? $localAccounts : [];

		if (in_array($uid, array_merge($masterAdmins, $localAccounts), true)) {
			$this->logger->debug('getSecondaryRemoteLocation: this user is a local account so ignore');
			throw new IsLocalAdminException();
		}

		// first ask the lookup server if we already know the user
		// is from SAML or OIDC, only search on userId, ignore email.
		$location = $this->queryLookupServer($uid, $isSamlOrOidc);
		$this->logger->debug('getSecondaryRemoteLocation: location according to lookup server: ' . $location);

		// if not we fall back to an initial user deployment method, if configured
		$userDiscoveryModule = $this->config->getSystemValueString('gss.user.discovery.module', '');
		if (empty($location) && !empty($userDiscoveryModule)) {
			try {
				$this->logger->debug('getSecondaryRemoteLocation: obtaining location from discovery module ' . $userDiscoveryModule);

				/** @var IUserDiscoveryModule $module */
				$module = Server::get($userDiscoveryModule);
				$location = $module->getLocation($discoveryData);
				$this->lookup->sanitizeUid($uid);

				$this->logger->debug(
					'getSecondaryRemoteLocation: location according to discovery module: ' . $location
				);
			} catch (Exception $e) {
				$this->logger->warning(
					'Could not load user discovery module: ' . $userDiscoveryModule,
					['exception' => $e->getMessage()]
				);
			}
		}

		if ($location === '') {
			return null;
		}

		return $this->normalizeLocation($location);
	}

	protected function queryLookupServer(string &$uid, bool $matchUid = false): string {
		return $this->lookup->search($uid, $matchUid);
	}

	/**
	 * @param non-empty-string $url
	 * @return non-empty-string
	 */
	protected function normalizeLocation(string $url): string {
		if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
			return $url;
		}

		return $this->request->getServerProtocol() . '://' . $url;
	}

	public function sendToSecondary(IUser $user, string $path, array $payload): string {
		$location = $this->getSecondaryRemoteLocation($user);
		if ($location === null) {
			throw new \Exception('Could not send message to secondary. No secondary location found for user with id: ' . $user->getUID());
		}

		if (!isset($payload['exp'])) {
			$payload['exp'] = $this->time->getTime() + 300; // expires after 5 minutes;
		}

		$jwt = JWT::encode(
			$payload,
			$this->config->getSystemValueString('gss.jwt.key', ''),
			'HS256',
		);

		try {
			$this->clientService->newClient()->post(
				$location . $path,
				[
					'headers' => ['OCS-APIRequest' => 'true'],
					'verify' => !$this->config->getSystemValueBool('gss.selfsigned.allow', false),
					'query' => ['format' => 'json'],
					'body' => ['jwt' => $jwt],
				]
			);
		} catch (\Exception $e) {
			throw new \Exception('Could not send message to secondary due to a network issue :' . $e->getMessage(), previous: $e);
		}

		return $location;
	}

	public function decodePayload(string $jwt): array {
		return (array)JWT::decode($jwt, new Key($this->config->getSystemValueString('gss.jwt.key', ''), 'HS256'));
	}
}

// OCP\GlobalScale\IGlobalScaleService only exists since Nextcloud 34.0.3, but this app
// still supports 32 and 33, so only implement it when it's actually available.
if (interface_exists(IGlobalScaleService::class)) {
	class GlobalScaleService implements IGlobalScaleService {
		use TGlobalScaleService;
	}
} else {
	// needed as long as Nextcloud < 34.0.3 is supported, see appinfo/info.xml
	class GlobalScaleService {
		use TGlobalScaleService;
	}
}
