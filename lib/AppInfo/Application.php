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


namespace OCA\GlobalSiteSelector\AppInfo;


use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Master;
use OCA\GlobalSiteSelector\PublicCapabilities;
use OCA\GlobalSiteSelector\Slave;
use OCA\GlobalSiteSelector\UserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\Util;
use Symfony\Component\EventDispatcher\GenericEvent;

class Application extends App {

	public const APP_ID = 'globalsiteselector';

	public function __construct(array $urlParams = array()) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();

		$gss = $container->query(GlobalSiteSelector::class);
		$mode = $gss->getMode();

		if ($mode === 'master') {
			$this->registerMasterHooks($container);
		} else {
			$this->registerSlaveHooks($container);
			$this->registerUserBackendForSlave($container);
		}

		$container->registerCapability(PublicCapabilities::class);

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
	}

	/**
	 * register hooks for the portal if it operates as a slave
	 *
	 * @param IAppContainer $c
	 */
	private function registerSlaveHooks(IAppContainer $c) {
		/** @var Slave $slave */
		$slave = $c->query(Slave::class);

		Util::connectHook('OC_User', 'post_createUser',	$slave, 'createUser');
		Util::connectHook('OC_User', 'pre_deleteUser',	$slave, 'preDeleteUser');
		Util::connectHook('OC_User', 'post_deleteUser',	$slave, 'deleteUser');

		$dispatcher = \OC::$server->getEventDispatcher();
		$dispatcher->addListener('OC\AccountManager::userUpdated', function(GenericEvent $event) use ($slave) {
			/** @var \OCP\IUser $user */
			$user = $event->getSubject();
			$slave->updateUser($user);
		});

		\OC::$server->getUserSession()->listen('\OC\User', 'postLogout', function () use ($slave) {
			$slave->handleLogoutRequest();
		});
	}

	/**
	 * Register the Global Scale User Backend if we run in slave mode
	 *
	 * @param IAppContainer $container
	 */
	private function registerUserBackendForSlave(IAppContainer $container) {
		// make sure that we register the backend only once
		$backends = \OC::$server->getUserManager()->getBackends();
		foreach ($backends as $backend) {
			if ($backend instanceof UserBackend) {
				return;
			}
		}
		$userBackend = new UserBackend(
			$container->getServer()->getDatabaseConnection(),
			$container->getServer()->getSession(),
			$container->getServer()->getGroupManager(),
			$container->getServer()->getUserManager()
		);
		$userBackend->registerBackends(\OC::$server->getUserManager()->getBackends());
		\OC_User::useBackend($userBackend);
	}
}
