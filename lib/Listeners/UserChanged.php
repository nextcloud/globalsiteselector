<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Listeners;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Slave;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserChangedEvent;

/**
 * @template-implements IEventListener<UserChangedEvent>
 */
class UserChanged implements IEventListener {

	public function __construct(
		private GlobalSiteSelector $globalSiteSelector,
		private Slave $slave,
	) {
	}

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$event instanceof UserChangedEvent) {
			return;
		}

		/** only used in slave mode */
		if (!$this->globalSiteSelector->isSlave()) {
			return;
		}

		if ($event->getFeature() !== 'enabled') {
			return;
		}

		// updateUser() routes enabled→add, disabled→remove
		$this->slave->updateUser($event->getUser());
	}
}