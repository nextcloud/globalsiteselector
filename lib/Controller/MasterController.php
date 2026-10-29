<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Controller;

use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\Exceptions\GlobalScaleModeratorException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Service\AuditLogService;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\Key;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
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
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly Lookup $lookup,
		private readonly AuditLogService $auditLogService,
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
		} catch (GlobalScaleModeratorException $e) {
			$this->logger->warning('prepAccount failed due to GlobalScaleModeratorException', ['exception' => $e]);
			$response = new Response(Http::STATUS_NOT_FOUND);
			$response->throttle();
			return $response;
		}

		// check user is not already on lus
		$location = $this->lookup->search($uid, true);
		if ($location !== '') {
			return new DataResponse(['already existing user'], Http::STATUS_BAD_REQUEST);
		}

		// confirm instance is known to lus
		$instance = strtolower($instance);
		$knownInstances = $this->lookup->getInstances();
		if (empty($knownInstances)) {
			$this->logger->warning('prepAccount require a configured lookup server');
		}

		$found = false;
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
			['uid' => $uid, 'displayName' => $displayName],
			[
				'timeout' => 60,
				'connect_timeout' => 10,
			]
		);

		$status = $response->getStatusCode();
		if ($status === Http::STATUS_OK) {
			$this->auditLogService->log(Operation::AccountPrepared, 'Account "%s" prepared on instance %s', [
				'userId' => $uid,
				'instance' => $instance,
			]);
			return new DataResponse(['node' => $instance], Http::STATUS_OK);
		}

		$this->logger->warning('fail to create account on remote node', ['status' => $status, 'node' => $instance]);
		return new Response($status);
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
	 * @throws GlobalScaleModeratorException
	 */
	private function confirmGssModerator(?string $configKey = null): void {
		if (!$this->gss->isMaster()) {
			throw new GlobalScaleModeratorException('not master');
		}

		if ($configKey !== null && !$this->appConfig->getAppValueBool($configKey)) {
			throw new GlobalScaleModeratorException('feature not available');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new GlobalScaleModeratorException('no account in session');
		}

		$gssModeratorsGroup = $this->config->getSystemValueString('gss.moderators', '');
		if ($gssModeratorsGroup === '' || !$this->groupManager->isInGroup($user->getUID(), $gssModeratorsGroup)) {
			$this->auditLogService->log(Operation::ModeratorAccessDenied, 'Global scale moderator access denied for "%s"', [
				'userId' => $user->getUID(),
			]);
			throw new GlobalScaleModeratorException('account is not moderator');
		}
	}
}
