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
}
