<?php

/**
 * Kolab Task (ToDo) model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_task extends kolab_format
{
    public $CTYPE = 'application/x-vnd.kolab.task';

    protected $xmltype = 'task';

    public static $fulltext_cols = array('title', 'description', 'location', 'attendees:name', 'attendees:email', 'categories');


    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        // TODO: implement this

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return $this->data;
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($record)
    {
        $object = array(
            'uid'     => $record['uid'],
            'changed' => $record['last-modification-date'],
        );

        // TODO: implement this

        $this->data = $object;
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        if ($this->data['status'] == 'COMPLETED' || $this->data['complete'] == 100)
            $tags[] = 'x-complete';

        if ($this->data['priority'] == 1)
            $tags[] = 'x-flagged';

        if (!empty($this->data['alarms']))
            $tags[] = 'x-has-alarms';

        if ($this->data['parent_id'])
            $tags[] = 'x-parent:' . $this->data['parent_id'];

        return $tags;
    }
}
