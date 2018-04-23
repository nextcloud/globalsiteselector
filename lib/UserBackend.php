<?php
/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

use OCP\IUserBackend;


class UserBackend implements IUserBackend, \OCP\User\Backend\ICheckPasswordBackend {

	private static $validJwt = [];

	/**
	 * Backend name to be shown in user management
	 *
	 * @return string the name of the backend to be shown
	 * @since 8.0.0
	 */
	public function getBackendName() {
		return 'globalsiteselector';
	}

	/**
	 * @since 14.0.0
	 *
	 * @param string $uid The username
	 * @param string $password The password
	 * @return string|bool The uid on success false on failure
	 */
	public function checkPassword(string $loginName, string $password) {
		// TODO: Implement checkPassword() method.
	}

	public function loginUser($)
}
