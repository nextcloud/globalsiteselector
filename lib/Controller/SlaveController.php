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

use OC\Authentication\Token\IToken;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Events\AfterLoginOnSlaveEvent;
use OCA\GlobalSiteSelector\Exceptions\MasterUrlException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\ExpiredException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Class SlaveController
 *
 * Endpoints in case the global site selector operates as a slave
 *
 * @package OCA\GlobalSiteSelector\Controller
 */
class SlaveController extends OCSController {

	public function __construct(
		$appName,
		IRequest $request,
		private GlobalSiteSelector $gss,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private ICrypto $crypto,
		private IEventDispatcher $eventDispatcher,
		private TokenHandler $tokenHandler,
		private IUserManager $userManager,
		private UserBackend $userBackend,
		private ISession $session,
		private SlaveService $slaveService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $jwt
	 *
	 * @return RedirectResponse
	 */
	public function autoLogin(string $jwt): RedirectResponse {
		$this->logger->debug('autologin incoming request with ' . $jwt);

		try {
			$masterUrl = $this->gss->getMasterUrl();
		} catch (MasterUrlException $e) {
			$this->logger->warning('missing master url');
			return new RedirectResponse('');
		}

		if ($this->gss->isMaster()) {
			return new RedirectResponse($masterUrl);
		}
		if ($jwt === '') {
			return new RedirectResponse($masterUrl);
		}

		try {
			list($uid, $password, $options) = $this->decodeJwt($jwt);
			$this->logger->debug('uid: ' . $uid . ', options: ' . json_encode($options));

			$target = (string) $options['target'];
			if (($options['backend'] ?? '') === 'saml') {
				$this->logger->debug('saml enabled');
				$this->autoprovisionIfNeeded($uid, $options);

				$user = $this->userManager->get($uid);
				if (!($user instanceof IUser)) {
					throw new \InvalidArgumentException('User is not valid');
				}
				$user->updateLastLoginTimestamp();

				$this->session->set('globalScale.userData', $options);
				$this->session->set('globalScale.uid', $uid);
				$this->config->setUserValue(
					$user->getUID(),
					Application::APP_ID,
					Slave::SAML_IDP,
					$options['saml']['idp'] ?? null
				);

				$result = true;
			} else {
				$this->logger->debug('testing normal login process');
				$result = $this->userSession->login($uid, $password);
			}

			$this->logger->notice('auth result: ' . json_encode($result));
			if ($result === false) {
				throw new \Exception('wrong username or password given for: ' . $uid);
			}
		} catch (ExpiredException $e) {
			$this->logger->info('token expired');

			return new RedirectResponse($masterUrl);
		} catch (\Exception $e) {
			$this->logger->warning('issue during login process', ['exception' => $e]);
			return new RedirectResponse($masterUrl);
		}

		$this->logger->debug('all good. creating session');
		$this->userSession->createSessionToken($this->request, $uid, $uid, null, IToken::REMEMBER);

		$this->slaveService->updateUserById($uid);
		$this->logger->debug('userdata updated on lus');

		$user = $this->userManager->get($uid);
		if ($user instanceof IUser) {
			$this->logger->debug('emitting AfterLoginOnSlaveEvent event');
			$this->eventDispatcher->dispatchTyped(new AfterLoginOnSlaveEvent($user));
		}

		/* see if we need to handle client login */
		$clientFeatureEnabled = ($this->config->getAppValue(Application::APP_ID, 'client_feature_enabled', 'false') === 'true');
		if ($clientFeatureEnabled
			&& $this->request->isUserAgent(
				[
					IRequest::USER_AGENT_CLIENT_IOS,
					IRequest::USER_AGENT_CLIENT_ANDROID,
					IRequest::USER_AGENT_CLIENT_DESKTOP,
					'/^.*\(Android\)$/'
				]
			)) {
			$this->logger->debug('managing request as emerging from client');
			$redirectUrl = $this->modifyRedirectUriForClient($uid, $target, $jwt);
		} else {
			$redirectUrl = $this->urlGenerator->getAbsoluteURL($target);
		}

		$this->logger->debug('redirecting to ' . $redirectUrl);

		return new RedirectResponse($redirectUrl);
	}

	/**
	 * Create app token
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function createAppToken($jwt) {
		if ($this->gss->getMode() === 'master' || empty($jwt)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			list($uid, $password, $options) = $this->decodeJwt($jwt);
			$saml = (($options['backend'] ?? '') === 'saml');
			if ($saml) {
				$this->autoprovisionIfNeeded($uid, $options);
			}

			if ($this->userManager->userExists($uid)) {
				// if we have a password, we verify it; if not it means we should be using saml.
				$result = ('' === $password) ? $saml : $this->userSession->login($uid, $password);
				if ($result) {
					$token = $this->tokenHandler->generateAppToken($uid);

					return new DataResponse($token);
				}
			}
		} catch (ExpiredException $e) {
			$this->logger->info('Create app password: JWT token expired');
		} catch (\Exception $e) {
			$this->logger->info('issue while token creation', ['exception' => $e]);
		}

		return new DataResponse([], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * decode jwt and return the uid and the password
	 *
	 * @param string $jwt
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function decodeJwt($jwt) {
		$key = $this->gss->getJwtKey();
		$decoded = (array)JWT::decode($jwt, new Key($key, Application::JWT_ALGORITHM));

		if (!isset($decoded['uid'])) {
			throw new \Exception('"uid" not set in JWT');
		}

		if (!isset($decoded['password'])) {
			throw new \Exception('"password" not set in JWT');
		}

		$uid = $decoded['uid'];
		$password = $this->crypto->decrypt($decoded['password'], $key);
		$options = $decoded['options'] ?? json_encode([]);

		return [$uid, $password, json_decode($options, true)];
	}


	/**
	 * create new user if the user doesn't exist yet on the client node
	 *
	 * @param string $uid
	 * @param array $options
	 */
	protected function autoprovisionIfNeeded($uid, $options) {
		// make sure that a valid UID is given
		if (empty($uid)) {
			$this->logger->error('Uid "{uid}" is not valid.', ['app' => $this->appName, 'uid' => $uid]);
			throw new \InvalidArgumentException('No valid uid given. Given uid: ' . $uid);
		}

		$this->userBackend->createUserIfNotExists($uid);
		$this->userBackend->updateAttributes($uid, $options);
	}


	private function modifyRedirectUriForClient(
		string $uid,
		string $target,
		string $jwt
	): string {
		$requestUri = $this->request->getRequestUri();
		$isDirectWebDavAccess = str_contains($requestUri, 'remote.php/webdav') || str_contains($requestUri, 'remote.php/dav');

		// direct webdav access with old client or general purpose webdav clients
		if ($isDirectWebDavAccess) {
			$this->logger->debug('redirectUser: client direct webdav request to ' . $target);
			$redirectUrl = $target . '/remote.php/webdav/';
		} else {
			$this->logger->debug('redirectUser: client request generating apptoken');
			$data = $this->createAppToken($jwt)->getData();
			if (!isset($data['token'])) {
				throw new \Exception('getAppToken - data missing token: ' . json_encode($data));
			}
			$appToken = $data['token'];

			$redirectUrl = 'nc://login/server:' . $requestUri . '&user:' . urlencode($uid) . '&password:' . urlencode($appToken);
		}

		$this->logger->debug('generated client redirect url: ' . $redirectUrl);
		return $redirectUrl;
	}
}
