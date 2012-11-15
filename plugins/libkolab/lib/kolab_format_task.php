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

    // Kolab 2 format field map
    private $kolab2_fieldmap = array(
      // kolab       => roundcube
      'summary'      => 'title',
      'body'         => 'description',
      'categories'   => 'categories',
      'sensitivity'  => 'sensitivity',
      'priority'     => 'priority',
      'parent'       => 'parent_id',
    );
    private $kolab2_statusmap = array(
        'none'        => 'NEEDS-ACTION',
        'deferred'    => 'NEEDS-ACTION',
        'not-started' => 'NEEDS-ACTION',
        'in-progress' => 'IN-PROCESS',
        'complete'    => 'COMPLETED',
    );


    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        if ($object['uid'])
            $this->kolab_object['uid'] = $object['uid'];

        $this->kolab_object['last-modification-date'] = new DateTime();

        // map basic fields rcube => $kolab
        foreach ($this->kolab2_fieldmap as $kolab => $rcube) {
            $this->kolab_object[$kolab] = $object[$rcube];
        }

        // make sure categories is an array
        if (!is_array($this->kolab_object['categories']))
            $this->kolab_object['categories'] = array_filter((array)$this->kolab_object['categories']);

        $status_map = array_flip($this->kolab2_statusmap);
        if ($kolab_status = $status_map[$object['status']])
            $this->kolab_object['status'] = $kolab_status;

        $this->kolab_object['due-date']   = $object['due']   ? self::horde_datetime($object['due'], null, $object['due']->_dateonly) : null;
        $this->kolab_object['start-date'] = $object['start'] ? self::horde_datetime($object['start'], null, $object['start']->_dateonly) : null;

        if ($object['status'] == 'COMPLETED' || $object['complete'] == 100)
            $this->kolab_object['completed'] = 100;
        else if ($object['status'] != 'COMPLETED')
            $this->kolab_object['completed'] = intval($object['complete']);

        // handle alarms
        $this->kolab_object['alarm'] = self::to_kolab2_alarm($object['alarms']);

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);

console($this->data, $this->kolab_object);
    }

    /**
     *
     */
    public function is_valid()
    {
        return !empty($this->data['uid']) && isset($this->data['title']);
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($record)
    {
        $object = array(
            'uid'     => $record['uid'],
            'dtstamp' => $record['last-modification-date'],
            'complete' => intval($record['completed']),
        );

        // map basic fields rcube => $kolab
        foreach ($this->kolab2_fieldmap as $kolab => $rcube) {
            $object[$rcube] = $record[$kolab];
        }

        if ($record['completed'] === true || $record['completed'] == 100) {
            $object['status'] = 'COMPLETED';
        }

        if ($record['due-date']) {
            $object['due'] = self::php_datetime($record['due-date']);
            $object['due']->setTimezone(self::$timezone);
        }
        if ($record['start-date']) {
            $object['start'] = self::php_datetime($record['start-date']);
            $object['start']->setTimezone(self::$timezone);
        }

        if ($record['alarm'])
            $object['alarms'] = self::from_kolab2_alarm($record['alarm']);

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
