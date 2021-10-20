<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace OCA\GlobalSiteSelector\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class AddContentSecurityPolicyListener implements IEventListener {

	private $config;
	private $userSession;
	private $request;

	public function __construct(IConfig $config, IUserSession $userSession, IRequest $request) {
		$this->config = $config;
		$this->userSession = $userSession;
		$this->request = $request;
	}
	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$gssMode = $this->config->getSystemValue('gss.mode', '');
		$cspAllowList = $this->config->getSystemValue('gss.master.csp-allow', []);
		if ($gssMode !== 'master') {
			return;
		}
		if (!$this->userSession->isLoggedIn() && $this->request->getPathInfo() === '/login') {
			$policy = new ContentSecurityPolicy();
			foreach ($cspAllowList as $entry) {
				$policy->addAllowedFormActionDomain($entry);
			}
			$event->addPolicy($policy);
		}
	}
}
