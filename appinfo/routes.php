<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


return [
	'ocs' => [
		['name' => 'Slave#createAppToken', 'url' => '/v1/createapptoken', 'verb' => 'GET'],
		['name' => 'Slave#discovery', 'url' => '/discovery', 'verb' => 'GET'],
		['name' => 'Slave#sharedFile', 'url' => '/sharedfile', 'verb' => 'GET'],
	],
	'routes' => [
		[
			'name' => 'Slave#autoLogin',
			'url' => '/autologin',
			'verb' => 'GET'
		],
		[
			'name' => 'Master#autoLogout',
			'url' => '/autologout',
			'verb' => 'GET'
		],
		[
			'name' => 'Slave#findFile',
			'url' => '/gf/{token}/{fileId}',
			'verb' => 'GET',
			'root' => '',
		],
	],
];
