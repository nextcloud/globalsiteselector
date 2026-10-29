<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GlobalSiteSelector\Exceptions;

use Exception;
use Throwable;

/**
 * the signature of the JWT is verified, but its payload is not valid
 */
class JwtPayloadException extends Exception {
	public function __construct(
		string $message,
		private readonly ?string $uid = null,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * the uid set in the payload, which can be trusted as the signature is verified
	 */
	public function getUid(): ?string {
		return $this->uid;
	}
}
