<?php


/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\GlobalSiteSelector\Controller;

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Master;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class MasterController
 *
 * Endpoints in case the global site selector operates as a master
 *
 * @package OCA\GlobalSiteSelector\Controller
 */
class MasterController extends OCSController {
	private IURLGenerator $urlGenerator;
	private ISession $session;
	private GlobalSiteSelector $gss;
	private Master $master;
	private LoggerInterface $logger;

	public function __construct(
		$appName,
		IRequest $request,
		IURLGenerator $urlGenerator,
		ISession $session,
		GlobalSiteSelector $globalSiteSelector,
		Master $master,
		LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);

		$this->urlGenerator = $urlGenerator;
		$this->session = $session;
		$this->gss = $globalSiteSelector;
		$this->master = $master;
		$this->logger = $logger;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string|null $jwt
	 *
	 * @return RedirectResponse
	 */
	public function autoLogout(?string $jwt) {
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
}
