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
use OCP\IDBConnection;
use OCP\Share\IShare;

class ShareRequest {
	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	/**
	 * returns list of existing federated shares providing access to a list
	 * of files, in relation to the specified instance.
	 *
	 * @param LocalFile[] $files
	 *
	 * @return FederatedShare[]
	 */
	public function getFederatedSharesRelatedToRemoteInstance(array $files, string $instance): array {
		$indexedFiles = $ids = [];
		foreach ($files as $entry) {
			$indexedFiles[$entry->getId()] = $entry;
			$ids[] = $entry->getId();
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->select('s.id', 's.file_source', 's.share_type', 's.share_with', 's.permissions')
			->from('share', 's')
			->where(
				$qb->expr()->andX(
					$qb->expr()->in('file_source', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)),
					$qb->expr()->orX(
						$qb->expr()->andX(
							$qb->expr()->in('share_type', $qb->createNamedParameter([IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP], IQueryBuilder::PARAM_INT_ARRAY)),
							$qb->expr()->like('share_with', $qb->createNamedParameter('%@' . $instance)),
						),
						$qb->expr()->in('share_type', $qb->createNamedParameter([IShare::TYPE_CIRCLE], IQueryBuilder::PARAM_INT_ARRAY)),
					)
				)
			);

		$result = $qb->executeQuery();
		$shares = [];
		while ($row = $result->fetch()) {
			$shareWith = $row['share_with'];
			if (str_ends_with(strtolower($shareWith), '@' . strtolower($instance))) {
				$shareWith = substr($shareWith, 0, -strlen('@' . $instance));
			}

			$federatedShare = new FederatedShare();
			$federatedShare->setId($row['id'])
				->setFileId($row['file_source'])
				->setShareType($row['share_type'])
				->setShareWith($shareWith)
				->setPermissions($row['permissions'])
				->setTarget($indexedFiles[$row['file_source']]);
			$shares[] = $federatedShare;
		}
		$result->closeCursor();

		return $shares;
	}

	/**
	 * return id and owner about a file.
	 *
	 * @return array{int, string} [fileId, fileOwner]
	 */
	public function getFileOwnerFromShareId(int $shareId): array {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('uid_owner', 'file_source')
			->from('share', 's')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false) {
			return [];
		}
		$fileId = (int)$row['file_source'];
		$owner = $row['uid_owner'];
		$result->closeCursor();

		return [$fileId, $owner];
	}

	/**
	 * returns details about the remote share linked to a local mount and
	 * how it is identified by the remote instance
	 */
	public function getBouncedShareFromLocalMount(LocalMount $mount): ?FederatedShare {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('remote', 'remote_id')
			->from('share_external')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('user', $qb->createNamedParameter($mount->getUserId())),
					$qb->expr()->eq('mountpoint_hash', $qb->createNamedParameter(md5($mount->getMountPoint()))),
				)
			);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		if ($row === false) {
			return null;
		}
		$bouncedShare = new FederatedShare();
		$bouncedShare->setBounce(true)
			->setRemote($row['remote'])
			->setRemoteId((int)$row['remote_id']);
		$result->closeCursor();

		return $bouncedShare;
	}
}
