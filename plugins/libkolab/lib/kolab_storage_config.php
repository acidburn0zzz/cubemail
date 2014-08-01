<?php

/**
 * Kolab storage class providing access to configuration objects on a Kolab server.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2012-2014, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_storage_config
{
    const FOLDER_TYPE = 'configuration';

    /**
     * Singleton instace of kolab_storage_config
     *
     * @var kolab_storage_config
     */
    static protected $instance;

    private $folders;
    private $default;
    private $enabled;


    /**
     * This implements the 'singleton' design pattern
     *
     * @return kolab_storage_config The one and only instance
     */
    static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new kolab_storage_config();
        }

        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->folders = kolab_storage::get_folders(self::FOLDER_TYPE);

        foreach ($this->folders as $folder) {
            if ($folder->default) {
                $this->default = $folder;
                break;
            }
        }

        // if no folder is set as default, choose the first one
        if (!$this->default) {
            $this->default = reset($this->folders);
        }

        // check if configuration folder exist
        if ($this->default && $this->default->name) {
            $this->enabled = true;
        }
    }

    /**
     * Check wether any configuration storage (folder) exists
     *
     * @return bool
     */
    public function is_enabled()
    {
        return $this->enabled;
    }

    /**
     * Get configuration objects
     *
     * @param array $filter      Search filter
     * @param bool  $default     Enable to get objects only from default folder
     * @param array $data_filter Additional object data filter
     *
     * @return array List of objects
     */
    public function get_objects($filter = array(), $default = false, $data_filter = array())
    {
        $list = array();

        foreach ($this->folders as $folder) {
            // we only want to read from default folder
            if ($default && !$folder->default) {
                continue;
            }

            foreach ($folder->select($filter) as $object) {
                foreach ($data_filter as $key => $val) {
                    if ($object[$key] != $val) {
                        continue 2;
                    }
                }

                $list[] = $object;
            }
        }

        return $list;
    }

    /**
     * Create/update configuration object
     *
     * @param array  $object Object data
     * @param string $type   Object type
     *
     * @return bool True on success, False on failure
     */
    public function save(&$object, $type)
    {
        if (!$this->enabled) {
            return false;
        }

        $folder = $this->find_folder($object);

        $object['type'] = $type;

        return $folder->save($object, self::FOLDER_TYPE . '.' . $type, $object['uid']);
    }

    /**
     * Remove configuration object
     *
     * @param string $uid Object UID
     *
     * @return bool True on success, False on failure
     */
    public function delete($uid)
    {
        // fetch the object to find folder
        $list   = $this->get_objects(array(array('uid', '=', $uid)));
        $object = $list[0];

        if (!$object) {
            return false;
        }

        $folder = $this->find_folder($object);

        return $folder->delete($uid);
    }

    /**
     * Find folder
     */
    private function find_folder($object = array())
    {
        // find folder object
        if ($object['_mailbox']) {
            foreach ($this->folders as $folder) {
                if ($folder->name == $object['_mailbox']) {
                    break;
                }
            }
        }
        else {
            $folder = $this->default;
        }

        return $folder;
    }

    /**
     * Builds relation member URI
     *
     * @param string|array Object UUID or Message folder, UID, Search headers (Message-Id, Date)
     *
     * @return string $url Member URI
     */
    public static function build_member_url($params)
    {
        // param is object UUID
        if (is_string($params) && !empty($params)) {
            return 'urn:uuid:' . $params;
        }

        if (empty($params) || !strlen($params['folder'])) {
            return null;
        }

        $rcube   = rcube::get_instance();
        $storage = $rcube->get_storage();

        // modify folder spec. according to namespace
        $folder = $params['folder'];
        $ns     = $storage->folder_namespace($folder);

        if ($ns == 'shared') {
            // Note: this assumes there's only one shared namespace root
            if ($ns = $storage->get_namespace('shared')) {
                if ($prefix = $ns[0][0]) {
                    $folder = 'shared' . substr($folder, strlen($prefix));
                }
            }
        }
        else {
            if ($ns == 'other') {
                // Note: this assumes there's only one other users namespace root
                if ($ns = $storage->get_namespace('shared')) {
                    if ($prefix = $ns[0][0]) {
                        $folder = 'user' . substr($folder, strlen($prefix));
                    }
                }
            }
            else {
                $folder = 'user' . '/' . $rcube->get_user_name() . '/' . $folder;
            }
        }

        $folder = implode('/', array_map('rawurlencode', explode('/', $folder)));

        // build URI
        $url = 'imap:///' . $folder;

        // UID is optional here because sometimes we want
        // to build just a member uri prefix
        if ($params['uid']) {
            $url .= '/' . $params['uid'];
        }

        unset($params['folder']);
        unset($params['uid']);

        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&');
        }

        return $url;
    }

    /**
     * Parses relation member string
     *
     * @param string $url Member URI
     *
     * @return array Message folder, UID, Search headers (Message-Id, Date)
     */
    public static function parse_member_url($url)
    {
        // Look for IMAP URI:
        // imap:///(user/username@domain|shared)/<folder>/<UID>?<search_params>
        if (strpos($url, 'imap:///') === 0) {
            $rcube   = rcube::get_instance();
            $storage = $rcube->get_storage();

            // parse_url does not work with imap:/// prefix
            $url   = parse_url(substr($url, 8));
            $path  = explode('/', $url['path']);
            parse_str($url['query'], $params);

            $uid  = array_pop($path);
            $ns   = array_shift($path);
            $path = array_map('rawurldecode', $path);

            // resolve folder name
            if ($ns == 'shared') {
                $folder = implode('/', $path);
                // Note: this assumes there's only one shared namespace root
                if ($ns = $storage->get_namespace('shared')) {
                    if ($prefix = $ns[0][0]) {
                        $folder = $prefix . '/' . $folder;
                    }
                }
            }
            else if ($ns == 'user') {
                $username = array_shift($path);
                $folder   = implode('/', $path);

                if ($username != $rcube->get_user_name()) {
                    // Note: this assumes there's only one other users namespace root
                    if ($ns = $storage->get_namespace('other')) {
                        if ($prefix = $ns[0][0]) {
                            $folder = $prefix . '/' . $username . '/' . $folder;
                        }
                    }
                }
                else if (!strlen($folder)) {
                    $folder = 'INBOX';
                }
            }
            else {
                return;
            }

            return array(
                'folder' => $folder,
                'uid'    => $uid,
                'params' => $params,
            );
        }
    }

}
