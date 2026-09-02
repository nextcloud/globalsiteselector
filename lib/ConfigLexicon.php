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
	public const INSTANCE_MAIN_THREAD = 'requested_instance_main_thread';
	public const SSO_USER_DATA = 'ssoUserData';

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
			new Entry(key: self::LOCAL_TOKEN, type: ValueType::STRING, defaultRaw: '', definition: 'local token to id instance within GlobalScale', lazy: true),
			new Entry(key: self::REDIRECT_WEBDAV, type: ValueType::BOOL, defaultRaw: false, definition: 'redirect WebDAV request on Master to Slaves', lazy: false),
			new Entry(key: self::INSTANCE_MAIN_THREAD, type: ValueType::INT, defaultRaw: 2, definition: 'when running event requests, maximum number of instances to reach before switching to background job', lazy: false),
		];
	}

	/**
	 * @inheritDoc
	 */
	#[\Override]
	public function getUserConfigs(): array {
		return [
			new Entry(key: self::SSO_USER_DATA, type: ValueType::ARRAY, defaultRaw: [], definition: 'formatted SAML/OIDC identity data, cached from the last login with an active SSO session', lazy: true),
		];
	}
}
