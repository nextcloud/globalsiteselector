<?php
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


use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;

/**
 * Class Lookup
 *
 * query the lookup server to find the users location
 *
 * @package OCA\GlobalSiteSelector
 */
class Lookup {

	/** @var IClientService */
	private $httpClientService;

	/** @var  string */
	private $lookupServerUrl;

	/** @var ILogger */
	private $logger;

	/**
	 * Lookup constructor.
	 *
	 * @param IClientService $clientService
	 * @param IConfig $config
	 * @param ILogger $logger
	 */
	public function __construct(IClientService $clientService,
								IConfig $config,
								ILogger $logger
	) {
		$this->httpClientService = $clientService;
		$this->lookupServerUrl = $config->getSystemValue('lookup_server', '');
		$this->logger = $logger;
	}

	/**
	 * try to find the exact user at the lookup server, we allow to search for
	 * email addresses and federated cloud ids and internal UIDs.
	 *
	 * @param string $uid
	 * @return string the url of the server where the user is located
	 */
	public function search($uid) {

		$result = '';

		// admin need to specify a lookup server with GSS capabilities
		if (empty($this->lookupServerUrl)) {
			$this->logger->error(
				'Can not lookup user, no lookup server registered',
				['app' => 'globalsiteselector']
			);
			return $result;
		}

		try {
			$body = $this->queryLookupServer($uid);

			if (isset($body['location'])) {
				$result = rtrim($body['location'], '/');
			}

		} catch (\Exception $e) {
			// Nothing to do, we just return a empty string below as a indicator
			// that nothing was found
		}

		return $result;

	}

	/**
	 * query lookup server and return result
	 *
	 * @param $uid
	 * @return mixed
	 * @throws \Exception
	 */
	private function queryLookupServer($uid) {
		$client = $this->httpClientService->newClient();
		$response = $client->get(
			$this->lookupServerUrl . '/users?search=' . urlencode($uid) . '&exact=1',
			[
				'timeout' => 10,
				'connect_timeout' => 3,
			]
		);

		$body = json_decode($response->getBody(), true);

		return $body;
	}

}
