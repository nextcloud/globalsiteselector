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

	public function getStrictness(): Strictness {
		return Strictness::IGNORE;
	}

	/**
	 * @inheritDoc
	 */
	public function getAppConfigs(): array {
		return [
			new Entry(key: self::GS_TOKENS, type: ValueType::ARRAY, defaultRaw: [], definition: 'list of token+host to navigate through GlobalScale', lazy: true),
			new Entry(key: self::LOCAL_TOKEN, type: ValueType::STRING, defaultRaw: '', definition: 'local token to id instance within GlobalScale', lazy: true),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getUserConfigs(): array {
		return [
		];
	}
}
