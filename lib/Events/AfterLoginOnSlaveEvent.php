<?php

declare(strict_types=1);

/*
 * @copyright 2023 Micke Nordin <kano@sunet.se>
 *
 * @author 2023 Micke Nordin <kano@sunet.se>
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
 */

namespace OCA\GlobalSiteSelector\Events;

use OCP\EventDispatcher\Event;
use OCP\IUser;

/**
 * This event is triggered after GSS login is finalized on the slave.
 **/
class AfterLoginOnSlaveEvent extends Event {
  public function __construct(private IUser $user)
  {
    parent::__construct();
  }
  public function getUser(): IUser
  {
    return $this->user;
  }
}
