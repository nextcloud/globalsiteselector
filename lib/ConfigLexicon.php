<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

class ConfigLexicon implements ILexicon {
	public const GS_TOKENS = 'globalScaleTokens';
	public const LOCAL_TOKEN = 'localToken';
	public const REDIRECT_WEBDAV = 'redirectWebDAV';
	public const MANAGE_OAUTH2 = 'manageOAuth2';
	public const NOTIFY_REMOTE = 'notifyRemote';
	public const INSTANCE_MAIN_THREAD = 'requested_instance_main_thread';
	public const ENABLE_PREP_ACCOUNTS = 'prepAccounts';
	public const IGNORE_USER_PROPERTIES = 'ignore_properties';
	public const SSO_USER_DATA = 'ssoUserData';
	public const FIRST_LOGIN = 'firstLogin';

	#[\Override]
	public function getStrictness(): Strictness {
		return Strictness::IGNORE;
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getAppConfigs(): array {
		return [
			new Entry(key: self::GS_TOKENS, type: ValueType::ARRAY, defaultRaw: [], definition: 'list of token+host to navigate through GlobalScale', lazy: true),
			new Entry(key: self::LOCAL_TOKEN, type: ValueType::STRING, defaultRaw: '', definition: 'local token to id instance within GlobalScale'),
			new Entry(key: self::IGNORE_USER_PROPERTIES, type: ValueType::BOOL, defaultRaw: false, definition: 'ignore local user properties when updating lookup server'),
			new Entry(key: self::REDIRECT_WEBDAV, type: ValueType::BOOL, defaultRaw: false, definition: 'redirect WebDAV request on Master to Slaves', lazy: false),
			new Entry(key: self::MANAGE_OAUTH2, type: ValueType::BOOL, defaultRaw: false, definition: 'manage OAuth2 requests from Master', lazy: false),
			new Entry(key: self::NOTIFY_REMOTE, type: ValueType::BOOL, defaultRaw: false, definition: 'notify remote instance on file changes', lazy: false),
			new Entry(key: self::INSTANCE_MAIN_THREAD, type: ValueType::INT, defaultRaw: 2, definition: 'when running event requests, maximum number of instances to reach before switching to background job', lazy: false),
			new Entry(key: self::ENABLE_PREP_ACCOUNTS, type: ValueType::BOOL, defaultRaw: false, definition: 'open an OCS endpoint to allow specific accounts on master to prepare accounts on slaves', lazy: true),
		];
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getUserConfigs(): array {
		return [
			new Entry(key: self::SSO_USER_DATA, type: ValueType::ARRAY, defaultRaw: [], definition: 'formatted SAML/OIDC identity data, cached from the last login with an active SSO session', lazy: true),
			new Entry(key: self::FIRST_LOGIN, type: ValueType::BOOL, defaultRaw: false, definition: 'confirm user already logged in', lazy: true),
		];
	}
}
