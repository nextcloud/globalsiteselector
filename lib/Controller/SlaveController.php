<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
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


use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ICrypto;

/**
 * Class SlaveController
 *
 * Endpoints in case the global site selector operates as a slave
 *
 * @package OCA\GlobalSiteSelector\Controller
 */
class SlaveController extends OCSController {

	/** @var GlobalSiteSelector  */
	private $gss;

	/** @var ILogger */
	private $logger;

	/** @var IUserSession */
	private $session;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ICrypto */
	private $crypto;

	/**
	 * SlaveController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param GlobalSiteSelector $gss
	 * @param ILogger $logger
	 * @param IUserSession $session
	 * @param IURLGenerator $urlGenerator
	 * @param ICrypto $crypto
	 */
	public function __construct($appName,
								IRequest $request,
								GlobalSiteSelector $gss,
								ILogger $logger,
								IUserSession $session,
								IURLGenerator $urlGenerator,
								ICrypto $crypto
	) {
		parent::__construct($appName, $request);
		$this->gss = $gss;
		$this->logger = $logger;
		$this->session = $session;
		$this->urlGenerator = $urlGenerator;
		$this->crypto = $crypto;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $jwt
	 * @return RedirectResponse
	 */
	public function autoLogin($jwt) {

		$masterUrl = $this->gss->getMasterUrl();

		if($this->gss->getMode() === 'master') {
			return new RedirectResponse($masterUrl);
		}

		if ($jwt === '') {
			return new RedirectResponse($masterUrl);
		}

		try {

			list($uid, $password) = $this->decodeJwt($jwt);

			$result = $this->session->login($uid, $password);
			if ($result === false) {
				throw new \Exception('wrong username or password given for: ' . $uid);
			}

		} catch (ExpiredException $e) {
			$this->logger->info('token expired', ['app' => 'globalsiteselector']);
			return new RedirectResponse($masterUrl);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['app' => 'globalsiteselector']);
			return new RedirectResponse($masterUrl);
		}

		$this->session->createSessionToken($this->request, $uid, $uid, null, 0);
		$home = $this->urlGenerator->getAbsoluteURL('/');
		return new RedirectResponse($home);

	}

	/**
	 * decode jwt and return the uid and the password
	 *
	 * @param string $jwt
	 * @return array
	 * @throws \Exception
	 */
	protected function decodeJwt($jwt) {
		$key = $this->gss->getJwtKey();
		$decoded = (array)JWT::decode($jwt, $key, ['HS256']);

		if (!isset($decoded['uid'])) {
			throw new Exception('"uid" not set in JWT');
		}

		if (!isset($decoded['password'])) {
			throw new \Exception('"password" not set in JWT');
		}

		$uid = $decoded['uid'];
		$password = $this->crypto->decrypt($decoded['password'], $key);

		return [$uid, $password];
	}

}
