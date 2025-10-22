<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Db;

use OCA\GlobalSiteSelector\Model\FederatedShare;
use OCA\GlobalSiteSelector\Model\LocalFile;
use OCA\GlobalSiteSelector\Model\LocalMount;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Federation\ICloudIdManager;
use OCP\IDBConnection;

class FileRequest {
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly ICloudIdManager $cloudIdManager,
	) {
	}

	/**
	 * return details from a local file id
	 */
	public function getFileDetails(int $fileId): ?LocalFile {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('parent', 'name', 'storage')
			->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false) {
			return null;
		}
		$details = new LocalFile();
		$details->setId($fileId)
			->setName($row['name'] ?? '')
			->setStorageId($row['storage'] ?? -1)
			->setParent($row['parent'] ?? -1);
		$result->closeCursor();

		return $details;
	}

	/**
	 * return details about the mount point from a LocalFile
	 */
	public function getMountFromTarget(LocalFile $target): ?LocalMount {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('mount_provider_class', 'mount_point', 'user_id')
			->from('mounts')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('storage_id', $qb->createNamedParameter($target->getStorageId(), IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('root_id', $qb->createNamedParameter($target->getId(), IQueryBuilder::PARAM_INT)),
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false) {
			return null;
		}

		$mount = new LocalMount();
		$mount->setProviderClass($row['mount_provider_class'])
			->setMountPoint(rtrim(explode('/files', $row['mount_point'], 2)[1] ?? '', '/'))
			->setUserId($row['user_id']);

		$result->closeCursor();

		return $mount;
	}

	/**
	 * returns remote details about a team share mount point
	 */
	public function getFederatedTeamMount(LocalMount $mount, array $teamIds): ?FederatedShare {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('remote', 'remote_id')
			->from('circles_mount')
			->where(
				$qb->expr()->eq('mountpoint_hash', $qb->createNamedParameter(md5($mount->getMountPoint()))),
				$qb->expr()->in('circle_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false || ($row['remote'] ?? '') === '') {
			return null;
		}

		$federatedShare = new FederatedShare();
		$federatedShare->setRemote($row['remote'])
			->setRemoteId($row['remote_id'])
			->setBounce(true);

		$result->closeCursor();

		return $federatedShare;
	}

	/**
	 * returns id from a storage mount point
	 */
	public function getFilesFromExternalShareStorage(string $storageKey): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('c.fileid')
			->from('filecache', 'c')
			->from('storages', 's')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('s.numeric_id', 'c.storage'),
					$qb->expr()->eq('s.id', $qb->createNamedParameter($storageKey)),
					$qb->expr()->eq('c.parent', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)),
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row !== false) {
			$fileId = (int)$row['fileid'];
		}
		$result->closeCursor();

		return $fileId ?? 0;
	}

	/**
	 * returns the storage key related to federated share from share_external
	 */
	public function getFederatedShareStorageKey(FederatedShare $federatedShare, string $instance): ?string {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('share_token', 'owner', 'remote')
			->from('share_external')
			->where(
				$qb->expr()->andX(
					$qb->expr()->like('remote', $qb->createNamedParameter('%://' . $instance . '/')),
					$qb->expr()->eq('remote_id', $qb->createNamedParameter($federatedShare->getId(), IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('user', $qb->createNamedParameter($federatedShare->getShareWith()))
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false) {
			return null;
		}
		$cloudId = $this->cloudIdManager->getCloudId($row['owner'], $row['remote']);
		$storage = 'shared::' . md5($row['share_token'] . '@' . $cloudId->getRemote());
		$result->closeCursor();

		return $storage;
	}

	/**
	 * returns the storage key related to a federated share from circles_mount
	 */
	public function getTeamStorages(FederatedShare $federatedShare, string $instance): ?string {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('token', 'remote')
			->from('circles_mount')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('remote', $qb->createNamedParameter($instance)),
					$qb->expr()->eq('remote_id', $qb->createNamedParameter($federatedShare->getId(), IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('circle_id', $qb->createNamedParameter($federatedShare->getShareWith()))
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false) {
			return null;
		}
		// why not storing md5 into circles_mount ?
		$storage = 'shared::' . md5($row['token'] . '@https://' . $row['remote']);
		$result->closeCursor();

		return $storage;
	}
}
