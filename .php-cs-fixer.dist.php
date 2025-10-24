<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once './vendor-bin/csfixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('tests/stubs')
	->notPath('l10n')
	->notPath('src')
	->notPath('vendor')
	->notPath('vendor-bin')
	->notPath('lib/Vendor')
	->in(__DIR__);
return $config;
