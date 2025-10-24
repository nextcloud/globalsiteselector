<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\GlobalSiteSelector\Model;

use JsonSerializable;

class LocalMount implements JsonSerializable {
	private string $providerClass = '';
	private string $mountPoint = '';
	private string $userId = '';

	public function __construct() {
	}

	public function setProviderClass(string $providerClass): self {
		$this->providerClass = $providerClass;
		return $this;
	}

	public function getProviderClass(): string {
		return $this->providerClass;
	}

	public function setMountPoint(string $mountPoint): self {
		$this->mountPoint = $mountPoint;
		return $this;
	}

	public function getMountPoint(): string {
		return $this->mountPoint;
	}

	public function setUserId(string $userId): self {
		$this->userId = $userId;
		return $this;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	/**
	 * @return array{provider: string, mountPoint: string, userId: string}
	 */
	public function jsonSerialize(): array {
		return [
			'provider' => $this->getProviderClass(),
			'mountPoint' => $this->getMountPoint(),
			'userId' => $this->getUserId(),
		];
	}
}
