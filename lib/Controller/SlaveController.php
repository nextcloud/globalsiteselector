<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Controller;

use DomainException;
use OC\Authentication\Token\IProvider;
use OC\User\DisabledUserException;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\Exceptions\ConfigurationException;
use OCA\GlobalSiteSelector\Exceptions\JwtPayloadException;
use OCA\GlobalSiteSelector\Exceptions\LocalFederatedShareException;
use OCA\GlobalSiteSelector\Exceptions\MasterUrlException;
use OCA\GlobalSiteSelector\Exceptions\SharedFileException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Model\FederatedShare;
use OCA\GlobalSiteSelector\Model\LocalFile;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\BeforeValidException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\ExpiredException;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWTExceptionWithPayloadInterface;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\SignatureInvalidException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\Authentication\Token\IToken;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

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
		private readonly Slave $slave,
		private readonly SlaveService $slaveService,
		private readonly GlobalScaleService $globalScaleService,
		private readonly GlobalShareService $globalShareService,
		private readonly IConfig $config,
		private readonly IProvider $authTokenProvider,
		private readonly AuditLogService $auditLogService,
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
	#[BruteForceProtection(action: 'sharedFile')]
	public function sharedFile(string $jwt): DataResponse {
		try {
			$decoded = $this->decodeJwtPayload($jwt);
		} catch (UnexpectedValueException|DomainException $e) {
			$this->logFailedSharedFileRequest('sharedFile', $this->getJwtFailureReason($e));
			return $this->rejectInvalidJwt($e);
		}

		$this->logger->debug('decoded request', ['data' => $decoded]);

		try {
			$instance = $this->getPayloadString($decoded, 'instance');
			/** @var LocalFile $target */
			$target = $this->importPayload(new LocalFile(), $decoded['target'] ?? []);
		} catch (JwtPayloadException $e) {
			return $this->rejectInvalidPayload('sharedFile', $e);
		}

		$fileId = (int)($decoded['fileId'] ?? 0);
		$shareId = (int)($decoded['shareId'] ?? 0);

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

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[BruteForceProtection(action: 'initAccount')]
	#[FrontpageRoute(verb: 'POST', url: '/initaccount')]
	public function initAccount(string $jwt): DataResponse {
		if (!$this->gss->isSlave()) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		try {
			$decoded = $this->decodeJwtPayload($jwt);
		} catch (UnexpectedValueException|DomainException $e) {
			$this->logFailedProvisioning($this->getTrustedJwtUid($e), $this->getJwtFailureReason($e));
			return $this->rejectInvalidJwt($e);
		}

		$uid = $decoded['uid'] ?? '';
		if (!is_string($uid) || $uid === '') {
			$this->logFailedProvisioning(null, 'invalid_jwt_payload');
			return new DataResponse(['invalid or already existing uid'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->userManager->userExists($uid)) {
			$this->logFailedProvisioning($uid, 'account_exists');
			return new DataResponse(['invalid or already existing uid'], Http::STATUS_BAD_REQUEST);
		}

		$attributes['userData']['displayName'] = $decoded['displayName'] ?? '';
		$this->userBackend->createUserIfNotExists($uid, false);
		$this->userBackend->updateAttributes($uid, $attributes);
		$this->slaveService->updateUserById($uid);

		return new DataResponse([], Http::STATUS_OK);
	}

	/**
	 * initiate refresh on local versions of a remote federated file.
	 * request must contain encoded jwt.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'refreshSharedFile')]
	public function refreshSharedFile(string $jwt): DataResponse {
		try {
			$decoded = $this->decodeJwtPayload($jwt);
		} catch (UnexpectedValueException|DomainException $e) {
			$this->logFailedSharedFileRequest('refreshSharedFile', $this->getJwtFailureReason($e));
			return $this->rejectInvalidJwt($e);
		}

		$this->logger->debug('decoded request', ['data' => $decoded]);

		try {
			$instance = $this->getPayloadString($decoded, 'instance');
			$shares = $decoded['shares'] ?? null;
			if (!is_array($shares)) {
				throw new JwtPayloadException('"shares" is not an array in JWT');
			}

			$federatedShares = [];
			foreach ($shares as $entry) {
				$federatedShares[] = $this->importPayload(new FederatedShare(), $entry);
			}
		} catch (JwtPayloadException $e) {
			return $this->rejectInvalidPayload('refreshSharedFile', $e);
		}

		/** @var FederatedShare $federatedShare */
		foreach ($federatedShares as $federatedShare) {
			$this->globalShareService->refreshSharedTarget(
				$instance,
				$federatedShare->getShareType(),
				$federatedShare->getId(),
				$federatedShare->getShareToken(),
				$federatedShare->getTarget()
			);
		}

		return new DataResponse([]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	#[BruteForceProtection(action: 'autoLogin')]
	public function autoLogin(string $jwt): RedirectResponse {
		$this->logger->debug('autologin incoming request with ' . $jwt);

		try {
			$masterUrl = $this->gss->getMasterUrl();
		} catch (MasterUrlException $e) {
			$this->logger->warning('missing master url');
			return new RedirectResponse('');
		}

		if ($this->gss->isMaster() || $jwt === '') {
			$response = new RedirectResponse($masterUrl);
			$response->throttle();
			return $response;
		}

		$uid = null;
		try {
			[$uid, $password, $options] = $this->decodeJwt($jwt);
			$this->logger->debug('uid: ' . $uid . ', options: ' . json_encode($options));

			$target = $options['target'];
			$params = $options['params'] ?? [];
			$backend = $options['backend'] ?? '';
			if ($backend === 'saml' || $backend === 'oidc') {
				$this->logger->debug('saml or oidc enabled: ' . $backend);
				$this->autoprovisionIfNeeded($uid, $options);

				$user = $this->userManager->get($uid);
				if (!($user instanceof IUser)) {
					throw new \InvalidArgumentException('User is not valid');
				}
				if (!$user->isEnabled()) {
					throw new DisabledUserException('Account disabled');
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
				$backend = 'password';
			}

			$this->logger->notice('auth result: ' . json_encode($result));
			if ($result === false) {
				throw new \Exception('wrong username or password given for: ' . $uid);
			}
		} catch (ExpiredException|BeforeValidException $e) {
			$this->logger->info('token expired or not yet valid');
			$this->logFailedAutoLogin($this->getTrustedJwtUid($e), $this->getJwtFailureReason($e));
			$response = new RedirectResponse($masterUrl);
			$response->throttle();
			return $response;
		} catch (DisabledUserException) {
			// user is disabled, remove from lookup server
			$params = ['uid' => $uid];
			$this->slave->preDeleteUser($params);
			$this->slave->deleteUser($params);
			$this->logFailedAutoLogin($uid, 'account_disabled');
			return new RedirectResponse($masterUrl);
		} catch (ConfigurationException $e) {
			$this->logger->error('cannot process autologin', ['exception' => $e]);
			return new RedirectResponse($masterUrl);
		} catch (\Exception $e) {
			$this->logger->warning('issue during login process', ['exception' => $e]);
			$this->logFailedAutoLogin($uid ?? $this->getTrustedJwtUid($e), ($uid === null) ? $this->getJwtFailureReason($e) : 'login_failed');
			$response = new RedirectResponse($masterUrl);
			$response->throttle();
			return $response;
		}

		$this->logger->debug('all good. creating session');
		/** @psalm-suppress UndefinedInterfaceMethod defined in the private implementation */
		$this->userSession->createSessionToken($this->request, $uid, $uid, null, IToken::REMEMBER);

		// ignore the need of password validation on slaves
		$token = $this->authTokenProvider->getToken($this->session->getId());
		$scope = $token->getScopeAsArray();
		$scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
		$token->setScope($scope);
		$this->authTokenProvider->updateToken($token);

		$this->auditLogService->log(Operation::AutoLoginSucceeded, 'User "%s" logged in via global scale autologin (%s)', [
			'userId' => $uid,
			'backend' => $backend,
		]);

		$this->slaveService->updateUserById($uid);
		$this->logger->debug('userdata updated on lus');

		if (str_starts_with((string)$target, 'http://') || str_starts_with((string)$target, 'https://')) {
			$home = $target;
		} else {
			$home = $this->urlGenerator->getAbsoluteURL($target);
		}

		if (!empty($params)) {
			$home .= '?' . http_build_query($params);
		}
		$this->logger->debug('redirecting to ' . $home);

		return new RedirectResponse($home);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'createAppToken')]
	public function createAppToken($jwt): DataResponse {
		if ($this->gss->getMode() === 'master' || empty($jwt)) {
			$response = new DataResponse([], Http::STATUS_BAD_REQUEST);
			$response->throttle();
			return $response;
		}

		$uid = null;
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
				$this->logFailedAppTokenCreation($uid, 'login_failed');
			} else {
				$this->logFailedAppTokenCreation($uid, 'user_not_found');
			}
		} catch (ExpiredException|BeforeValidException $e) {
			$this->logger->info('Create app password: JWT token expired or not yet valid');
			$this->logFailedAppTokenCreation($this->getTrustedJwtUid($e), $this->getJwtFailureReason($e));
		} catch (ConfigurationException $e) {
			$this->logger->error('cannot process app password creation', ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Exception $e) {
			$this->logger->info('issue while token creation', ['exception' => $e]);
			$this->logFailedAppTokenCreation($uid ?? $this->getTrustedJwtUid($e), ($uid === null) ? $this->getJwtFailureReason($e) : 'login_failed');
		}

		$response = new DataResponse([], Http::STATUS_BAD_REQUEST);
		$response->throttle();
		return $response;
	}

	/**
	 * decode jwt and return the uid and the password
	 *
	 * @throws ConfigurationException if the JWT key is not valid
	 * @throws JwtPayloadException if the payload of a verified JWT is not valid
	 * @throws UnexpectedValueException|DomainException if the JWT is rejected
	 */
	protected function decodeJwt(string $jwt): array {
		$decoded = $this->decodeJwtPayload($jwt);

		$uid = $decoded['uid'] ?? null;
		if (!is_string($uid)) {
			throw new JwtPayloadException('"uid" not set in JWT');
		}

		$password = $decoded['password'] ?? null;
		if (!is_string($password)) {
			throw new JwtPayloadException('"password" not set in JWT', $uid);
		}

		try {
			$password = $this->crypto->decrypt($password, $this->gss->getValidJwtKey());
		} catch (\Exception $e) {
			throw new JwtPayloadException('cannot decrypt password from JWT', $uid, $e);
		}

		$options = $decoded['options'] ?? json_encode([]);
		$options = is_string($options) ? json_decode($options, true) : null;
		if (!is_array($options)) {
			throw new JwtPayloadException('"options" is not valid in JWT', $uid);
		}

		return [$uid, $password, $options];
	}

	/**
	 * @throws UnexpectedValueException
	 * @throws DomainException
	 * @throws ConfigurationException
	 */
	private function decodeJwtPayload(string $jwt): array {
		try {
			$decoded = JWT::decode($jwt, new Key($this->gss->getValidJwtKey(), Application::JWT_ALGORITHM));
		} catch (\TypeError $e) {
			throw new UnexpectedValueException('Malformed JWT', 0, $e);
		}

		// JWT store data as stdClass, not array
		return json_decode(json_encode($decoded), true);
	}

	/**
	 * @throws JwtPayloadException
	 */
	private function getPayloadString(array $payload, string $key): string {
		$value = $payload[$key] ?? '';
		if (!is_string($value)) {
			throw new JwtPayloadException('"' . $key . '" is not a string in JWT');
		}

		return $value;
	}

	/**
	 * @throws JwtPayloadException
	 */
	private function importPayload(LocalFile|FederatedShare $model, mixed $data): LocalFile|FederatedShare {
		if (!is_array($data)) {
			throw new JwtPayloadException('model data is not an array in JWT');
		}

		try {
			return $model->import($data);
		} catch (\TypeError $e) {
			// the setters used by import() are typed
			throw new JwtPayloadException('invalid model data in JWT', null, $e);
		}
	}

	private function rejectInvalidPayload(string $request, JwtPayloadException $e): DataResponse {
		$this->logger->warning('invalid jwt payload on ' . $request, ['exception' => $e]);
		$this->logFailedSharedFileRequest($request, $this->getJwtFailureReason($e));
		return new DataResponse(['invalid payload'], Http::STATUS_BAD_REQUEST);
	}

	private function rejectInvalidJwt(\Throwable $e): DataResponse {
		$this->logger->info('invalid jwt', ['exception' => $e]);
		$response = new DataResponse(['invalid jwt'], Http::STATUS_BAD_REQUEST);
		$response->throttle();
		return $response;
	}

	/**
	 * Create new user if the user doesn't exist yet on the client node
	 */
	protected function autoprovisionIfNeeded(string $uid, array $options) {
		// make sure that a valid UID is given
		if ($uid === '') {
			$this->logger->error('Uid "{uid}" is not valid.', ['app' => $this->appName, 'uid' => $uid]);
			throw new \InvalidArgumentException('No valid uid given. Given uid: ' . $uid);
		}

		$this->userBackend->createUserIfNotExists($uid, true);
		$this->userBackend->updateAttributes($uid, $options);
	}

	/**
	 * the signature of a JWT is verified before its validity period, so the uid of an expired
	 * or not yet valid JWT can be trusted
	 */
	private function getTrustedJwtUid(\Throwable $e): ?string {
		if ($e instanceof JwtPayloadException) {
			return $e->getUid();
		}

		if (!($e instanceof JWTExceptionWithPayloadInterface)) {
			return null;
		}

		$uid = $e->getPayload()->uid ?? null;
		return is_string($uid) ? $uid : null;
	}

	private function getJwtFailureReason(\Throwable $e): string {
		return match (true) {
			$e instanceof ExpiredException => 'expired_jwt',
			$e instanceof BeforeValidException => 'jwt_not_yet_valid',
			$e instanceof SignatureInvalidException => 'invalid_jwt_signature',
			$e instanceof JwtPayloadException => 'invalid_jwt_payload',
			default => 'invalid_jwt',
		};
	}

	private function logFailedProvisioning(?string $uid, string $reason): void {
		$this->auditLogService->log(Operation::AccountProvisioningFailed, 'Global scale account provisioning for "%s" rejected: %s', [
			'userId' => $uid ?? '',
			'reason' => $reason,
		]);
	}

	private function logFailedSharedFileRequest(string $request, string $reason): void {
		$this->auditLogService->log(Operation::SharedFileRequestFailed, 'Global scale %s request rejected: %s', [
			'request' => $request,
			'reason' => $reason,
		]);
	}

	private function logFailedAutoLogin(?string $uid, string $reason): void {
		$this->auditLogService->log(Operation::AutoLoginFailed, 'Global scale autologin for "%s" rejected: %s', [
			'userId' => $uid ?? '',
			'reason' => $reason,
		]);
	}

	private function logFailedAppTokenCreation(?string $uid, string $reason): void {
		$this->auditLogService->log(Operation::AppPasswordRequestFailed, 'Global scale app password creation for "%s" rejected: %s', [
			'userId' => $uid ?? '',
			'reason' => $reason,
		]);
	}
}
