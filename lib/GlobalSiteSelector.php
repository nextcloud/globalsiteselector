<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector;

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


	/** @var IConfig */
	private $config;

	/**
	 * GlobalSiteSelector constructor.
	 *
	 * @param IConfig $config
	 */
	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * the global site selector can operate as 'master' or 'slave'
	 *
	 * @return string
	 */
	public function getMode(): string {
		return strtolower($this->config->getSystemValueString('gss.mode', self::SLAVE));
	}

	/**
	 * @return bool
	 */
	public function isMaster(): bool {
		return ($this->getMode() === self::MASTER);
	}

	/**
	 * @return bool
	 */
	public function isSlave(): bool {
		return ($this->getMode() === self::SLAVE);
	}


	public const MIN_JWT_KEY_LENGTH = 32;

	/**
	 * get JWT key
	 *
	 * @return string
	 */
	public function getJwtKey(): string {
		return $this->config->getSystemValueString('gss.jwt.key', '');
	}

	/**
	 * Validate that the JWT key meets minimum length requirements.
	 * HS256 requires a key of at least 256 bits (32 bytes) per RFC 7518 §3.2.
	 */
	public function isJwtKeyValid(): bool {
		$key = $this->getJwtKey();
		return $key !== '' && strlen($key) >= self::MIN_JWT_KEY_LENGTH;
	}


	/**
	 * get the URL of the global site selector master
	 *
	 * @return string
	 * @throws MasterUrlException
	 */
	public function getMasterUrl(): string {
		$masterUrl = $this->config->getSystemValueString('gss.master.url', '');
		if ($masterUrl === '') {
			throw new MasterUrlException('missing gss.master.url in config');
		}

		return $masterUrl;
	}


	/**
	 * get lookup server URL
	 *
	 * @return string
	 */
	public function getLookupServerUrl(): string {
		// TODO: returns exception if non-existant
		return $this->config->getSystemValueString('lookup_server', '');
	}
}
