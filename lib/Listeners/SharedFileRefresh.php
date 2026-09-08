<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Listeners;

use OCA\GlobalSiteSelector\Service\GlobalShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @template-implements IEventListener<NodeRenamedEvent|NodeDeletedEvent|NodeWrittenEvent>
 */
class SharedFileRefresh implements IEventListener {
	public function __construct(
		private readonly GlobalShareService $globalShareService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event $event
	 */
	#[\Override]
	public function handle(Event $event): void {
		switch (get_class($event)) {
			case NodeWrittenEvent::class:
				$fileId = $event->getNode()->getId();
				break;

			case NodeRenamedEvent::class:
				$fileId = $event->getTarget()->getId();
				break;

			case NodeDeletedEvent::class:
				$fileId = $event->getNode()->getParentId();
				break;

			default:
				return;
		}

		try {
			// file is modified locally, broadcasting the event to other instances
			$this->globalShareService->refreshFileAcrossGlobalScale($fileId);
		} catch (Throwable $e) {
			$this->logger->warning('issue while refreshing file across GS', ['exception' => $e]);
		}
	}
}
