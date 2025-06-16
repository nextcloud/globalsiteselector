<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Model;

use JsonSerializable;

class FederatedShare implements JsonSerializable {
	private int $id = 0;
	private int $fileId = 0;
	private int $shareType = 0;
	private string $shareWith = '';
	private int $permissions = 0;
	private bool $bounce = false;
	private string $remote = '';
	private int $remoteId = 0;

	private ?LocalFile $target = null;

	public function __construct() {
	}

	public function setId(int $id): self {
		$this->id = $id;
		return $this;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setFileId(int $fileId): self {
		$this->fileId = $fileId;
		return $this;
	}

	public function getFileId(): int {
		return $this->fileId;
	}

	public function setShareType(int $shareType): self {
		$this->shareType = $shareType;
		return $this;
	}

	public function getShareType(): int {
		return $this->shareType;
	}

	public function setShareWith(string $shareWith): self {
		$this->shareWith = $shareWith;
		return $this;
	}

	public function getShareWith(): string {
		return $this->shareWith;
	}

	public function setPermissions(int $permissions): self {
		$this->permissions = $permissions;
		return $this;
	}

	public function getPermissions(): int {
		return $this->permissions;
	}

	public function setTarget(LocalFile $target): self {
		$this->target = $target;
		return $this;
	}

	public function getTarget(): ?LocalFile {
		return $this->target;
	}

	public function setBounce(bool $bounce): self {
		$this->bounce = $bounce;
		return $this;
	}

	public function isBounce(): bool {
		return $this->bounce;
	}

	public function setRemote(string $remote): self {
		$this->remote = $remote;
		return $this;
	}

	public function getRemote(): string {
		return $this->remote;
	}

	public function setRemoteId(int $remoteId): self {
		$this->remoteId = $remoteId;
		return $this;
	}

	public function getRemoteId(): int {
		return $this->remoteId;
	}

	/**
	 * deserialize model
	 */
	public function import(array $data): self {
		$this->setBounce($data['bounce'] ?? false);
		if ($this->isBounce()) {
			$this->setRemoteId($data['remoteId'] ?? 0)
				 ->setRemote($data['remote'] ?? '');
		} else {
			$this->setId($data['id'] ?? 0)
				 ->setFileId($data['fileId'] ?? 0)
				 ->setShareType($data['shareType'] ?? 0)
				 ->setShareWith($data['shareWith'] ?? '')
				 ->setPermissions($data['permissions'] ?? 0);
		}

		if (array_key_exists('target', $data)) {
			$target = new LocalFile();
			$target->import($data['target']);
			$this->setTarget($target);
		}

		return $this;
	}

	/**
	 * @return array{id: int, fileId: int, shareType: int, shareWith: string, permissions: int, target: array, remote: string, remoteId: int}
	 */
	public function jsonSerialize(): array {
		if ($this->isBounce()) {
			return [
				'remote' => $this->getRemote(),
				'remoteId' => $this->getRemoteId(),
				'target' => $this->getTarget(),
				'bounce' => $this->isBounce(),
			];
		}

		return [
			'id' => $this->getId(),
			'fileId' => $this->getFileId(),
			'shareType' => $this->getShareType(),
			'shareWith' => $this->getShareWith(),
			'permissions' => $this->getPermissions(),
			'target' => $this->getTarget(),
		];

	}
}
