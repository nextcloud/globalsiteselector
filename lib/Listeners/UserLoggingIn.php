<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
		private LoggerInterface $logger,
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
