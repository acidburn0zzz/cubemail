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
        if (PEAR::isError($rec))
            return;

        $start_time = date('H:i:s', $rec['start-date']);
        $allday = $rec['_is_all_day'] || ($start_time == '00:00:00' && $start_time == date('H:i:s', $rec['end-date']));

        // in Roundcube all-day events go from 12:00 to 13:00
        if ($allday) {
            $now = new DateTime('now', self::$timezone);
            $gmt_offset = $now->getOffset();

            $rec['start-date'] += 12 * 3600;
            $rec['end-date']   -= 11 * 3600;
            $rec['end-date']   -= $gmt_offset - date('Z', $rec['end-date']);    // shift times from server's timezone to user's timezone
            $rec['start-date'] -= $gmt_offset - date('Z', $rec['start-date']);  // because generated with mktime() in Horde_Kolab_Format_Date::decodeDate()
            // sanity check
            if ($rec['end-date'] <= $rec['start-date'])
              $rec['end-date'] += 86400;
        }

        // convert alarm time into internal format
        if ($rec['alarm']) {
            $alarm_value = $rec['alarm'];
            $alarm_unit = 'M';
            if ($rec['alarm'] % 1440 == 0) {
                $alarm_value /= 1440;
                $alarm_unit = 'D';
            }
            else if ($rec['alarm'] % 60 == 0) {
                $alarm_value /= 60;
                $alarm_unit = 'H';
            }
            $alarm_value *= -1;
        }

        // convert recurrence rules into internal pseudo-vcalendar format
        if ($recurrence = $rec['recurrence']) {
            $rrule = array(
                'FREQ' => strtoupper($recurrence['cycle']),
                'INTERVAL' => intval($recurrence['interval']),
            );

            if ($recurrence['range-type'] == 'number')
                $rrule['COUNT'] = intval($recurrence['range']);
            else if ($recurrence['range-type'] == 'date')
                $rrule['UNTIL'] = date_create('@'.$recurrence['range']);

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
                    $rrule['EXDATE'][] = date_create($excl . date(' H:i:s', $rec['start-date']));  // use time of event start
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
            'title' => $rec['summary'],
            'location' => $rec['location'],
            'description' => $rec['body'],
            'start' => new DateTime('@'.$rec['start-date']),
            'end'   => new DateTime('@'.$rec['end-date']),
            'allday' => $allday,
            'recurrence' => $rrule,
            'alarms' => $alarm_value . $alarm_unit,
            'categories' => explode(',', $rec['categories']),
            'attachments' => $attachments,
            'attendees' => $attendees,
            'free_busy' => $rec['show-time-as'],
            'priority' => $rec['priority'],
            'sensitivity' => $rec['sensitivity'],
            'changed' => $rec['last-modification-date'],
        );

        // assign current timezone to event start/end
        $this->data['start']->setTimezone(self::$timezone);
        $this->data['end']->setTimezone(self::$timezone);
    }
}
