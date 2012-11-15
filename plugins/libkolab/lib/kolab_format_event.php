<?php

/**
 * Kolab Event model class
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

class kolab_format_event extends kolab_format
{
    public $CTYPE = 'application/x-vnd.kolab.event';

    protected $xmltype = 'event';

    public static $fulltext_cols = array('title', 'description', 'location', 'attendees:name', 'attendees:email', 'categories');

    // old Kolab 2 format field map
    private $kolab2_fieldmap = array(
      // kolab       => roundcube
      'summary'      => 'title',
      'location'     => 'location',
      'body'         => 'description',
      'categories'   => 'categories',
      'sensitivity'  => 'sensitivity',
      'show-time-as' => 'free_busy',
      'priority'     => 'priority',
    );
    private $kolab2_rolemap = array(
        'required' => 'REQ-PARTICIPANT',
        'optional' => 'OPT-PARTICIPANT',
        'resource' => 'CHAIR',
    );
    private $kolab2_statusmap = array(
        'none'      => 'NEEDS-ACTION',
        'tentative' => 'TENTATIVE',
        'accepted'  => 'CONFIRMED',
        'accepted'  => 'ACCEPTED',
        'declined'  => 'DECLINED',
    );
    private $kolab2_weekdaymap = array('MO'=>'monday', 'TU'=>'tuesday', 'WE'=>'wednesday', 'TH'=>'thursday', 'FR'=>'friday', 'SA'=>'saturday', 'SU'=>'sunday');
    private $kolab2_monthmap = array('', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december');


    /**
     * Clones into an instance of libcalendaring's extended EventCal class
     *
     * @return mixed EventCal object or false on failure
     */
    public function to_libcal()
    {
        return false;
    }

    /**
     * Set event properties to the kolabformat object
     *
     * @param array  Event data as hash array
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

        // convert dates into a structure Horde understands
        $is_allday = intval($object['allday']);
        $this->kolab_object['start-date'] = self::horde_datetime($object['start'], null, $is_allday);
        $this->kolab_object['end-date']   = self::horde_datetime($object['end'], null, $is_allday);

        // handle alarms
        $this->kolab_object['alarm'] = self::to_kolab2_alarm($object['alarms']);

        // recurr object/array
        if (count($object['recurrence']) > 1) {
            $ra = $object['recurrence'];

            // frequency and interval
            $this->kolab_object['recurrence'] = array(
                'cycle' => strtolower($ra['FREQ']),
                'interval' => intval($ra['INTERVAL']),
            );

            // range Type
            if ($ra['UNTIL']) {
                $this->kolab_object['recurrence']['range-type'] = 'date';
                $this->kolab_object['recurrence']['range'] = $ra['UNTIL'];
            }
            if ($ra['COUNT']) {
                $this->kolab_object['recurrence']['range-type'] = 'number';
                $this->kolab_object['recurrence']['range'] = intval($ra['COUNT']);
            }

            // WEEKLY
            if ($ra['FREQ'] == 'WEEKLY') {
                if ($ra['BYDAY']) {
                    foreach (explode(',', $ra['BYDAY']) as $day)
                        $this->kolab_object['recurrence']['day'][] = $this->kolab2_weekdaymap[$day];
                }
                else {
                    // use weekday of start date if empty
                    $this->kolab_object['recurrence']['day'][] = strtolower($object['start']->format('l'));
                }
            }

            // MONTHLY (hack to follow Horde logic)
            if ($ra['FREQ'] == 'MONTHLY') {
                if ($ra['BYDAY'] && preg_match('/(-?[1-4])([A-Z]+)/', $ra['BYDAY'], $m)) {
                    $this->kolab_object['recurrence']['daynumber'] = $m[1];
                    $this->kolab_object['recurrence']['day'] = array($this->kolab2_weekdaymap[$m[2]]);
                    $this->kolab_object['recurrence']['cycle'] = 'monthly';
                    $this->kolab_object['recurrence']['type']  = 'weekday';
                }
                else {
                    $this->kolab_object['recurrence']['daynumber'] = preg_match('/^\d+$/', $ra['BYMONTHDAY']) ? $ra['BYMONTHDAY'] : $object['start']->format('j');
                    $this->kolab_object['recurrence']['cycle'] = 'monthly';
                    $this->kolab_object['recurrence']['type']  = 'daynumber';
                }
            }

            // YEARLY
            if ($ra['FREQ'] == 'YEARLY') {
                if (!$ra['BYMONTH'])
                    $ra['BYMONTH'] = $object['start']->format('n');

                $this->kolab_object['recurrence']['cycle'] = 'yearly';
                $this->kolab_object['recurrence']['month'] = $this->month_map[intval($ra['BYMONTH'])];

                if ($ra['BYDAY'] && preg_match('/(-?[1-4])([A-Z]+)/', $ra['BYDAY'], $m)) {
                    $this->kolab_object['recurrence']['type'] = 'weekday';
                    $this->kolab_object['recurrence']['daynumber'] = $m[1];
                    $this->kolab_object['recurrence']['day'] = array($this->kolab2_weekdaymap[$m[2]]);
                }
                else {
                    $this->kolab_object['recurrence']['type'] = 'monthday';
                    $this->kolab_object['recurrence']['daynumber'] = $object['start']->format('j');
                }
            }

            // exclusions
            foreach ((array)$ra['EXDATE'] as $excl) {
                $this->kolab_object['recurrence']['exclusion'][] = $excl->format('Y-m-d');
            }
        }
        else if (isset($object['recurrence']))
            unset($this->kolab_object['recurrence']);

        // process event attendees
        $status_map = array_flip($this->kolab2_statusmap);
        $role_map = array_flip($this->kolab2_rolemap);
        $this->kolab_object['attendee'] = array();
        foreach ((array)$object['attendees'] as $attendee) {
            $role = $attendee['role'];
            if ($role == 'ORGANIZER') {
                $this->kolab_object['organizer'] = array(
                    'display-name' => $attendee['name'],
                    'smtp-address' => $attendee['email'],
                );
            }
            else {
                $this->kolab_object['attendee'][] = array(
                    'display-name' => $attendee['name'],
                    'smtp-address' => $attendee['email'],
                    'status'       => $status_map[$attendee['status']],
                    'role'         => $role_map[$role],
                    'request-response' => $attendee['rsvp'],
                );
            }
        }

        // clear old cid: list attachments
        $links = array();
        foreach ((array)$this->kolab_object['link-attachment'] as $i => $url) {
            if (strpos($url, 'cid:') !== 0)
                $links[] = $url;
        }
        foreach ((array)$object['_attachments'] as $key => $attachment) {
            if ($attachment)
                $links[] = 'cid:' . $key;
        }
        $this->kolab_object['link-attachment'] = $links;

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return !empty($this->data['uid']) && $this->data['start'] && $this->data['end'];
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = array();

        foreach ((array)$this->data['categories'] as $cat) {
            $tags[] = rcube_utils::normalize_string($cat);
        }

        if (!empty($this->data['alarms'])) {
            $tags[] = 'x-has-alarms';
        }

        return $tags;
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($rec)
    {
        // convert recurrence rules into internal pseudo-vcalendar format
        if ($recurrence = $rec['recurrence']) {
            $rrule = array(
                'FREQ' => strtoupper($recurrence['cycle']),
                'INTERVAL' => intval($recurrence['interval']),
            );

            if (is_object($recurrence['range']) && is_a($recurrence['range'], 'DateTime'))
                $rrule['UNTIL'] = $recurrence['range'];
            if (!empty($recurrence['range']) && is_numeric($recurrence['range']))
                $rrule['COUNT'] = intval($recurrence['range']);

            if ($recurrence['day']) {
                $byday = array();
                $prefix = ($rrule['FREQ'] == 'MONTHLY' || $rrule['FREQ'] == 'YEARLY') ? intval($recurrence['daynumber'] ? $recurrence['daynumber'] : 1) : '';
                foreach ($recurrence['day'] as $day)
                    $byday[] = $prefix . substr(strtoupper($day), 0, 2);
                $rrule['BYDAY'] = join(',', $byday);
            }
            if ($recurrence['daynumber']) {
                if ($recurrence['type'] == 'monthday' || $recurrence['type'] == 'daynumber')
                    $rrule['BYMONTHDAY'] = $recurrence['daynumber'];
                else if ($recurrence['type'] == 'yearday')
                    $rrule['BYYEARDAY'] = $recurrence['daynumber'];
            }
            if ($recurrence['month']) {
                $monthmap = array_flip($this->kolab2_monthmap);
                $rrule['BYMONTH'] = strtolower($monthmap[$recurrence['month']]);
            }

            if ($recurrence['exclusion']) {
                foreach ((array)$recurrence['exclusion'] as $excl)
                    $rrule['EXDATE'][] = is_a($excl, 'DateTime') ? $excl : date_create($excl . date(' H:i:s', $rec['start-date']));  // use time of event start
            }
        }

        $attendees = array();
        if ($rec['organizer']) {
            $attendees[] = array(
                'role' => 'ORGANIZER',
                'name' => $rec['organizer']['display-name'],
                'email' => $rec['organizer']['smtp-address'],
                'status' => 'ACCEPTED',
            );
            $_attendees .= $rec['organizer']['display-name'] . ' ' . $rec['organizer']['smtp-address'] . ' ';
        }

        foreach ((array)$rec['attendee'] as $attendee) {
            $attendees[] = array(
                'role' => $this->kolab2_rolemap[$attendee['role']],
                'name' => $attendee['display-name'],
                'email' => $attendee['smtp-address'],
                'status' => $this->kolab2_statusmap[$attendee['status']],
                'rsvp' => $attendee['request-response'],
            );
            $_attendees .= $rec['organizer']['display-name'] . ' ' . $rec['organizer']['smtp-address'] . ' ';
        }

        $this->data = array(
            'uid' => $rec['uid'],
            'changed' => self::php_datetime($rec['last-modification-date']),
            'title' => $rec['summary'],
            'location' => $rec['location'],
            'description' => $rec['body'],
            'start' => self::php_datetime($rec['start-date']),
            'end'   => self::php_datetime($rec['end-date']),
            'recurrence' => $rrule,
            'alarms' => self::from_kolab2_alarm($rec['alarm']),
            'categories' => $rec['categories'],
            'attendees' => $attendees,
            'free_busy' => $rec['show-time-as'],
            'priority' => $rec['priority'],
            'sensitivity' => $rec['sensitivity'],
            'changed' => $rec['last-modification-date'],
        );

        $this->data['allday'] = $this->data['start']->_dateonly;

        // assign current timezone to event start/end which are most likely in UTC
        // $this->data['start']->setTimezone(self::$timezone);
        // $this->data['end']->setTimezone(self::$timezone);
    }
}
