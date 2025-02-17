<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\UserDiscoveryModules;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Class ManualUserMapping
 *
 * allows you to manage a local JSON encoded file which maps a arbitrary SAML parameter to
 * a initial location of the user.
 *
 * Therefore you have to define to values in the config.php
 *
 * 'gss.discovery.manual.mapping.file' => '/path/to/json-file'
 * 'gss.discovery.manual.mapping.parameter' => 'idp-parameter'
 *
 * And then there is another optional parameter if you want to use regular expressions:
 *
 * 'gss.discovery.manual.mapping.regex' => true
 *
 * @package OCA\GlobalSiteSelector\UserDiscoveryModules
 */
class ManualUserMapping implements IUserDiscoveryModule {
	private string $idpParameter;
	private string $file;
	private bool $useRegularExpressions;

	public function __construct(
		IConfig $config,
		private LoggerInterface $logger,
	) {
		$this->idpParameter = $config->getSystemValueString('gss.discovery.manual.mapping.parameter', '');
		$this->file = $config->getSystemValueString('gss.discovery.manual.mapping.file', '');
		$this->useRegularExpressions = $config->getSystemValueBool('gss.discovery.manual.mapping.regex', false);

		$this->logger->debug('Init ManualUserMapping');
		$this->logger->debug('IdP Parameter: ' . $this->idpParameter);
		$this->logger->debug('file: ' . $this->file);
		$this->logger->debug('use regular expression: ' . ($this->useRegularExpressions ? 'true' : 'false'));
	}


	/**
	 * get the initial user location
	 *
	 * @param array $data idp parameters
	 *
	 * @return string
	 */
	public function getLocation(array $data): string {
		$location = '';
		$dictionary = $this->getDictionary();

		$key = $this->getKey($data['saml'] ?? $data['oidc']);
		$this->logger->debug('Lookup key is: "' . $key . '"');

		// regular lookup
		if (!empty($key) && is_array($dictionary) && !$this->useRegularExpressions) {
			$location = $dictionary[$key] ?? '';
		}

		// dictionary contains regular expressions
		if (!empty($key) && is_array($dictionary) && $this->useRegularExpressions) {
			foreach ($dictionary as $regex => $nextcloudNode) {
				$this->logger->debug('Testing regex: "' . $regex . '"');
				if (preg_match($regex, $key) === 1) {
					$this->logger->debug('Regex matched');
					$location = $nextcloudNode;
					break;
				}
				$this->logger->debug('Regex did not match');
			}
		}

		$this->logger->debug('Location is: "' . $location . '"');

		return $location;
	}

	/**
	 * get dictionary which maps idp parameters to nextcloud nodes
	 *
	 * @return array
	 */
	private function getDictionary() {
		$dictionary = [];
		$isValidFile = !empty($this->file) && file_exists($this->file);
		if ($isValidFile) {
			$mapString = file_get_contents($this->file);
			$dictionary = json_decode($mapString, true);

			if ($dictionary === null || !is_array($dictionary)) {
				$this->logger->critical('Your json file at "' . $this->file . '" is not valid!');
			}
		}

		return is_array($dictionary) ? $dictionary : [];
	}

	/**
	 * get key from IDP parameter
	 *
	 * @param array $data idp parameters
	 *
	 * @return string
	 */
	private function getKey($data) {
		$key = '';
		if (!empty($this->idpParameter) && array_key_exists($this->idpParameter, $data)) {
			$keys = $data[$this->idpParameter];
			if (!is_array($keys)) {
				$keys = [$keys];
			}
			$key = $keys[0];
			$this->logger->debug('Found idpPrameter ' . $this->idpParameter . ' with value "' . $key . '"');
		} else {
			$this->logger->debug('Could not find idpParamter: ' . $this->idpParameter);
		}

		return $this->normalizeKey($key);
	}

	/**
	 * the keys are build like email addresses, we only need the "domain part"
	 *
	 * @param $key
	 *
	 * @return string
	 */
	private function normalizeKey($key) {
		$normalized = $key;
		$pos = strrpos($key, '@');
		if ($pos !== false) {
			$normalized = substr($key, $pos + 1);
		}

		$this->logger->debug('Normalized key: ' . $normalized);

		return $normalized;
	}
}
