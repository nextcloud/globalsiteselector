<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Service;

use OCA\GlobalSiteSelector\AuditLog\Operation;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Writes the actions done by Global Site Selector to the admin audit log
 */
class AuditLogService {
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * The operation is only passed on to servers whose CriticalActionPerformedEvent supports it
	 *
	 * @param string $message format string, its placeholders are filled with the first values of $parameters in order
	 * @param array<string, scalar|null> $parameters values for the placeholders of $message, optionally followed by more context
	 */
	public function log(Operation $operation, string $message, array $parameters = []): void {
		$arguments = [$message, $parameters, false];
		if (method_exists(CriticalActionPerformedEvent::class, 'getOperation')) {
			$arguments[] = $operation->value;
		}
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(...$arguments));
	}
}
