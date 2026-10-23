<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

use OCA\GlobalSiteSelector\Exceptions\ConfigurationException;
use OCA\GlobalSiteSelector\Exceptions\LookupServerConfigurationException;
use OCA\GlobalSiteSelector\Exceptions\MasterUrlException;
use OCP\IConfig;

/**
 * Class GlobalSiteSelector
 *
 * manage the global site selector
 *
 * @package OCA\GlobalSiteSelector
 */
class GlobalSiteSelector {
	public const MASTER = 'master';
	public const SLAVE = 'slave';

	public const MIN_JWT_KEY_LENGTH = 32;

	public function __construct(
		private readonly IConfig $config,
	) {
	}

	/**
	 * the global site selector can operate as 'master' or 'slave'
	 */
	public function getMode(): string {
		return strtolower($this->config->getSystemValueString('gss.mode', self::SLAVE));
	}

	public function isMaster(): bool {
		return ($this->getMode() === self::MASTER);
	}

	public function isSlave(): bool {
		return ($this->getMode() === self::SLAVE);
	}

	/**
	 * get JWT key
	 */
	public function getJwtKey(): string {
		return $this->config->getSystemValueString('gss.jwt.key', '');
	}

	/**
	 * Validate that the JWT key meets minimum length requirements.
	 * HS256 requires a key of at least 256 bits (32 bytes) per RFC 7518 §3.2.
	 */
	public function isJwtKeyValid(): bool {
		try {
			$this->getValidJwtKey();
			return true;
		} catch (ConfigurationException) {
			return false;
		}
	}

	/**
	 * get JWT key, after validating it meets minimum length requirements
	 *
	 * @throws ConfigurationException if the key is missing or too short
	 */
	public function getValidJwtKey(): string {
		$key = $this->getJwtKey();
		if (strlen($key) < self::MIN_JWT_KEY_LENGTH) {
			throw new ConfigurationException('gss.jwt.key is missing or shorter than ' . self::MIN_JWT_KEY_LENGTH . ' characters');
		}

		return $key;
	}

	/**
	 * get the URL of the global site selector master
	 *
	 * @throws MasterUrlException
	 */
	public function getMasterUrl(): string {
		$masterUrl = $this->config->getSystemValueString('gss.master.url', '');
		if ($masterUrl === '') {
			throw new MasterUrlException('missing gss.master.url in config');
		}

		return rtrim($masterUrl, '/');
	}

	/**
	 * get lookup server URL
	 *
	 * @throws LookupServerConfigurationException
	 */
	public function getLookupServerUrl(): string {
		$lus = $this->config->getSystemValueString('lookup_server', '');
		if ($lus === '') {
			throw new LookupServerConfigurationException();
		}

		return rtrim($lus, '/');
	}
}
