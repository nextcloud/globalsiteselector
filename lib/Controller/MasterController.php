<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Controller;

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\Exceptions\IsLocalAdminException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Service\OAuth2Service;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class MasterController
 *
 * Endpoints in case the global site selector operates as a master
 *
 * @package OCA\GlobalSiteSelector\Controller
 */
class MasterController extends OCSController {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly GlobalSiteSelector $gss,
		private readonly GlobalScaleService $globalScaleService,
		private readonly OAuth2Service $oauth2Service,
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly Lookup $lookup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession]
	#[BruteForceProtection(action: 'prepAccount')]
	#[ApiRoute(verb: 'POST', url: '/prepaccount')]
	public function prepAccount(
		string $uid,
		string $instance,
		string $displayName = '',
	): Response {
		try {
			$this->confirmGssModerator(ConfigLexicon::ENABLE_PREP_ACCOUNTS);
		} catch (IsLocalAdminException) {
			return new Response(Http::STATUS_NOT_FOUND);
		}

		// check user is not already on lus
		$location = $this->lookup->search($uid, true);
		if ($location !== '') {
			return new DataResponse(['already existing user'], Http::STATUS_BAD_REQUEST);
		}

		// confirm instance is known to lus
		$instance = strtolower($instance);
		$knownInstances = $this->lookup->getInstances();
		$found = empty($knownInstances);
		foreach ($knownInstances as $address) {
			if (strtolower($address) === $instance) {
				$found = true;
				break;
			}
		}
		if (!$found) {
			return new DataResponse(['instance not found'], Http::STATUS_BAD_REQUEST);
		}

		$response = $this->globalScaleService->sendToLocation(
			'post',
			$instance,
			'/apps/globalsiteselector/initaccount',
			['uid' => $uid, 'displayName' => $displayName]
		);

		$status = $response->getStatusCode();
		if ($status === Http::STATUS_OK) {
			return new DataResponse(['node' => $instance], Http::STATUS_OK);
		}

		return new Response(['creation failed'], $status);
	}

	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/test')]
	public function finalizeOAuthFlow(
		string $stateToken,
		string $clientIdentifier = '',
		string $providedRedirectUri = '',
	): Response {
		try {
			return $this->oauth2Service->finalizeOAuth2(
				$stateToken,
				$clientIdentifier,
				$providedRedirectUri,
				$this->request->getHeader('user-agent'),
			);
		} catch (\Exception $e) {
			$this->logger->warning('fail to manage oauth2', ['exception' => $e]);
			$response = new Response();
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $response;
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	public function autoLogout(?string $jwt): RedirectResponse {
		try {
			if ($jwt !== null) {
				$key = $this->gss->getJwtKey();
				$decoded = (array)JWT::decode($jwt, new Key($key, Application::JWT_ALGORITHM));

				// saml idp ID
				$samlIdp = $decoded['saml.idp'] ?? null;
				// oidc provider ID
				$oidcProviderId = $decoded['oidc.providerId'] ?? '';

				if (class_exists('\OCA\User_SAML\UserBackend')) {
					$logoutUrl = $this->urlGenerator->linkToRoute('user_saml.SAML.singleLogoutService');
				} elseif (class_exists('\OCA\UserOIDC\User\Backend')) {
					$logoutUrl = $this->urlGenerator->linkToRoute('user_oidc.login.singleLogoutService');
				}
				if (!empty($logoutUrl)) {
					$token = [
						'logout' => 'logout',
						'idp' => $samlIdp,
						'oidcProviderId' => $oidcProviderId,
						'exp' => time() + 300, // expires after 5 minutes
					];

					$jwt = JWT::encode($token, $this->gss->getJwtKey(), Application::JWT_ALGORITHM);

					return new RedirectResponse($logoutUrl . '?jwt=' . $jwt);
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('remote logout request failed', ['exception' => $e]);
		}

		$home = $this->urlGenerator->getAbsoluteURL('/');

		return new RedirectResponse($home);
	}

	/**
	 * check the current user is globalscale moderator and the feature is enabled
	 *
	 * @throws IsLocalAdminException
	 */
	private function confirmGssModerator(?string $configKey = null): void {
		if ($configKey !== null && !$this->appConfig->getAppValueBool($configKey)) {
			throw new IsLocalAdminException();
		}

		$user = $this->userSession->getUser();
		$gssModeratorsGroup = $this->config->getSystemValueString('gss.moderators', '');
		if ($gssModeratorsGroup === '' || !$this->groupManager->isInGroup($user->getUID(), $gssModeratorsGroup)) {
			throw new IsLocalAdminException();
		}
	}
}
