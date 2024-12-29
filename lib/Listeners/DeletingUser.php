<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Listeners;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Slave;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * @template-implements IEventListener<BeforeUserDeletedEvent>
 */
class DeletingUser implements IEventListener {

	public function __construct(
		private GlobalSiteSelector $globalSiteSelector,
		private Slave $slave) {
	}

	/**
	 * @param Event $event
	 */
	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserDeletedEvent) {
			return;
		}

		/** only used in slave mode */
		if ($this->globalSiteSelector->getMode() !== GlobalSiteSelector::SLAVE) {
			return;
		}

		$params = [
			'run' => true,
			'uid' => $event->getUser()->getUID()
		];

		$this->slave->preDeleteUser($params);
	}
}
