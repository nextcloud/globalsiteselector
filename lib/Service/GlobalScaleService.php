<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\GlobalSiteSelector\Service;

use Exception;
use JsonException;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class GlobalScaleService {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly IConfig $config,
		private readonly IURLGenerator $urlGenerator,
		private readonly ISecureRandom $secureRandom,
		private readonly GlobalSiteSelector $gss,
		private readonly Lookup $lookup,
		private readonly LoggerInterface $logger,
	) {
	}

    /**
     * return local global scale identity token
     * if none set yet, generate it
     */
	public function getLocalToken(): string {
		if (!$this->appConfig->hasKey(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN)) {
			$this->appConfig->setValueString(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN, $this->secureRandom->generate(5, 'abcdefghijklmnopqrstuvwxyz0123456789'));
		}

		return $this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN);
	}

    /**
     * return local address as known by lus
     */
	public function getLocalAddress(): ?string {
		return $this->getAddressFromToken($this->getLocalToken());
	}

    /**
     * confirm a specific global scale token identify local instance
     */
	public function isLocalToken(string $token): bool {
		return ($this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LOCAL_TOKEN) === $token);
	}

    /**
     * confirm that a url (or a host) is related to local instance
     */
	public function isLocalAddress(string $address): bool {
		if (str_contains($address, '://')) {
			$address = parse_url($address, PHP_URL_HOST);
		}
		return ($this->getLocalAddress() === $address);
	}

	/**
	 * get global scale identity token from each instance of the global scale
	 */
	public function refreshTokenFromGlobalScale(): void {
		if (!$this->gss->isSlave()) {
			return;
		}

		foreach ($this->lookup->getInstances() as $address) {
			$this->refreshTokenFromAddress($address);
		}
	}

	/**
     * request global scale token from a remote instance using public discovery and store it in local cache
	 */
	public function refreshTokenFromAddress(string $address): void {
		if (!$this->gss->isSlave()) {
			return;
		}

		$token = $this->getRemotePublicDiscovery($address)['token'] ?? '';
		if ($token === '' || strlen($token) < 5) {
			return;
		}

		$tokens = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS);
		if (($tokens[$address] ?? '') === $token) {
			return;
		}

		$tokens[$address] = $token;
		$this->appConfig->setValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS, $tokens);
	}

    /**
     * get address from a global scale token
     */
	public function getAddressFromToken(string $token): ?string {
		$tokens = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS);
		$address = array_search($token, $tokens, true);
		if (!$address) {
			return null;
		}
		return $address;
	}

    /**
     * returns global scale token from a specific address
     */
	public function getTokenFromAddress(string $address): ?string {
		$tokens = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS);
		return $tokens[$address] ?? null;
	}

    /**
     * returns discovery data from a remote address
     */
	public function getRemotePublicDiscovery(string $address): array {
     return $this->requestGssOcs($address, 'Slave.discovery');
    }

    /**
     * get data from a remote globalsiteselector ocs endpoint.
     *
     * @param string $address remote global scale instance
     * @param string $route route name to the ocs endpoint
     * @param array $data added to the request
     * @param int $responseCode contains the response code from the request
     *
     * @return array decoded version of the json response
     */
    public function requestGssOcs(string $address, string $route, array $data = [], int &$responseCode = 0): array {
		$client = $this->clientService->newClient();
		try {
			$response = $client->get(
				'https://' . $address . parse_url($this->urlGenerator->linkToOCSRouteAbsolute('globalsiteselector.' . $route), PHP_URL_PATH),
				[
					'headers' => ['OCS-APIRequest' => 'true'],
					'verify' => !$this->config->getSystemValueBool('gss.selfsigned.allow', false),
					'query' => array_merge($data, ['format' => 'json'])
				]
			);
		} catch (Exception $e) {
			$this->logger->warning('could not reach remote gss ocs', ['exception' => $e]);
			return [];
		}

		try {
            $responseCode = $response->getStatusCode();
			return json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR)['ocs']['data'] ?? [];
		} catch (JsonException $e) {
			$this->logger->warning('could not decode json', ['exception' => $e]);
			return [];
		}
	}
}
