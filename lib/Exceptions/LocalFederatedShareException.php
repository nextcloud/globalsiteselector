<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Exceptions;

use Exception;
use OCA\GlobalSiteSelector\Model\FederatedShare;

class LocalFederatedShareException extends Exception {
	public function __construct(
		private readonly ?FederatedShare $federatedShare = null,
		string $message = "",
		int $code = 0,
		Exception $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getFederatedShare(): FederatedShare {
		return $this->federatedShare;
	}
}
