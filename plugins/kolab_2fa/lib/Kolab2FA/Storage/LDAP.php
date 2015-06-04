<?php

/**
 * Storage backend to store 2-Factor-Authentication settings in LDAP
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

use \Net_LDAP3;

class LDAP extends Base
{
    private $cache = array();
    private $conn;
    private $error;

    public function init(array $config)
    {
        parent::init($config);

        $this->conn = new Net_LDAP3($config);
        $this->conn->config_set('log_hook', array($this, 'log'));

        $this->conn->connect();

        $bind_pass = $this->config['bind_pass'];
        $bind_user = $this->config['bind_user'];
        $bind_dn   = $this->config['bind_dn'];

        $this->ready = $this->conn->bind($bind_dn, $bind_pass);

        if (!$this->ready) {
            throw new Exception("LDAP storage not ready: " . $this->error);
        }
    }

    /**
     * Read data for the given key
     */
    public function read($key)
    {
        list($username, $method) = $this->split_key($key);

        if (!$this->config['fieldmap'][$method]) {
            $this->cache[$key] = false;
            // throw new Exception("No LDAP attribute defined for " . $method);
        }

        if (!isset($this->cache[$key]) && ($rec = $this->get_user_record($username))) {
            $data = false;
            if (!empty($rec[$method])) {
                $data = @json_decode($rec[$method], true);
            }
            $this->cache[$key] = $data;
        }

        return $this->cache[$key];
    }

    /**
     * Save data for the given key
     */
    public function write($key, $value)
    {
        list($username, $method) = $this->split_key($key);

        if (!$this->config['fieldmap'][$method]) {
            // throw new Exception("No LDAP attribute defined for " . $method);
            return false;
        }
/*
        if ($rec = $this->get_user_record($username)) {
            $attrib = $this->config['fieldmap'][$method];
            $result = $this->conn->modify_entry($rec['dn], ...);
            return !empty($result);
        }
*/
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
     * Helper method to split the storage key into username and auth-method
     */
    private function split_key($key)
    {
        return explode(':', $key, 2);
    }

    /**
     * Fetches user data from LDAP addressbook
     */
    function get_user_record($user)
    {
        $filter  = $this->parse_vars($this->config['filter'], $user);
        $base_dn = $this->parse_vars($this->config['base_dn'], $user);
        $scope   = $this->config['scope'] ?: 'sub';

        // get record
        if ($this->ready && ($result = $this->conn->search($base_dn, $filter, $scope, array_values($this->config['fieldmap'])))) {
            if ($result->count() == 1) {
                $entries = $result->entries(true);
                $dn      = key($entries);
                $entry   = array_pop($entries);
                $entry   = $this->field_mapping($dn, $entry);

                return $entry;
            }
        }

        return null;
    }

    /**
     * Maps LDAP attributes to defined fields
     */
    protected function field_mapping($dn, $entry)
    {
        $entry['dn'] = $dn;

        // fields mapping
        foreach ($this->config['fieldmap'] as $field => $attr) {
            $attr_lc = strtolower($attr);
            if (isset($entry[$attr_lc])) {
                $entry[$field] = $entry[$attr_lc];
            }
            else if (isset($entry[$attr])) {
                $entry[$field] = $entry[$attr];
            }
        }

        return $entry;
    }

    /**
     * Prepares filter query for LDAP search
     */
    protected function parse_vars($str, $user)
    {
        // replace variables in filter
        list($u, $d) = explode('@', $user);

        // hierarchal domain string
        if (empty($dc)) {
            $dc = 'dc=' . strtr($d, array('.' => ',dc='));
        }

        $replaces = array('%dc' => $dc, '%d' => $d, '%fu' => $user, '%u' => $u);

        return strtr($str, $replaces);
    }

    /**
     * Prints debug/error info to the log
     */
    public function log($level, $msg)
    {
        $msg = implode("\n", $msg);

        switch ($level) {
        case LOG_DEBUG:
        case LOG_INFO:
        case LOG_NOTICE:
            if ($this->config['debug'] && class_exists('\\rcube', false)) {
                \rcube::write_log('ldap', $msg);
            }
            break;

        case LOG_EMERGE:
        case LOG_ALERT:
        case LOG_CRIT:
        case LOG_ERR:
        case LOG_WARNING:
            $this->error = $msg;
            // throw new Exception("LDAP storage error: " . $msg);
            break;
        }
    }
}
