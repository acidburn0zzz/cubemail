<?php

/**
 * Kolab calendar storage class simulating a virtual user calendar
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

class kolab_user_calendar extends kolab_calendar
{
  public $id = 'unknown';
  public $ready = false;
  public $readonly = true;
  public $attachments = false;
  public $subscriptions = false;

  protected $userdata = array();


  /**
   * Default constructor
   */
  public function __construct($user_or_folder, $calendar)
  {
    $this->cal = $calendar;

    // full user record is provided
    if (is_array($user_or_folder)) {
      $this->userdata = $user_or_folder;
      $this->storage = new kolab_storage_folder_user($this->userdata['kolabtargetfolder'], '', $this->userdata);
    }
    else {  // get user record from LDAP
      $this->storage = new kolab_storage_folder_user($user_or_folder);
      $this->userdata = $this->storage->ldaprec;
    }

    $this->ready = !empty($this->userdata['kolabtargetfolder']);

    if ($this->ready) {
      // ID is derrived from the user's kolabtargetfolder attribute
      $this->id = kolab_storage::folder_id($this->userdata['kolabtargetfolder'], true);
      $this->imap_folder = $this->userdata['kolabtargetfolder'];
      $this->name = $this->storage->get_name();
      $this->parent = '';  // user calendars are top level

      // user-specific alarms settings win
      $prefs = $this->cal->rc->config->get('kolab_calendars', array());
      if (isset($prefs[$this->id]['showalarms']))
        $this->alarms = $prefs[$this->id]['showalarms'];
    }
  }


  /**
   * Getter for a nice and human readable name for this calendar
   *
   * @return string Name of this calendar
   */
  public function get_name()
  {
    return $this->userdata['name'] ?: $this->userdata['mail'];
  }


  /**
   * Getter for the IMAP folder owner
   *
   * @return string Name of the folder owner
   */
  public function get_owner()
  {
    return $this->userdata['mail'];
  }


  /**
   * Getter for the name of the namespace to which the IMAP folder belongs
   *
   * @return string Name of the namespace (personal, other, shared)
   */
  public function get_namespace()
  {
    return 'other user';
  }


  /**
   * Getter for the top-end calendar folder name (not the entire path)
   *
   * @return string Name of this calendar
   */
  public function get_foldername()
  {
    return $this->get_name();
  }

  /**
   * Return color to display this calendar
   */
  public function get_color()
  {
    // calendar color is stored in local user prefs
    $prefs = $this->cal->rc->config->get('kolab_calendars', array());

    if (!empty($prefs[$this->id]) && !empty($prefs[$this->id]['color']))
      return $prefs[$this->id]['color'];

    return 'cc0000';
  }

  /**
   * Compose an URL for CalDAV access to this calendar (if configured)
   */
  public function get_caldav_url()
  {
    return false;
  }


  /**
   * Update properties of this calendar folder
   *
   * @see calendar_driver::edit_calendar()
   */
  public function update(&$prop)
  {
    // don't change anything.
    // let kolab_driver save props in local prefs
    return $prop['id'];
  }


  /**
   * Getter for a single event object
   */
  public function get_event($id)
  {
    // TODO: implement this
    return $this->events[$id];
  }


  /**
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @param  boolean Include virtual events (optional)
   * @param  array   Additional parameters to query storage
   * @return array A list of event records
   */
  public function list_events($start, $end, $search = null, $virtual = 1, $query = array())
  {
    // TODO: implement this
    console('kolab_user_calendar::list_events()');
    return array();
  }


  /**
   * Create a new event record
   *
   * @see calendar_driver::new_event()
   * 
   * @return mixed The created record ID on success, False on error
   */
  public function insert_event($event)
  {
    return false;
  }

  /**
   * Update a specific event record
   *
   * @see calendar_driver::new_event()
   * @return boolean True on success, False on error
   */

  public function update_event($event, $exception_id = null)
  {
    return false;
  }

  /**
   * Delete an event record
   *
   * @see calendar_driver::remove_event()
   * @return boolean True on success, False on error
   */
  public function delete_event($event, $force = true)
  {
    return false;
  }

  /**
   * Restore deleted event record
   *
   * @see calendar_driver::undelete_event()
   * @return boolean True on success, False on error
   */
  public function restore_event($event)
  {
    return false;
  }


  /**
   * Convert from Kolab_Format to internal representation
   */
  private function _to_rcube_event($record)
  {
    $record['id'] = $record['uid'];
    $record['calendar'] = $this->id;

    // TODO: implement this

    return $record;
  }

}
