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
 * The request is POST, contains data from the sso, and should return the hostname of the destination (nextcloud instance)
 *
 * config.php:
 *    'gss.user.discovery.module' => '\\OCA\\GlobalSiteSelector\\UserDiscoveryModules\\RemoteUserMapping',
 *    'gss.discovery.remote.endpoint' => 'https://example.net/discovery.php',
 *
 */
class RemoteUserMapping implements IUserDiscoveryModule {
	private string $discoveryEndpoint;

	public function __construct(
		private IClientService $clientService,
		IConfig $config,
		private LoggerInterface $logger,
	) {
		$this->discoveryEndpoint = $config->getSystemValueString('gss.discovery.remote.endpoint', '');

		$this->logger->debug('Init RemoteUserMapping');
		$this->logger->debug('host: ' . $this->discoveryEndpoint);
	}

	public function getLocation(array $data): string {
		$client = $this->clientService->newClient();
		$location = '';
		try {
			$result = $client->post($this->discoveryEndpoint, ['body' => $data]);
			$location = $result->getBody();
		} catch (\Exception $e) {
			$this->logger->warning('cannot access remote discovery endpoint ' . $this->discoveryEndpoint, ['exception' => $e]);
		}

		$this->logger->debug('extracted location from remote discovery: ' . $location);
		return $location;
	}
}
