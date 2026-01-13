<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Db;

use Exception;
use OCA\GlobalSiteSelector\Model\FederatedShare;
use OCA\GlobalSiteSelector\Model\LocalFile;
use OCA\GlobalSiteSelector\Model\LocalMount;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Federation\ICloudIdManager;
use OCP\Files\Config\ICachedMountFileInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class FileRequest {
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IUserMountCache $userMountCache,
		private readonly IRootFolder $rootFolder,
		private readonly ICloudIdManager $cloudIdManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * return details from a local file id
	 */
	public function getFileDetails(int $fileId): ?LocalFile {
		$cachedMount = $this->getCachedMountInfoFromNodeId($fileId);
		if ($cachedMount === null) {
			return null;
		}

		try {
			$rootFolder = $this->rootFolder->getUserFolder($cachedMount->getUser()->getUID());
		} catch (Exception $e) {
			$this->logger->warning('could not get root folder for user ' . $cachedMount->getUser()->getUID(), ['exception' => $e, 'fileId' => $fileId, 'userId' => $cachedMount->getUser()->getUID()]);
			return null;
		}
		$node = $rootFolder->getFirstNodeById($fileId);
		if ($node === null) {
			return null;
		}

		$details = new LocalFile();
		$details->setId($fileId)
			->setName($node->getName())
			->setStorageId($cachedMount->getStorageId())
			->setParent($node->getParentId());

		return $details;
	}

	/**
	 * return details about the mount point from a LocalFile
	 */
	public function getMountFromTarget(LocalFile $target): ?LocalMount {
		$cachedMount = $this->getCachedMountInfoFromNodeId($target->getId());
		if ($cachedMount === null) {
			return null;
		}

		$mount = new LocalMount();
		$mount->setProviderClass($cachedMount->getMountProvider())
			->setMountPoint(rtrim(explode('/files', $cachedMount->getMountPoint(), 2)[1] ?? '', '/'))
			->setUserId($cachedMount->getUser()->getUID());

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

	/**
	 * returns the mount using the id of a node,
	 * userid can then be extracted and used to retrieve the file's root folder
	 */
	private function getCachedMountInfoFromNodeId(int $nodeId): ?ICachedMountFileInfo {
		$mounts = $this->userMountCache->getMountsForFileId($nodeId);
		if (empty($mounts ?? [])) {
			$this->logger->warning('mount not found for node id ' . $nodeId);
		}

		return reset($mounts);
	}
}
