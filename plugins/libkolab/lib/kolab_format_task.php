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
      'name'         => 'title',
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

        $this->kolab_object['last-modification-date'] = time();

        // map basic fields rcube => $kolab
        foreach ($this->kolab2_fieldmap as $kolab => $rcube) {
            $this->kolab_object[$kolab] = $object[$rcube];
        }

        $this->kolab_object['categories'] = join(',', (array)$object['categories']);

        $status_map = array_flip($this->kolab2_statusmap);
        if ($kolab_status = $status_map[$object['status']])
            $this->kolab_object['status'] = $kolab_status;

        $this->kolab_object['due'] = $this->kolab_object['start'] = null;
        if ($object['due']) {
            $dtdue = clone $object['due'];
            $dtdue->setTimezone(new DateTimeZone('UTC'));
            if ($object['due']->_dateonly)
                $dtdue->setTime(0,0,0);
            $this->kolab_object['due'] = $dtdue->format('U');
        }
        if ($object['start']) {
            $dtstart = clone $object['start'];
            $dtstart->setTimezone(new DateTimeZone('UTC'));
            if ($object['start']->_dateonly)
                $dtstart->setTime(0,0,0);
            $this->kolab_object['start'] = $dtstart->format('U');
        }

        // set 'completed-date' on transition
        if ($this->kolab_object['complete'] < 100 && $object['status'] == 'COMPLETED')
            $this->kolab_object['completed-date'] = time();

        if ($object['status'] == 'COMPLETED' || $object['complete'] == 100)
            $this->kolab_object['completed'] = true;
        else if ($object['status'] != 'COMPLETED')
            $this->kolab_object['completed'] = intval($object['complete']);

        // handle alarms
        $this->kolab_object['alarm'] = self::to_kolab2_alarm($object['alarms']);

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
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

        $object['categories'] = array_filter(explode(',', $record['_categories_all'] ? $record['_categories_all'] : $record['categories']));

        if ($record['due']) {
            $object['due'] = new DateTime('@'.$record['due']);
            if ($object['due']->format('H:i') == '00:00')
                $object['due']->_dateonly = true;
            $object['due']->setTimezone(self::$timezone);
        }
        if ($record['start']) {
            $object['start'] = new DateTime('@'.$record['start']);
            if ($object['start']->format('H:i') == '00:00')
                $object['start']->_dateonly = true;
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
