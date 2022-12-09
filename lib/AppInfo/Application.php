<?php

declare(strict_types=1);


/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
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


namespace OCA\GlobalSiteSelector\AppInfo;

use Closure;
use Exception;
use OC;
use OC_User;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Listener\AddContentSecurityPolicyListener;
use OCA\GlobalSiteSelector\Listeners\DeletingUser;
use OCA\GlobalSiteSelector\Listeners\UserCreated;
use OCA\GlobalSiteSelector\Listeners\UserDeleted;
use OCA\GlobalSiteSelector\Listeners\UserLoggedOut;
use OCA\GlobalSiteSelector\Listeners\UserLoggingIn;
use OCA\GlobalSiteSelector\Master;
use OCA\GlobalSiteSelector\PublicCapabilities;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\UserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\QueryException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserLoggedOutEvent;
use OCP\Util;
use Symfony\Component\EventDispatcher\GenericEvent;
use Throwable;

require_once __DIR__ . '/../../vendor/autoload.php';


/**
 * Class Application
 *
 * @package OCA\GlobalSiteSelector\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'globalsiteselector';
	public const JWT_ALGORITHM = 'HS256';

	private GlobalSiteSelector $globalSiteSelector;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		//TODO: Add proper CSP exception for NC://
	}

	/**
	 * register hooks for the portal if it operates as a master which redirects
	 * users to their login server
	 *
	 * @param IAppContainer $c
	 */
	private function registerMasterHooks(IAppContainer $c) {
		$master = $c->query(Master::class);
		Util::connectHook('OC_User', 'pre_login', $master, 'handleLoginRequest');

		try {
			/** @var IEventDispatcher $eventDispatcher */
			$eventDispatcher = $c->getServer()->query(IEventDispatcher::class);
			$eventDispatcher->addServiceListener(
				AddContentSecurityPolicyEvent::class, AddContentSecurityPolicyListener::class
			);
		} catch (QueryException $e) {
		}
	}

	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(PublicCapabilities::class);

		// event on master
		$context->registerEventListener(BeforeUserLoggedInEvent::class, UserLoggingIn::class);

		// events on slave
		$context->registerEventListener(UserCreatedEvent::class, UserCreated::class);
		$context->registerEventListener(BeforeUserDeletedEvent::class, DeletingUser::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeleted::class);
		$context->registerEventListener(UserLoggedOutEvent::class, UserLoggedOut::class);

		// It seems that AccountManager use deprecated dispatcher, let's use a deprecated listener
		$dispatcher = OC::$server->getEventDispatcher();
		$dispatcher->addListener(
			'OC\AccountManager::userUpdated', function (GenericEvent $event) {
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
		$this->globalSiteSelector = $context->getAppContainer()
											->get(GlobalSiteSelector::class);

		$context->injectFn(Closure::fromCallable([$this, 'registerUserBackendForSlave']));
		$context->injectFn(Closure::fromCallable([$this, 'redirectToMasterLogin']));
	}


	/**
	 * Register the Global Scale User Backend if we run in slave mode
	 *
	 * @param IServerContainer $container
	 */
	private function registerUserBackendForSlave(IServerContainer $container) {
		if (!$this->globalSiteSelector->isSlave()) {
			return;
		}
		$userManager = $container->get(IUserManager::class);

		// make sure that we register the backend only once
		$backends = $userManager->getBackends();
		foreach ($backends as $backend) {
			if ($backend instanceof UserBackend) {
				return;
			}
		}

		$userBackend = new UserBackend(
			$container->get(IDBConnection::class),
			$container->get(ISession::class),
			$container->get(IGroupManager::class),
			$userManager
		);
		$userBackend->registerBackends($userManager->getBackends());
		OC_User::useBackend($userBackend);
	}


	/**
	 * Register the Global Scale User Backend if we run in slave mode
	 *
	 * @param IServerContainer $container
	 */
	private function redirectToMasterLogin(IServerContainer $container) {
		if (OC::$CLI) {
			return;
		}

		if (!$this->globalSiteSelector->isSlave()) {
			return;
		}

		try {
			$masterUrl = $this->globalSiteSelector->getMasterUrl();

			/** @var IUserSession $userSession */
			$userSession = $container->get(IUserSession::class);
			/** @var IRequest $request */
			$request = $container->get(IRequest::class);

			if ($userSession->isLoggedIn() || $request->getPathInfo() !== '/login') {
				return;
			}

			$params = $request->getParams();
			if (isset($params['direct'])) {
				return;
			}

			if (isset($params['redirect_url'])) {
				$masterUrl .= '?redirect_url=' . $params['redirect_url'];
			}

			header('Location: ' . $masterUrl);
			exit();
		} catch (Exception $e) {
			return;
		}
	}
}
