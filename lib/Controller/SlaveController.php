<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\GlobalSiteSelector\Controller;

use OC\Authentication\Token\IToken;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Exceptions\LocalFederatedShareException;
use OCA\GlobalSiteSelector\Exceptions\MasterUrlException;
use OCA\GlobalSiteSelector\Exceptions\SharedFileException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Model\LocalFile;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\ExpiredException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
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
		private readonly GlobalSiteSelector $gss,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly ICrypto $crypto,
		private readonly TokenHandler $tokenHandler,
		private readonly IUserManager $userManager,
		private readonly UserBackend $userBackend,
		private readonly ISession $session,
		private readonly SlaveService $slaveService,
		private readonly GlobalScaleService $globalScaleService,
		private readonly GlobalShareService $globalShareService,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function discovery(): DataResponse {
		// public data, reachable from outside.
		return new DataResponse(['token' => $this->globalScaleService->getLocalToken()]);
	}

	/**
	 * return sharing details about a file.
	 * request must contain encoded jwt.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function findFile(string $token, int $fileId): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $this->globalShareService->getNewFileId($token, $fileId) ?? 1]));
	}

	/**
     * return sharing details about a file.
     * request must contain encoded jwt.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function sharedFile(string $jwt): DataResponse {
		$key = $this->gss->getJwtKey();
		$decoded = (array)JWT::decode($jwt, new Key($key, Application::JWT_ALGORITHM));
		// JWT store data as stdClass, not array
		$decoded = json_decode(json_encode($decoded), true);

		$this->logger->debug('decoded request', ['data' => $decoded]);

		$fileId = (int)($decoded['fileId'] ?? 0);
		$shareId = (int)($decoded['shareId'] ?? 0);
		$instance = $decoded['instance'] ?? '';

		$target = new LocalFile();
		$target->import($decoded['target'] ?? []);

		try {
			// the file is local and returns shares related to it
			return new DataResponse($this->globalShareService->getSharedFiles($fileId, $shareId, $instance, $target));
		} catch (SharedFileException $e) {
			// file not found
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (LocalFederatedShareException $e) {
			// the file is not local and returns the shared folder and the path to the file
			return new DataResponse($e->getFederatedShare(), Http::STATUS_MOVED_PERMANENTLY);
		}
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
			[$uid, $password, $options] = $this->decodeJwt($jwt);
			$this->logger->debug('uid: ' . $uid . ', options: ' . json_encode($options));

			$target = $options['target'];
			$backend = $options['backend'] ?? '';
			if ($backend === 'saml' || $backend === 'oidc') {
				$this->logger->debug('saml or oidc enabled: ' . $backend);
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
					$options['saml']['idp'] ?? ''
				);
				$this->config->setUserValue(
					$user->getUID(),
					Application::APP_ID,
					Slave::OIDC_PROVIDER_ID,
					$options['oidc']['providerId'] ?? ''
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

		$home = $this->urlGenerator->getAbsoluteURL($target);
		$this->logger->debug('redirecting to ' . $home);

		return new RedirectResponse($home);
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
			[$uid, $password, $options] = $this->decodeJwt($jwt);
			$samlOrOidc = in_array($options['backend'] ?? '', ['saml', 'oidc']);
			if ($samlOrOidc) {
				$this->autoprovisionIfNeeded($uid, $options);
			}

			if ($this->userManager->userExists($uid)) {
				// if we have a password, we verify it; if not it means we should be using saml.
				$result = ($password === '') ? $samlOrOidc : $this->userSession->login($uid, $password);
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
}
