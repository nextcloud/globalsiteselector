<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\AuditLog;

/**
 * Operations Global Site Selector writes to the admin audit log, in the form `app.entity.action`.
 * The values are matched by log consumers (filters, alert rules, SIEM imports), so changing one
 * is a breaking change for them. Case names may be renamed.
 */
enum Operation: string {
	case AppPasswordCreated = 'globalsiteselector.app_password.created';
	case LoginFlowGranted = 'globalsiteselector.login_flow.granted';
	case AppPasswordRequestFailed = 'globalsiteselector.app_password.request_failed';
	case AutoLoginSucceeded = 'globalsiteselector.autologin.succeeded';
	case AutoLoginFailed = 'globalsiteselector.autologin.failed';

	public function description(): string {
		return match ($this) {
			self::AppPasswordCreated => 'A slave created an app password for a client login redirected from the master.',
			self::LoginFlowGranted => 'The master completed an emulated login flow grant and created an app password, plus an OAuth2 authorization code when a client is given.',
			self::AppPasswordRequestFailed => 'A slave rejected an app password request from the master.',
			self::AutoLoginSucceeded => 'A slave created a session from a master JWT autologin.',
			self::AutoLoginFailed => 'A slave rejected a JWT autologin.',
		};
	}
}
