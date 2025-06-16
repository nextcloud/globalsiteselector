<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\GlobalSiteSelector\Model;

use JsonSerializable;

class LocalFile implements JsonSerializable {
	private int $id = 0;
	private string $name = '';
	private int $storageId = -1;
	private int $parent = -1;
	/** @var string[] */
	private array $path = [];

	public function __construct() {
	}

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;
		return $this;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): self {
		$this->name = $name;
		return $this;
	}

	public function getStorageId(): int {
		return $this->storageId;
	}

	public function setStorageId(int $storageId): self {
		$this->storageId = $storageId;
		return $this;
	}

	public function getParent(): int {
		return $this->parent;
	}

	public function setParent(int $parent): self {
		$this->parent = $parent;
		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getPath(): array {
		return $this->path;
	}

	/**
	 * @param string[] $path
	 *
	 * @return $this
	 */
	public function setPath(array $path): self {
		$this->path = $path;
		return $this;
	}

	/**
	 * deserialize model
	 */
	public function import(array $data): self {
		$this->setId($data['id'] ?? 0)
			 ->setName($data['name'] ?? '')
			 ->setStorageId($data['storageId'] ?? -1)
			 ->setParent($data['parent'] ?? -1)
			 ->setPath($data['path'] ?? []);

		return $this;
	}

	/**
	 * @return array{id: int, name: string, storageId: int, parent: int, path: string[]}
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'storageId' => $this->getStorageId(),
			'parent' => $this->getParent(),
			'path' => $this->getPath(),
		];
	}
}
