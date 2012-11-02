<?php

/**
 * Recurrence computation class for the Calendar plugin
 *
 * Uitility class to compute instances of recurring events.
 *
 * @version 0.7.3
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @package calendar
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * Additional permission is granted to distribute and use this file under
 * the terms of the GNU General Public License Version 2 in conjunction with
 * the Roundcube Web Mailer Version 0.7 as distributed by the Roundcube
 * Community (http://roundcube.net).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
class calendar_recurrence
{
  private $cal;
  private $event;
  private $engine;
  private $tz_offset = 0;
  private $hour = 0;

  /**
   * Default constructor
   *
   * @param object calendar The calendar plugin instance
   * @param array The event object to operate on
   */
  function __construct($cal, $event)
  {
    $this->cal = $cal;
    $dtstart = clone $event['start'];
    $dtstart->setTimezone($cal->user_timezone);

    // use Horde classes to compute recurring instances
    // TODO: replace with something that has less than 6'000 lines of code
    require_once($this->cal->home . '/lib/Horde_Date_Recurrence.php');

    $this->event = $event;
    $this->engine = new Horde_Date_Recurrence($dtstart->format('Y-m-d H:i:s'));
    $this->engine->fromRRule20(calendar::to_rrule($event['recurrence']));

    if (is_array($event['recurrence']['EXDATE'])) {
      foreach ($event['recurrence']['EXDATE'] as $exdate)
        $this->engine->addException($exdate->format('Y'), $exdate->format('n'), $exdate->format('j'));
    }

    $this->tz_offset = $event['allday'] ? $this->cal->gmt_offset - date('Z') : 0;
    $this->next = new Horde_Date($dtstart->format('Y-m-d H:i:s'));
    $this->hour = $this->next->hour;
  }

  /**
   * Get timestamp of the next occurence of this event
   *
   * @return mixed DateTime or False if recurrence ended
   */
  public function next_start()
  {
    $time = false;
    if ($this->next && ($next = $this->engine->nextActiveRecurrence(array('year' => $this->next->year, 'month' => $this->next->month, 'mday' => $this->next->mday + 1, 'hour' => $this->next->hour, 'min' => $this->next->min, 'sec' => $this->next->sec)))) {
      # fix time for all-day events
      if ($this->event['allday']) {
        $next->hour = $this->hour;
        $next->min = 0;
      }
      $time = new DateTime($next->rfc3339DateTime(), $this->cal->user_timezone);
      $this->next = $next;
    }

    return $time;
  }

}
