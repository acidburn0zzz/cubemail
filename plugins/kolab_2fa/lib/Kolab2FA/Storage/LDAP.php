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
    private $users = array();
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
        if (!isset($this->cache[$key]) && ($rec = $this->get_ldap_record($this->username, $key))) {
            $pkey = '@' . $key;
            if (!empty($this->config['fieldmap'][$pkey])) {
                $rec = @json_decode($rec[$pkey], true);
            }
            else if ($this->config['fieldmap'][$key]) {
                $rec = $rec[$key];
            }
            $this->cache[$key] = $rec;
        }

        return $this->cache[$key];
    }

    /**
     * Save data for the given key
     */
    public function write($key, $value)
    {
        if ($rec = $this->get_ldap_record($this->username, $key)) {
            $old_attrs = $rec['_raw'];
            $new_attrs = $old_attrs;

            // serialize $value into one attribute
            $pkey = '@' . $key;
            if ($attr = $this->config['fieldmap'][$pkey]) {
                $new_attrs[$attr] = $value === null ? '' : json_encode($value);
            }
            else if ($attr = $this->config['fieldmap'][$key]) {
                $new_attrs[$attr] = $this->value_mapping($attr, $value, false);

                // special case nsroledn: keep other roles unknown to us
                if ($attr == 'nsroledn' && is_array($this->config['valuemap'][$attr])) {
                    $map = $this->config['valuemap'][$attr];
                    $new_attrs[$attr] = array_merge(
                        $new_attrs[$attr],
                        array_filter((array)$old_attrs[$attr], function($f) use ($map) { return !in_array($f, $map); })
                    );
                }
            }
            else if (is_array($value)) {
                foreach ($value as $k => $val) {
                    if ($attr = $this->config['fieldmap'][$k]) {
                        $new_attrs[$attr] = $this->value_mapping($attr, $value, false);
                    }
                }
            }

            $result = $this->conn->modify_entry($rec['_dn'], $old_attrs, $new_attrs);

            if (!empty($result)) {
                $this->cache[$key] = $value;
                $this->users = array();
            }

            return !empty($result);
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
        $this->users = array();
    }

    /**
     * Fetches user data from LDAP addressbook
     */
    protected function get_ldap_record($user, $key)
    {
        $filter  = $this->parse_vars($this->config['filter'], $user, $key);
        $base_dn = $this->parse_vars($this->config['base_dn'], $user, $key);
        $scope   = $this->config['scope'] ?: 'sub';

        $cachekey = $base_dn . $filter;
        if (!isset($this->users[$cachekey])) {
            $this->users[$cachekey] = array();

            if ($this->ready && ($result = $this->conn->search($base_dn, $filter, $scope, array_values($this->config['fieldmap'])))) {
                if ($result->count() == 1) {
                    $entries = $result->entries(true);
                    $dn      = key($entries);
                    $entry   = array_pop($entries);
                    $this->users[$cachekey] = $this->field_mapping($dn, $entry);
                }
            }
        }

        return $this->users[$cachekey];
    }

    /**
     * Maps LDAP attributes to defined fields
     */
    protected function field_mapping($dn, $entry)
    {
        $entry['_dn'] = $dn;
        $entry['_raw'] = $entry;

        // fields mapping
        foreach ($this->config['fieldmap'] as $field => $attr) {
            $attr_lc = strtolower($attr);
            if (isset($entry[$attr_lc])) {
                $entry[$field] = $this->value_mapping($attr_lc, $entry[$attr_lc], true);
            }
            else if (isset($entry[$attr])) {
                $entry[$field] = $this->value_mapping($attr, $entry[$attr], true);
            }
        }

        return $entry;
    }

    /**
     *
     */
    protected function value_mapping($attr, $value, $reverse = false)
    {
        if ($map = $this->config['valuemap'][$attr]) {
            if ($reverse) {
                $map = array_flip($map);
            }

            if (is_array($value)) {
                $value = array_filter(array_map(function($val) use ($map) {
                    return $map[$val];
                }, $value));
            }
            else {
                $value = $map[$value];
            }
        }

        return $value;
    }

    /**
     * Prepares filter query for LDAP search
     */
    protected function parse_vars($str, $user, $key)
    {
        // replace variables in filter
        list($u, $d) = explode('@', $user);

        // build hierarchal domain string
        $dc = $this->conn->domain_root_dn($d);

        // map key value
        if (is_array($this->config['keymap']) && isset($this->config['keymap'][$key])) {
            $key = $this->config['keymap'][$key];
        }

        // TODO: resolve $user into its DN for %udn

        $replaces = array('%dc' => $dc, '%d' => $d, '%fu' => $user, '%u' => $u, '%k' => $key);

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
