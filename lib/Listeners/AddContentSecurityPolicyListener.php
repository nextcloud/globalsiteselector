<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Listeners;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class AddContentSecurityPolicyListener implements IEventListener {

	public function __construct(
		private IConfig $config,
		private IUserSession $userSession,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$gssMode = $this->config->getSystemValueString('gss.mode', '');
		$cspAllowList = $this->config->getSystemValue('gss.master.csp-allow', []);
		if ($gssMode !== 'master') {
			return;
		}
		if (!$this->userSession->isLoggedIn() && $this->request->getPathInfo() === '/login') {
			$policy = new ContentSecurityPolicy();
			foreach ($cspAllowList as $entry) {
				$policy->addAllowedFormActionDomain($entry);
			}
			$policy->addAllowedFormActionDomain('nc://*');
			$event->addPolicy($policy);
		}
	}
}
