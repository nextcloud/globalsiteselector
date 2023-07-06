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

use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Class Lookup
 *
 * query the lookup server to find the users location
 *
 * @package OCA\GlobalSiteSelector
 */
class Lookup {

	private string $lookupServerUrl;

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private ICloudIdManager $cloudIdManager,
		private IConfig $config
	) {
		$this->lookupServerUrl = $this->config->getSystemValueString('lookup_server', '');
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
			if (($body['federationId'] ?? '') !== '') {
				$uid = $body['userid']['value'] ?? $uid;
				$location = $this->getUserLocation($body['federationId'], $uid);
			} else {
				$this->logger->debug('search: federationId not set for ' . $uid . ' ' . json_encode($body));
			}
		} catch (\InvalidArgumentException $e) {
			// Nothing to do, assuming we have not found anything
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

	protected function getUserLocation(string $address, string &$uid = ''): string {
		try {
			return match ($this->config->getSystemValueString('gss.username_format', 'validate')) {
				'ignore' => $this->getUserLocation_Ignore($address),
				'sanitize' => $this->getUserLocation_Sanitize($address, $uid),
				'', 'validate' => $this->getUserLocation_Validate($address)
			};
		} catch (\UnhandledMatchError $e) {
			throw new \UnhandledMatchError('gss.username_format in config.php is not valid');
		}
	}


	private function getUserLocation_Validate(string $address): string {
		try {
			$cloudId = $this->cloudIdManager->resolveCloudId($address);
			$location = $cloudId->getRemote();

			return rtrim($location, '/');
		} catch (\InvalidArgumentException $e) {
			$this->logger->notice('(CloudIdManager) Invalid Federated Cloud ID ' . $address);
			throw new \InvalidArgumentException('Invalid Federated Cloud ID');
		}
	}

	private function getUserLocation_Ignore(string $address, ?string &$uid = ''): string {
		$atPos = strrpos($address, '@');
		if (!$atPos) {
			$this->logger->notice('(Local) Invalid Federated Cloud ID ' . $address);
			throw new \InvalidArgumentException('Invalid Federated Cloud ID');
		}

		$uid = substr($address, 0, $atPos);
		$url = substr($address, $atPos + 1);
		$url = (str_starts_with($url, 'https://')) ? substr($url, 8) : $url;
		$url = (str_starts_with($url, 'http://')) ? substr($url, 7) : $url;

		return rtrim($url, '/');
	}


	/**
	 * based on the sanitizeUsername() method from apps/user_ldap/lib/Access.php
	 *
	 * @param string $address
	 * @param string $uid
	 *
	 * @return string
	 */
	private function getUserLocation_Sanitize(string $address, string &$uid): string {
		$address = $this->getUserLocation_Ignore($address, $extractedUid);
		$extractedUid = htmlentities($extractedUid, ENT_NOQUOTES, 'UTF-8');

		$extractedUid = preg_replace(
			'#&([A-Za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $extractedUid
		);
		$extractedUid = preg_replace('#&([A-Za-z]{2})(?:lig);#', '\1', $extractedUid);
		$extractedUid = preg_replace('#&[^;]+;#', '', $extractedUid);
		$extractedUid = str_replace(' ', '_', $extractedUid);
		$extractedUid = preg_replace('/[^a-zA-Z0-9_.@-]/u', '', $extractedUid);

		if (strlen($extractedUid) > 64) {
			$extractedUid = hash('sha256', $extractedUid, false);
		}

		if ($extractedUid === '') {
			throw new \InvalidArgumentException(
				'provided name template for username does not contain any allowed characters'
			);
		}

		$uid = $extractedUid;

		return $address;
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
