<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Service;

class ToolsService {

	/**
	 * return TRUE if one entry from $search can be compared to $path or /index.php$path
	 */
	public function isPath(array $search, string $path): bool {
		if ($path === '') {
			return false;
		}

		foreach ($search as $entry) {
			if (str_starts_with($path, (string)$entry) || str_starts_with($path, '/index.php' . $entry)) {
				return true;
			}
		}

		return false;
	}
}
