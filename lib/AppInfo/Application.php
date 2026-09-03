<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\AppInfo;

use Exception;
use OC;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Listeners\AddContentSecurityPolicyListener;
use OCA\GlobalSiteSelector\Listeners\DeletingUser;
use OCA\GlobalSiteSelector\Listeners\UserChanged;
use OCA\GlobalSiteSelector\Listeners\UserCreated;
use OCA\GlobalSiteSelector\Listeners\UserDeleted;
use OCA\GlobalSiteSelector\Listeners\UserLoggedOut;
use OCA\GlobalSiteSelector\Listeners\UserLoggingIn;
use OCA\GlobalSiteSelector\Master;
use OCA\GlobalSiteSelector\PublicCapabilities;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCA\GlobalSiteSelector\SetupChecks\LongJwtKeySetupCheck;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\UserBackend;
use OCP\Accounts\UserUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\GlobalScale\IGlobalScaleService;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Server;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedOutEvent;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class Application
 *
 * @package OCA\GlobalSiteSelector\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'globalsiteselector';
	public const JWT_ALGORITHM = 'HS256';

	private GlobalSiteSelector $globalSiteSelector;
	private LoggerInterface $logger;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(PublicCapabilities::class);

		// events on master
		$context->registerEventListener(UserLoggedInEvent::class, UserLoggingIn::class);
		$context->registerEventListener(
			AddContentSecurityPolicyEvent::class,
			AddContentSecurityPolicyListener::class
		);

		// events on slave
		$context->registerEventListener(UserCreatedEvent::class, UserCreated::class);
		$context->registerEventListener(BeforeUserDeletedEvent::class, DeletingUser::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeleted::class);
		$context->registerEventListener(UserLoggedOutEvent::class, UserLoggedOut::class);
		$context->registerEventListener(UserChangedEvent::class, UserChanged::class);
		$context->registerEventListener(UserUpdatedEvent::class, UserChanged::class);

		$context->registerSetupCheck(LongJwtKeySetupCheck::class);
		$context->registerConfigLexicon(ConfigLexicon::class);

		// registerGlobalScaleService() and IGlobalScaleService only exist since Nextcloud
		// 34.0.3, but this app still supports 32 and 33, see lib/Service/GlobalScaleService.php
		if (interface_exists(IGlobalScaleService::class)) {
			/** @psalm-suppress UndefinedInterfaceMethod */
			$context->registerGlobalScaleService(GlobalScaleService::class);
		}
	}

	/**
	 * @throws Throwable
	 */
	#[\Override]
	public function boot(IBootContext $context): void {
		$this->globalSiteSelector = $context->getAppContainer()->get(GlobalSiteSelector::class);
		$this->logger = $context->getServerContainer()->get(LoggerInterface::class);

		$context->injectFn(\Closure::fromCallable($this->registerUserBackendForSlave(...)));
		$context->injectFn(\Closure::fromCallable($this->redirectToMasterLogin(...)));
		$context->injectFn(\Closure::fromCallable($this->redirectToSlave(...)));
	}

	/**
	 * Register the Global Scale User Backend if we run in slave mode
	 */
	private function registerUserBackendForSlave(): void {
		if (!$this->globalSiteSelector->isSlave()) {
			return;
		}

		$this->logger->debug('registering gss UserBackend for slave', ['app' => self::APP_ID]);

		try {
			$userManager = Server::get(IUserManager::class);
			$backend = Server::get(UserBackend::class);
			$userManager->registerBackend($backend);
		} catch (ContainerExceptionInterface $e) {
			$this->logger->debug(
				'issue during user backend registration',
				[
					'app' => self::APP_ID,
					'exception' => $e
				]
			);
		}

		$this->logger->debug('gss UserBackend registered', ['app' => self::APP_ID]);
	}

	/**
	 * Register the Global Scale User Backend if we run in slave mode
	 */
	private function redirectToMasterLogin(): void {
		if (OC::$CLI) {
			return;
		}

		if (!$this->globalSiteSelector->isSlave()) {
			return;
		}

		$this->logger->debug('Current is slave. should we redirect to master ?', ['app' => self::APP_ID]);

		try {
			$masterUrl = $this->globalSiteSelector->getMasterUrl();

			/** @var IUserSession $userSession */
			$userSession = Server::get(IUserSession::class);
			if ($userSession->isLoggedIn()) {
				$this->logger->debug('already logged in, we stay on slave', ['app' => self::APP_ID]);
				return;
			}

			/** @var IRequest $request */
			$request = Server::get(IRequest::class);
			$pathInfo = (string)$request->getPathInfo();
			if (!in_array($pathInfo, ['/login', '/login/v2', '/login/flow', '/login/v2/flow'], true)) {
				$this->logger->debug('login page not called, we stay on slave', ['app' => self::APP_ID]);
				return;
			}

			$params = $request->getParams();
			if (isset($params['direct'])) {
				$this->logger->debug('direct login page requested, we stay on slave', ['app' => self::APP_ID]);
				return;
			}

			$redirectUrl = $params['redirect_url'] ?? null;
			if ($redirectUrl === null) {
				$masterUrl = rtrim($masterUrl, '/') . '/index.php' . $pathInfo;
			} else {
				$masterUrl = rtrim($masterUrl, '/') . '/index.php/login?redirect_url=' . urlencode($redirectUrl);
			}

			$this->logger->debug('Redirecting client to ' . $masterUrl, ['app' => self::APP_ID]);
			header('Location: ' . $masterUrl);
			exit();
		} catch (Exception|ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			$this->logger->warning(
				'issue during redirectToMasterLogin',
				[
					'exception' => $e,
					'app' => self::APP_ID
				]
			);
		}
	}

	private function redirectToSlave(IRequest $request, Master $master, IUserSession $userSession): void {
		/** only used in master mode */
		if (!$this->globalSiteSelector->isMaster()) {
			return;
		}

		if (!$userSession->isLoggedIn()) {
			return;
		}

		// We should ignore oauth2 token endpoint (oauth can send the credentials as basic auth which will fail with apache auth)
		$uri = $request->getPathInfo();
		if (str_starts_with($uri, '/apps/oauth/api/v1/token')
			|| str_starts_with($uri, '/apps/oauth2/authorize')
			|| str_starts_with($uri, '/apps/globalsiteselector/autologout')
			|| str_starts_with($uri, '/apps/user_saml/saml/sls')
			|| str_starts_with($uri, '/apps/user_oidc/sls')
			|| str_starts_with($uri, '/login/flow')
		) {
			return;
		}

		$user = $userSession->getUser();
		if ($user === null) {
			return;
		}

		$this->logger->debug('new redirectToSlave');
		$master->handleLoginRequest(
			$user,
			'',
			true,
		);

		$this->logger->debug('ending redirectToSlave');
	}
}
