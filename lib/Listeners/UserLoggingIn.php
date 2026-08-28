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
use OCP\IRequest;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<UserLoggedInEvent>
 */
class UserLoggingIn implements IEventListener {

	public function __construct(
		private readonly GlobalSiteSelector $globalSiteSelector,
		private readonly Master $master,
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof UserLoggedInEvent) {
			return;
		}

		/** only used in master mode */
		if (!$this->globalSiteSelector->isMaster()) {
			return;
		}

		$uri = $this->request->getRequestUri();
		if (str_ends_with($uri, '/apps/oauth/api/v1/token')) {
			return;
		}

		$this->logger->debug('new BeforeUserLoggedInEvent event');
		$this->master->handleLoginRequest(
			$event->getUser(),
			$event->getPassword(),
		);

		$this->logger->debug('ending BeforeUserLoggedInEvent event');
	}
}
