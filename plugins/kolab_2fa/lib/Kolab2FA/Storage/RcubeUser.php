<?php

/**
 * Storage backend to use the Roundcube user prefs to store 2-Factor-Authentication settings
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2015, Kolab Systems AG <contact@kolabsys.com>
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

namespace Kolab2FA\Storage;

use \rcmail;
use \rcube_user;

class RcubeUser extends Base
{
    private $cache = array();
    private $user;

    public function init(array $config)
    {
        parent::init($config);

        $rcmail = rcmail::get_instance();
        $this->config['hostname'] = $rcmail->user->ID ? $rcmail->user->data['mail_host'] : $_SESSION['hostname'];
    }

    /**
     * Read data for the given key
     */
    public function read($key)
    {
        if (!isset($this->cache[$key]) && ($user = $this->get_user($this->username))) {
            $prefs = $user->get_prefs();
            $pkey = 'kolab_2fa_props_' . $key;
            $this->cache[$key] = $prefs[$pkey];
        }

        return $this->cache[$key];
    }

    /**
     * Save data for the given key
     */
    public function write($key, $value)
    {
        if ($user = $this->get_user($this->username)) {
            $this->cache[$key] = $value;
            $pkey = 'kolab_2fa_props_' . $key;
            return $user->save_prefs(array($pkey => $value), true);
        }

        return false;
    }

    /**
     * Remove the data stoed for the given key
     */
    public function remove($key)
    {
        return $this->write($key, null);
    }

    /**
     * Set username to store data for
     */
    public function set_username($username)
    {
        parent::set_username($username);

        // reset cached values
        $this->cache = array();
        $this->user = null;
    }

    /**
     * Helper method to get a rcube_user instance for storing prefs
     */
    private function get_user($username)
    {
        // use global instance if we have a valid Roundcube session
        $rcmail = rcmail::get_instance();
        if ($rcmail->user->ID && $rcmail->user->get_username() == $username) {
            return $rcmail->user;
        }

        if (!$this->user) {
            $this->user = rcube_user::query($username, $this->config['hostname']);
        }

        return $this->user;
    }

}
