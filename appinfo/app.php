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

require_once __DIR__ . '/../vendor/autoload.php';

$app = new \OCA\GlobalSiteSelector\AppInfo\Application();

if(OC::$CLI) {
	return;
}
$config = \OC::$server->getConfig();
$gssMode = $config->getSystemValue('gss.mode', '');
if ($gssMode === 'master') {
	return;
}

$userSession = \OC::$server->getUserSession();
$masterUrl = $config->getSystemValue('gss.master.url', '');
$request = \OC::$server->getRequest();
if (!$userSession->isLoggedIn() && !empty($masterUrl) &&
	$request->getPathInfo() === '/login') {

	// an admin wants to login directly at the Nextcloud node
	$params = $request->getParams();
	if (isset($params['direct'])) {
		return;
	}

	if(isset($params['redirect_url'])) {
		$masterUrl .= $params['redirect_url'];
	}

	header('Location: '. $masterUrl);
	exit();
}
