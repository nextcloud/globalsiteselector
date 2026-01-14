<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\GlobalSiteSelector\Command;

use OC\Core\Command\Base;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\ConfigLexicon;
use OCA\GlobalSiteSelector\Service\GlobalScaleService;
use OCP\IAppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GlobalScaleDiscovery extends Base {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly GlobalScaleService $globalScaleService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this->setName('globalsiteselector:discovery')
			->addOption('current', '', InputOption::VALUE_NONE, 'display current data')
			->setDescription('run a discovery request over Global Scale to get details about each instances');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('current')) {
			$output->writeln(json_encode($this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::GS_TOKENS), JSON_PRETTY_PRINT));
			return self::SUCCESS;
		}

		// currently, the only available data is a unique token that helps identify each instance
		$this->globalScaleService->refreshTokenFromGlobalScale();
		return self::SUCCESS;
	}
}
