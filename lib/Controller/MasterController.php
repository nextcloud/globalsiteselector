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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
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
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly GlobalSiteSelector $gss,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
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
}
