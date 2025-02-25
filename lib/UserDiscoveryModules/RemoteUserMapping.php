<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\UserDiscoveryModules;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * get user location from a remote discovery service.
 * The request is POST, contains data from the sso, and should return a JSON of an array
 * including the entry 'location' with the hostname of the destination (nextcloud instance) as value:
 *
 * {"location": "https://node12.example.net"}
 *
 * config.php:
 *    'gss.user.discovery.module' => '\\OCA\\GlobalSiteSelector\\UserDiscoveryModules\\RemoteUserMapping',
 *    'gss.discovery.remote.endpoint' => 'https://example.net/discovery.php',
 *    'gss.discovery.remote.secret' => 'myVeryOwnLittleSecret',
 */
class RemoteUserMapping implements IUserDiscoveryModule {
	private string $discoveryEndpoint;
	private string $discoverySecretKey;

	public function __construct(
		private IClientService $clientService,
		IConfig $config,
		private LoggerInterface $logger,
	) {
		$this->discoveryEndpoint = $config->getSystemValueString('gss.discovery.remote.endpoint', '');
		$this->discoverySecretKey = $config->getSystemValueString('gss.discovery.remote.secret', '');

		$this->logger->debug('Init RemoteUserMapping');
		$this->logger->debug('host: ' . $this->discoveryEndpoint);
	}

	public function getLocation(array $data): string {
		$client = $this->clientService->newClient();
		if ($this->discoverySecretKey !== '') {
			$data['gsSecretKey'] = $this->discoverySecretKey;
		}

		try {
			$result = $client->post($this->discoveryEndpoint, ['body' => $data]);
		} catch (\Exception $e) {
			$this->logger->warning('cannot access remote discovery endpoint ' . $this->discoveryEndpoint, ['exception' => $e]);
		}

		try {
			$location = json_decode($result->getBody(), true, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->logger->warning('cannot parse remote discovery endpoint result', ['result' => $result->getBody()]);
		}

		$this->logger->debug('extracted location from remote discovery: ' . json_encode($location));
		return $location['location'] ?? '';
	}
}
