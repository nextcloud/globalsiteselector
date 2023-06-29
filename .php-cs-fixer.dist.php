<?php

declare(strict_types=1);

require_once './vendor-bin/csfixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('l10n')
	->notPath('src')
	->notPath('vendor')
	->notPath('vendor-bin')
	->notPath('lib/Vendor')
	->in(__DIR__);
return $config;
