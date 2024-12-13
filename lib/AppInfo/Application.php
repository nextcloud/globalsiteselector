<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\AppInfo;

use Closure;
use Exception;
use OC;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Listeners\AddContentSecurityPolicyListener;
use OCA\GlobalSiteSelector\Listeners\DeletingUser;
use OCA\GlobalSiteSelector\Listeners\UserCreated;
use OCA\GlobalSiteSelector\Listeners\UserDeleted;
use OCA\GlobalSiteSelector\Listeners\UserLoggedOut;
use OCA\GlobalSiteSelector\Listeners\UserLoggingIn;
use OCA\GlobalSiteSelector\PublicCapabilities;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\UserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Server;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserLoggedOutEvent;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
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


	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(PublicCapabilities::class);

		// events on master
		$context->registerEventListener(BeforeUserLoggedInEvent::class, UserLoggingIn::class);
		$context->registerEventListener(
			AddContentSecurityPolicyEvent::class,
			AddContentSecurityPolicyListener::class
		);

		// events on slave
		$context->registerEventListener(UserCreatedEvent::class, UserCreated::class);
		$context->registerEventListener(BeforeUserDeletedEvent::class, DeletingUser::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeleted::class);
		$context->registerEventListener(UserLoggedOutEvent::class, UserLoggedOut::class);

		// It seems that AccountManager use deprecated dispatcher, let's use a deprecated listener
		/** @var IEventDispatcher $eventDispatcher */
		$dispatcher = Server::get(IEventDispatcher::class);
		$dispatcher->addListener(
			'OC\AccountManager::userUpdated',
			function (GenericEvent $event) {
				/** @var IUser $user */
				$user = $event->getSubject();
				$slave = OC::$server->get(Slave::class);
				$slave->updateUser($user);
			}
		);
	}


	/**
	 * @param IBootContext $context
	 *
	 * @throws Throwable
	 */
	public function boot(IBootContext $context): void {
		$this->globalSiteSelector = $context->getAppContainer()->get(GlobalSiteSelector::class);
		$this->logger = $context->getServerContainer()->get(LoggerInterface::class);

		$context->injectFn(Closure::fromCallable([$this, 'registerUserBackendForSlave']));
		$context->injectFn(Closure::fromCallable([$this, 'redirectToMasterLogin']));
	}


	/**
	 * Register the Global Scale User Backend if we run in slave mode
	 */
	private function registerUserBackendForSlave() {
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
	private function redirectToMasterLogin() {
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
			if ($request->getPathInfo() !== '/login') {
				$this->logger->debug('login page not called, we stay on slave', ['app' => self::APP_ID]);

				return;
			}

			$params = $request->getParams();
			if (isset($params['direct'])) {
				$this->logger->debug('direct login page requested, we stay on slave', ['app' => self::APP_ID]
				);

				return;
			}

			if (isset($params['redirect_url'])) {
				$masterUrl = rtrim($masterUrl, '/') . '/index.php/login?redirect_url=' . $params['redirect_url'];
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
}
