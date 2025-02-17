<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\UserDiscoveryModules;

interface IUserDiscoveryModule {
	/**
	 * get the initial user location
	 *
	 * @param array $data arbitrary data, whatever the module needs (for example for SAML we hand over the
	 *                    raw data)
	 *
	 * @return string
	 */
	public function getLocation(array $data): string;
}
