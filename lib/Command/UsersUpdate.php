<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Command;

use OC\Core\Command\Base;
use OCA\GlobalSiteSelector\Slave;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UsersUpdate extends Base {
	private Slave $slave;

	public function __construct(Slave $slave) {
		parent::__construct();

		$this->slave = $slave;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('globalsiteselector:users:update')
			->setDescription('update known users data to Lookup Server');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->slave->batchUpdate();

		return 0;
	}
}
