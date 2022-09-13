<?php
/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\GlobalSiteSelector\Controller;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;

/**
 * Class MasterController
 *
 * Endpoints in case the global site selector operates as a master
 *
 * @package OCA\GlobalSiteSelector\Controller
 */
class MasterController extends OCSController {
	/** @var GlobalSiteSelector  */
	private $gss;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ILogger */
	private $logger;

	/** @var ISession */
	private $session;

	/**
	 * SlaveController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param ILogger $logger
	 * @param GlobalSiteSelector $globalSiteSelector
	 */
	public function __construct($appName,
								IRequest $request,
								IURLGenerator $urlGenerator,
								ILogger $logger,
								GlobalSiteSelector $globalSiteSelector,
								ISession $session
	) {
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->gss = $globalSiteSelector;
		$this->session = $session;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $jwt
	 * @return RedirectResponse
	 */
	public function autoLogout($jwt) {
		try {
			if ($this->isValidJwt($jwt)) {
				$logoutUrl = $this->urlGenerator->linkToRoute('user_saml.SAML.singleLogoutService');
				if (!empty($logoutUrl) && $this->session->get('user_saml.Idp') !== null) {
					$token = ['logout' => 'logout',
						'exp' => time() + 300, // expires after 5 minutes
					];

					$jwt = JWT::encode($token, $this->gss->getJwtKey(), Application::JWT_ALGORITHM);

					return new RedirectResponse($logoutUrl . '?jwt=' . $jwt);
				}
			}
		} catch (\Exception $e) {
			$this->logger->error('remote logout request failed: ' . $e->getMessage());
		}

		$home = $this->urlGenerator->getAbsoluteURL('/');
		return new RedirectResponse($home);
	}

	/**
	 * decode jwt to check if the message comes from a server in the global scale network
	 *
	 * @param string $jwt
	 * @return bool
	 * @throws \Exception
	 */
	protected function isValidJwt($jwt) {
		$key = $this->gss->getJwtKey();
		JWT::decode($jwt, new Key($key, Application::JWT_ALGORITHM));

		return true;
	}
}
