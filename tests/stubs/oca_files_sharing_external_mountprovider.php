<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Files_Sharing\External;

use OCP\Federation\ICloudIdManager;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IDBConnection;
use OCP\IUser;


class MountProvider implements IMountProvider {
	public const STORAGE = '\OCA\Files_Sharing\External\Storage';

	/**
	 * @param IDBConnection $connection
	 * @param callable $managerProvider due to setup order we need a callable that return the manager instead of the manager itself
	 * @param ICloudIdManager $cloudIdManager
	 */
	public function __construct(
		private IDBConnection $connection,
		callable $managerProvider,
		private ICloudIdManager $cloudIdManager,
	) {
	}

	public function getMount(IUser $user, $data, IStorageFactory $storageFactory) {
	}

	public function getMountsForUser(IUser $user, IStorageFactory $loader) {
	}
}
