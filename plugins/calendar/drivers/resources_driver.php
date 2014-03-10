<?php

/**
 * Resources directory interface definition
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */


/**
 * Interface definition for a resources directory driver classe
 */
abstract class resources_driver
{

  /**
   * Fetch resource objects to be displayed for booking
   *
   * @param  string  Search query (optional)
   * @return array  List of resource records available for booking
   */
  abstract public function load_resources($query = null);

  /**
   * Return properties of a single resource
   *
   * @param string  Unique resource identifier
   * @return array  Resource object as hash array
   */
  abstract public function get_resource($id);

  /**
   * Return properties of a resource owner
   *
   * @param string  Owner identifier
   * @return array  Resource object as hash array
   */
  public function get_resource_owner($id)
  {
    return null;
  }

}
