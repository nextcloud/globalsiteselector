<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
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

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Events\GlobalScaleMasterLogoutEvent;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\EventDispatcher\IEventDispatcher;
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
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IEventDispatcher $eventDispatcher,
		private GlobalSiteSelector $gss,
		private LoggerInterface $logger
	) {
		parent::__construct($appName, $request);
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

				$event = new GlobalScaleMasterLogoutEvent();
				$event->setIdp($decoded['saml.idp'] ?? '');
				$this->eventDispatcher->dispatchTyped($event);

//				$logoutUrl = $this->urlGenerator->linkToRoute('user_saml.SAML.singleLogoutService');
//				if (!empty($logoutUrl)) {
//					$token = [
//						'logout' => 'logout',
//						'idp' => $idp,
//						'exp' => time() + 300, // expires after 5 minutes
//					];
//
//					$jwt = JWT::encode($token, $this->gss->getJwtKey(), Application::JWT_ALGORITHM);
//
//					return new RedirectResponse($logoutUrl . '?jwt=' . $jwt);
//				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('remote logout request failed', ['exception' => $e]);
		}

		$home = $this->urlGenerator->getAbsoluteURL('/');

		return new RedirectResponse($home);
	}
}
