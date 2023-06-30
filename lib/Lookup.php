<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\GlobalSiteSelector;

use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class Lookup {

	private string $lookupServerUrl;

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private ICloudIdManager $cloudIdManager,
		IConfig $config
	) {
		$this->lookupServerUrl = $config->getSystemValueString('lookup_server', '');
	}

	/**
	 * try to find the exact user at the lookup server, we allow to search for
	 * email addresses and federated cloud ids and internal UIDs.
	 *
	 * @param string $uid
	 *
	 * @return string the url of the server where the user is located
	 */
	public function search(string &$uid, bool $matchUid = false): string {
		$location = '';

		// admin need to specify a lookup server with GSS capabilities
		if (empty($this->lookupServerUrl)) {
			$this->logger->error(
				'Can not lookup user, no lookup server registered',
				['app' => 'globalsiteselector']
			);
			return $location;
		}

		try {
			$body = $this->queryLookupServer($uid, $matchUid);

			if (isset($body['federationId'])) {
				$location = $this->getUserLocation($body['federationId']);
				$uid = $body['userid']['value'] ?? $uid;
			} else {
				$this->logger->debug('search: federationId not set for ' . $uid);
			}
		} catch (\Exception $e) {
			// Nothing to do, we just return a empty string below as a indicator
			// that nothing was found
		}

		$this->logger->debug('search: location for ' . $uid . ' is ' . $location);
		return $location;
	}

	/**
	 * query lookup server and return result
	 *
	 * @param $uid
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	protected function queryLookupServer(string $uid, bool $matchUid = false) {
		$this->logger->debug('queryLookupServer: asking lookup server for: ' . $uid . ' (matchUid: ' . json_encode($matchUid) . ')');
		$client = $this->clientService->newClient();
		$response = $client->get(
			$this->lookupServerUrl . '/users',
			$this->configureClient(
				[
					'query' => [
						'search' => urlencode($uid),
						'exact' => '1',
						'keys' => ($matchUid) ? ['userid'] : []
					]
				]
			)
		);

		return json_decode($response->getBody(), true);
	}

	protected function getUserLocation(string $address): string {
		try {
			$cloudId = $this->cloudIdManager->resolveCloudId($address);
			$location = $cloudId->getRemote();
			return rtrim($location, '/');
		} catch (\InvalidArgumentException $e) {
			$this->logger->notice('Invalid Federated Cloud ID');
			throw new \Exception('Invalid Federated Cloud ID');
		}
	}


	/**
	 * @param array $options
	 *
	 * @return array
	 */
	public function configureClient(array $options): array {
		return array_merge(
			$options,
			[
				'timeout' => 10,
				'connect_timeout' => 3,
				'nextcloud' => ['allow_local_address' => true]
			]
		);
	}
}
