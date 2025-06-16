<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\GlobalSiteSelector\Service;

use OC\User\NoUserException;
use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Circle;
use OCA\Files_Sharing\External\MountProvider;
use OCA\GlobalSiteSelector\AppInfo\Application;
use OCA\GlobalSiteSelector\Db\FileRequest;
use OCA\GlobalSiteSelector\Db\ShareRequest;
use OCA\GlobalSiteSelector\Exceptions\LocalFederatedShareException;
use OCA\GlobalSiteSelector\Exceptions\SharedFileException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Model\FederatedShare;
use OCA\GlobalSiteSelector\Model\LocalFile;
use OCA\GlobalSiteSelector\Vendor\Firebase\JWT\JWT;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use UnhandledMatchError;

class GlobalShareService {
	private const LIMIT_PARENTS = 30;
	private array $currentGroups = [];
	private array $currentTeams = [];
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
        private readonly FileRequest $fileRequest,
        private readonly ShareRequest $shareRequest,
		private readonly GlobalSiteSelector $gss,
		private readonly GlobalScaleService $globalScaleService,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ?CirclesManager $circlesManager,
		private readonly LoggerInterface $logger,
	) {
	}


	/**
	 * @return int|null NULL if file not fount
	 */
	public function getNewFileId(string $token, int $fileId): ?int {
		$currentUser = $this->userSession->getUser()?->getUID();
		// There is no valid reason for getUser() to be null,
		if ($currentUser === null) {
			$this->logger->warning('internal link request', ['exception' => new \Exception('could not assign current user')]);
			return null;
		}

		// if token represents the local instance, fall back to normal behavior using file id
		if ($this->globalScaleService->isLocalToken($token)) {
			try {
				$this->getSharedFiles($fileId);
				return $fileId;
			} catch (SharedFileException) {
				return null;
			} catch (LocalFederatedShareException $e) {
				// file is not local
				$federatedShare = $e->getFederatedShare();
				$remote = $federatedShare->getRemote();

				// this should never be the case, but it confirms the file is not local
				if (!$federatedShare->isBounce() || $this->globalScaleService->isLocalAddress($remote)) {
					return null;
				}

				// Get the list of federated shares between both instances that would provide access to the file.
				// The file is identified by share mount point id and path to the final file.
				$federatedShares = $this->requestRemoteFederatedShares($remote, ['shareId' => $federatedShare->getRemoteId(), 'target' => $federatedShare->getTarget()?->jsonSerialize() ?? []], true);
				return $this->getLastFileIdFromShares($currentUser, $federatedShares, $remote);
			}
		}

		// extract instance linked to token
		$instance = $this->globalScaleService->getAddressFromToken($token);

		// unknown instance, make it file not found
		if ($instance === null) {
			return null;
		}

		// request the remote instance to get the list of existing federated shares between both instances and about the remote file id
		return $this->getSharedFileRemoteDetails($instance, $fileId);
	}


	/**
	 * @param string|null $instance set to NULL when assuming local
	 * @return FederatedShare[]
	 * @throws SharedFileException
	 * @throws LocalFederatedShareException
	 */
	public function getSharedFiles(int $fileId, int $shareId = 0, ?string $instance = null, ?LocalFile $target = null): array {
		// in case of redirection, we get the final file id from share mount id and path to the file
		if ($shareId > 0 && $target !== null) {
			$fileId = $this->getIdFromSharedTarget($shareId, $target);
		}

		if ($fileId === 0 || $instance === '') {
			throw new SharedFileException('missing argument');
		}

		// from a file id, get all parents until mount point
		$files = $this->getRelatedFiles((int)$fileId);
		if (empty($files)) {
			throw new SharedFileException('file not found');
		}

		// based on the mount point (last element of the list, top parent folder) we know if the file is local or a federated share from another instance
		$mountPoint = array_slice($files, -1)[0];

		// In case the mount point is a remote share, we send the correct remote instance and the remote share id
		$remoteShare = $this->getFederatedShareFromTargetLocalFile($mountPoint);

		if ($remoteShare?->isBounce() === true) {
			// from the base mount point we add the target to reach the destination filew
			$remoteShare->setTarget($mountPoint);
			throw new LocalFederatedShareException($remoteShare);
		}

		if ($instance === null) {
			return [];
		}

		// mount point is local, we return the list of shares between the remote instance and the related files
		return $this->shareRequest->getFederatedSharesRelatedToRemoteInstance($files, $instance);
	}


    /**
     * get details about a shared remote file based on the address of the remote
     * instance and the id of the file as stored on that remote instance
     *
     * @param string $remote address of the remote instance
     * @param int $remoteFileId id of the file as stored on the remote instance
     * @return int local file id, 1 if not found
     */
    private function getSharedFileRemoteDetails(string $remote, int $remoteFileId): int {
		$currentUser = $this->userSession->getUser()?->getUID();
		if ($currentUser === null || $this->globalScaleService->getLocalAddress() === null) {
			return 1;
		}

		try {
			$federatedShares = $this->requestRemoteFederatedShares($remote, ['fileId' => $remoteFileId]);
		} catch (LocalFederatedShareException $e) {
			// share is local, meaning we should be able to locally find the id of the file
			$federatedShare = $e->getFederatedShare();
			[$fileId, $fileOwner] = $this->shareRequest->getFileOwnerFromShareId($federatedShare->getRemoteId());
			return $this->getFinalFileId($fileOwner, $fileId, $federatedShare->getTarget());
		}

		if (empty($federatedShares)) {
			return 1;
		}

		$this->logger->warning('federated shares', ['remote' => $remote, 'remoteFileId' => $remoteFileId, 'federatedShares' => json_decode(json_encode($federatedShares), true)]);

		return $this->getLastFileIdFromShares($currentUser, $federatedShares, $remote);
	}

    /**
     * returns details about a local file and (recursively) about all parent folders
     *
     * @return LocalFile[]
     */
	private function getRelatedFiles(int $fileId): array {
		if ($fileId === 0) {
			return [];
		}

		$files = $path = [];
		for ($i = 0; $i < self::LIMIT_PARENTS; $i++) {
			$fileDetails = $this->fileRequest->getFileDetails($fileId);
			if ($fileDetails === null) {
				break;
			}

			$fileId = $fileDetails->getParent();
			$fileDetails->setPath($path);
			$files[] = $fileDetails;
			if ($fileId === -1) {
				break;
			}
			$path[] = $fileDetails->getName();
		}

		return $files;
	}

	/**
	 * returns details about the remote share linked to a local mount, how
	 * it is identified by the remote instance and the path to get back to
	 * the target file from the mount point.
	 *
	 * @return FederatedShare|null if no federated share were found
	 */
	private function getFederatedShareFromTargetLocalFile(LocalFile $target): ?FederatedShare {
		$mount = $this->fileRequest->getMountFromTarget($target);
		if ($mount === null) {
			return null;
		}

		if ($mount->getProviderClass() === '') {
			// could be from a federated team
			$teamMount = $this->fileRequest->getFederatedTeamMount($mount, $this->getCurrentTeams($mount->getUserId()));
			if ($teamMount !== null) {
				return $teamMount->setTarget($target);
			}
		}

		if ($mount->getProviderClass() !== MountProvider::class) {
			return null;
		}

		$federatedShare = $this->shareRequest->getBouncedShareFromLocalMount($mount);
		$federatedShare?->setTarget($target);

		return $federatedShare;
	}

	private function getIdFromSharedTarget(int $shareId, LocalFile $target): int {
		[$fileId, $fileOwner] = $this->shareRequest->getFileOwnerFromShareId($shareId);
		return $this->getFinalFileId($fileOwner, $fileId, $target);
	}

	/**
	 * Return a file id based on a list of available shares.
	 * A preferred share is selected based on permissions.
	 * Path will be applied to share mount point.
	 *
	 * @param FederatedShare[] $federatedShares
	 */
	private function getLastFileIdFromShares(string $userId, array $federatedShares, string $instance): int {
		if (str_contains($instance, '://')) {
			$instance = parse_url($instance, PHP_URL_HOST);
		}

		$permission = -1;
		$higherPermissionShare = null;
		foreach ($federatedShares as $federatedShare) {
			if ($this->compareShare($userId, $federatedShare, $permission)) {
				$higherPermissionShare = $federatedShare;
			}
		}

		// no shares are linked to current user.
		if ($higherPermissionShare === null) {
			return 1;
		}

		try {
			$storageKey = match ($higherPermissionShare->getShareType()) {
				IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP => $this->fileRequest->getFederatedShareStorageKey($higherPermissionShare, $instance),
				IShare::TYPE_CIRCLE => $this->fileRequest->getTeamStorages($higherPermissionShare, $instance),
			};
		} catch (UnhandledMatchError) {
			return 1;
		}

		if ($storageKey === null) {
			return 1;
		}

		// returns the id of the file at the end of the mount point + path to the file
		$rootFileId = $this->fileRequest->getFilesFromExternalShareStorage($storageKey);
		return $this->getFinalFileId($userId, $rootFileId, $higherPermissionShare->getTarget());
	}

	/**
	 * compare shares and extract the higher permission,
	 * confirm the link between current user and share.
	 *
	 * @return bool true if share has better permissions
	 */
	private function compareShare(string $userId, FederatedShare $federatedShare, int &$currentPermission): bool {
		if ($federatedShare->getId() === 0 || $federatedShare->getShareWith() === '') {
			return false;
		}

		if ($currentPermission >= $federatedShare->getPermissions()) {
			return false;
		}

		if (($federatedShare->getShareType() === IShare::TYPE_REMOTE && $federatedShare->getShareWith() === $userId)
			|| ($federatedShare->getShareType() === IShare::TYPE_REMOTE_GROUP && in_array($federatedShare->getShareWith(), $this->getCurrentGroups($userId), true))
			|| ($federatedShare->getShareType() === IShare::TYPE_CIRCLE && in_array($federatedShare->getShareWith(), $this->getCurrentTeams($userId), true))) {
			$currentPermission = $federatedShare->getPermissions();
			return true;
		}

		return false;
	}

    /**
     * Return the id of a local file based on the node id of the top
	 * folder / mount point and the path to reach the file
	 *
	 * @return int 1 if file not found
     */
	private function getFinalFileId(string $user, int $nodeId, LocalFile $target): int {
		try {
			$userFolder = $this->rootFolder->getUserFolder($user);
		} catch (NotPermittedException|NoUserException $e) {
			$this->logger->debug('could not get final file id', ['exception' => $e]);
			return 1;
		}

		$folder = $userFolder->getFirstNodeById($nodeId);
		if ($folder === null) {
			return 1;
		}

		foreach (array_reverse($target->getPath()) as $name) {
			try {
				$folder = $folder->get($name);
			} catch (NotFoundException|NotPermittedException $e) {
				$this->logger->debug('could not get final file id', ['exception' => $e]);
				return 1;
			}
		}

		return $folder->getId();
	}

	/**
	 * request remote instance to get the list of federated shares between both instances that would
	 * provide access to file id search can also be performed on the share id.
	 *
	 * @return FederatedShare[]
	 * @throws LocalFederatedShareException if the federated share is not remote
	 */
    private function requestRemoteFederatedShares(string &$remote, array $search, bool $redirected = false): array {
        if (str_contains($remote, '://')) {
            $remote = parse_url($remote, PHP_URL_HOST);
        }

		// this should not happen, but we keep a trace
		if ($this->globalScaleService->isLocalAddress($remote)) {
			$this->logger->warning('remote is local', ['exception' => new \Exception(), "remote" => $remote]);
			return [];
		}

		$responseCode = 0;
        $result = $this->globalScaleService->requestGssOcs(
            $remote,
            'Slave.sharedFile',
            ['jwt' => JWT::encode(array_merge($search, ['instance' => $this->globalScaleService->getLocalAddress()]), $this->gss->getJwtKey(), Application::JWT_ALGORITHM)],
            $responseCode);

		$this->logger->warning('result from remote gss ocs', ['remote' => $remote, 'search' => $search, 'data' => $result, 'responseCode' => $responseCode]);

		// in case file is not on remote instance, we get a redirection
		if (!$redirected && $responseCode === Http::STATUS_MOVED_PERMANENTLY) {
			$federatedShare = new FederatedShare();
			$federatedShare->import($result);
			if (!$federatedShare->isBounce()) {
				return [];
			}

			// on redirection, we update &$remote
			$remote = $federatedShare->getRemote();

			/**
			 * in case of redirection (the file belongs to a different instance than the one that generates the internal-link),
			 * we check the new remote it is not current (local) instance.
			 *
			 * remoteId is the share id, so we extract the id of the shared file.
			 */
			if ($this->globalScaleService->isLocalAddress($remote)) {
				throw new LocalFederatedShareException($federatedShare);
			}

			return $this->requestRemoteFederatedShares($remote, ['shareId' => $federatedShare->getRemoteId(), 'target' => $federatedShare->getTarget()?->jsonSerialize() ?? []], true);
		}

		if ($responseCode !== Http::STATUS_OK) {
			return [];
		}

		$federatedShares = [];
		foreach($result as $entry) {
			$federatedShare = new FederatedShare();
			$federatedShare->import($entry);
			if (!$federatedShare->isBounce()) {
				$federatedShares[] = $federatedShare;
			}
		}

		return $federatedShares;
    }

    /**
     * cache and returns list of current groups a userId belongs to
     */
	private function getCurrentGroups(string $userId): array {
		if (!array_key_exists($userId, $this->currentGroups)) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				return [];
			}
			$this->currentGroups[$userId] = $this->groupManager->getUserGroupIds($user);
		}

		return $this->currentGroups[$userId];
	}

    /**
     * cache and returns list of current teams a userId belongs to
     */
	private function getCurrentTeams(string $userId): array {
		if (!array_key_exists($userId, $this->currentTeams)) {
			$this->circlesManager->startSession($this->circlesManager->getLocalFederatedUser($userId));
			$teams = array_map(fn(Circle $team): string => $team->getSingleId(), $this->circlesManager->probeCircles());

			$this->currentTeams[$userId] = $teams;
		}

		return $this->currentTeams[$userId];
	}
}
