<?php

declare(strict_types=1);

/**
 * GlobalSiteSelector - Nextcloud Portal to redirect users to the right instance
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021
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

namespace OCA\GlobalSiteSelector\Listeners;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Master;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<BeforeUserLoggedInEvent>
 */
class UserLoggingIn implements IEventListener {

	public function __construct(
		private GlobalSiteSelector $globalSiteSelector,
		private Master $master,
		private LoggerInterface $logger
	) {
	}

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserLoggedInEvent) {
			return;
		}

		/** only used in master mode */
		if (!$this->globalSiteSelector->isMaster()) {
			return;
		}

		$this->logger->debug('new BeforeUserLoggedInEvent event');
		$this->master->handleLoginRequest(
			$event->getUsername(),
			$event->getPassword(),
			$event->getBackend()
		);

		$this->logger->debug('ending BeforeUserLoggedInEvent event');
	}
}
