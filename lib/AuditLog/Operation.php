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
	case AccountProvisioned = 'globalsiteselector.account.provisioned';
	case AccountProvisioningFailed = 'globalsiteselector.account.provisioning_failed';
	case AccountPrepared = 'globalsiteselector.account.prepared';
	case ModeratorAccessDenied = 'globalsiteselector.moderator_access.denied';
	case UserGroupsSynchronized = 'globalsiteselector.user_groups.synchronized';

	public function description(): string {
		return match ($this) {
			self::AppPasswordCreated => 'A slave created an app password for a client login redirected from the master.',
			self::LoginFlowGranted => 'The master completed an emulated login flow grant and created an app password, plus an OAuth2 authorization code when a client is given.',
			self::AppPasswordRequestFailed => 'A slave rejected an app password request from the master.',
			self::AutoLoginSucceeded => 'A slave created a session from a master JWT autologin.',
			self::AutoLoginFailed => 'A slave rejected a JWT autologin.',
			self::AccountProvisioned => 'A slave created an account in the global scale user backend, either prepared by a master moderator or at the first login.',
			self::AccountProvisioningFailed => 'A slave rejected an account provisioning request from the master.',
			self::AccountPrepared => 'A global scale moderator on the master prepared an account on a slave.',
			self::ModeratorAccessDenied => 'An account that is not a global scale moderator tried to use a moderator feature on the master.',
			self::UserGroupsSynchronized => 'Global scale provisioning added or removed group memberships of an account based on the data sent by the master.',
		};
	}
}
