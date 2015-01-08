<?php

/**
 * libcalendaring plugin's utility functions tests
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

class libcalendaring_test extends PHPUnit_Framework_TestCase
{
    function setUp()
    {
        require_once __DIR__ . '/../libcalendaring.php';
    }

    /**
     * libcalendaring::part_is_vcalendar()
     */
    function test_part_is_vcalendar()
    {
        $part = new StdClass;
        $part->mimetype = 'text/plain';
        $part->filename = 'event.ics';

        $this->assertFalse(libcalendaring::part_is_vcalendar($part));

        $part->mimetype = 'text/calendar';
        $this->assertTrue(libcalendaring::part_is_vcalendar($part));

        $part->mimetype = 'text/x-vcalendar';
        $this->assertTrue(libcalendaring::part_is_vcalendar($part));

        $part->mimetype = 'application/ics';
        $this->assertTrue(libcalendaring::part_is_vcalendar($part));

        $part->mimetype = 'application/x-any';
        $this->assertTrue(libcalendaring::part_is_vcalendar($part));
    }

    /**
     * libcalendaring::to_rrule()
     */
    function test_to_rrule()
    {
        $rrule = array(
            'FREQ' => 'MONTHLY',
            'BYDAY' => '2WE',
            'INTERVAL' => 2,
            'UNTIL' => new DateTime('2025-05-01 18:00:00 CEST'),
        );

        $s = libcalendaring::to_rrule($rrule);

        $this->assertRegExp('/FREQ='.$rrule['FREQ'].'/',          $s, "Recurrence Frequence");
        $this->assertRegExp('/INTERVAL='.$rrule['INTERVAL'].'/',  $s, "Recurrence Interval");
        $this->assertRegExp('/BYDAY='.$rrule['BYDAY'].'/',        $s, "Recurrence BYDAY");
        $this->assertRegExp('/UNTIL=20250501T160000Z/',           $s, "Recurrence End date (in UTC)");
    }

}

