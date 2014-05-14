<?php

/**
 * Helper class that represents a virtual IMAP folder
 * with a subset of the kolab_storage_folder API.
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
class kolab_storage_virtual_folder
{
    public $id;
    public $name;
    public $namespace;
    public $parent = '';
    public $children = array();
    public $virtual = true;

    protected $displayname;

    public function __construct($name, $dispname, $ns, $parent = '')
    {
        $this->id        = kolab_storage::folder_id($name);
        $this->name      = $name;
        $this->namespace = $ns;
        $this->parent    = $parent;
        $this->displayname = $dispname;
    }

    /**
     * Getter for the name of the namespace to which the IMAP folder belongs
     *
     * @return string Name of the namespace (personal, other, shared)
     */
    public function get_namespace()
    {
        return $this->namespace;
    }

    /**
     * Get the display name value of this folder
     *
     * @return string Folder name
     */
    public function get_name()
    {
        // this is already kolab_storage::object_name() result
        return $this->displayname;
    }

    /**
     * Getter for the top-end folder name (not the entire path)
     *
     * @return string Name of this folder
     */
    public function get_foldername()
    {
        $parts = explode('/', $this->name);
        return rcube_charset::convert(end($parts), 'UTF7-IMAP');
    }

    /**
     * Get the color value stored in metadata
     *
     * @param string Default color value to return if not set
     * @return mixed Color value from IMAP metadata or $default is not set
     */
    public function get_color($default = null)
    {
        return $default;
    }
}