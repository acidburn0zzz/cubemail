<?php

/**
 * Kolab driver for the Calendar plugin
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

require_once(dirname(__FILE__) . '/kolab_calendar.php');
require_once(dirname(__FILE__) . '/kolab_user_calendar.php');

class kolab_driver extends calendar_driver
{
  // features this backend supports
  public $alarms = true;
  public $attendees = true;
  public $freebusy = true;
  public $attachments = true;
  public $undelete = true;
  public $alarm_types = array('DISPLAY','AUDIO');
  public $categoriesimmutable = true;

  private $rc;
  private $cal;
  private $calendars;
  private $has_writeable = false;
  private $freebusy_trigger = false;

  /**
   * Default constructor
   */
  public function __construct($cal)
  {
    $cal->require_plugin('libkolab');

    $this->cal = $cal;
    $this->rc = $cal->rc;
    $this->_read_calendars();
    
    $this->cal->register_action('push-freebusy', array($this, 'push_freebusy'));
    $this->cal->register_action('calendar-acl', array($this, 'calendar_acl'));
    
    $this->freebusy_trigger = $this->rc->config->get('calendar_freebusy_trigger', false);

    if (kolab_storage::$version == '2.0') {
        $this->alarm_types = array('DISPLAY');
        $this->alarm_absolute = false;
    }

    // calendar uses fully encoded identifiers
    kolab_storage::$encode_ids = true;
  }


  /**
   * Read available calendars from server
   */
  private function _read_calendars()
  {
    // already read sources
    if (isset($this->calendars))
      return $this->calendars;

    // get all folders that have "event" type, sorted by namespace/name
    $folders = kolab_storage::sort_folders(kolab_storage::get_folders('event') + kolab_storage::get_user_folders(true));
    $this->calendars = array();

    foreach ($folders as $folder) {
      if ($folder instanceof kolab_storage_folder_user)
        $calendar = new kolab_user_calendar($folder->name, $this->cal);
      else
        $calendar = new kolab_calendar($folder->name, $this->cal);

      if ($calendar->ready) {
        $this->calendars[$calendar->id] = $calendar;
        if (!$calendar->readonly)
          $this->has_writeable = true;
      }
    }

    return $this->calendars;
  }

  /**
   * Get a list of available calendars from this source
   *
   * @param bool $active   Return only active calendars
   * @param bool $personal Return only personal calendars
   * @param object $tree   Reference to hierarchical folder tree object
   *
   * @return array List of calendars
   */
  public function list_calendars($active = false, $personal = false, &$tree = null)
  {
    // attempt to create a default calendar for this user
    if (!$this->has_writeable) {
      if ($this->create_calendar(array('name' => 'Calendar', 'color' => 'cc0000'))) {
         unset($this->calendars);
        $this->_read_calendars();
      }
    }

    $folders = $this->filter_calendars(false, $active, $personal);
    $calendars = $names = array();

    // include virtual folders for a full folder tree
    if (!is_null($tree))
      $folders = kolab_storage::folder_hierarchy($folders, $tree);

    foreach ($folders as $id => $cal) {
      $fullname = $cal->get_name();
      $listname = $cal->get_foldername();
      $imap_path = explode('/', $cal->name);
      $topname = array_pop($imap_path);
      $parent_id = kolab_storage::folder_id(join('/', $imap_path));

      // turn a kolab_storage_folder object into a kolab_calendar
      if ($cal instanceof kolab_storage_folder) {
          $cal = new kolab_calendar($cal->name, $this->cal);
          $this->calendars[$cal->id] = $cal;
      }

      // special handling for user or virtual folders
      if ($cal instanceof kolab_storage_folder_user) {
        $calendars[$cal->id] = array(
          'id' => $cal->id,
          'name' => kolab_storage::object_name($fullname),
          'listname' => $listname,
          'editname' => $cal->get_foldername(),
          'color'    => $cal->get_color(),
          'active'   => $cal->is_active(),
          'owner'    => $cal->get_owner(),
          'virtual'  => false,
          'readonly' => true,
          'group'    => 'other',
          'class'    => 'user',
        );
      }
      else if ($cal->virtual) {
        $calendars[$cal->id] = array(
          'id' => $cal->id,
          'name' => $fullname,
          'listname' => $listname,
          'editname' => $cal->get_foldername(),
          'virtual' => true,
          'readonly' => true,
          'group'    => $cal->get_namespace(),
          'class'    => 'folder',
        );
      }
      else {
        $calendars[$cal->id] = array(
          'id'       => $cal->id,
          'name'     => $fullname,
          'listname' => $listname,
          'editname' => $cal->get_foldername(),
          'color'    => $cal->get_color(),
          'readonly' => $cal->readonly,
          'showalarms' => $cal->alarms,
          'group'    => $cal->get_namespace(),
          'default'  => $cal->default,
          'active'   => $cal->is_active(),
          'owner'    => $cal->get_owner(),
          'children' => true,  // TODO: determine if that folder indeed has child folders
          'parent'   => $parent_id,
          'caldavurl' => $cal->get_caldav_url(),
        );
      }

      if ($cal->subscriptions) {
        $calendars[$cal->id]['subscribed'] = (bool)$cal->is_subscribed();
      }
    }

    // append the virtual birthdays calendar
    if ($this->rc->config->get('calendar_contact_birthdays', false)) {
      $id = self::BIRTHDAY_CALENDAR_ID;
      $prefs = $this->rc->config->get('kolab_calendars', array());  // read local prefs
      if (!$active || $prefs[$id]['active']) {
        $calendars[$id] = array(
          'id'         => $id,
          'name'       => $this->cal->gettext('birthdays'),
          'listname'   => $this->cal->gettext('birthdays'),
          'color'      => $prefs[$id]['color'],
          'active'     => $prefs[$id]['active'],
          'showalarms' => (bool)$this->rc->config->get('calendar_birthdays_alarm_type'),
          'group'      => 'birthdays',
          'readonly'   => true,
          'default'    => false,
          'children'   => false,
        );
      }
    }

    return $calendars;
  }

  /**
   * Get list of calendars according to specified filters
   *
   * @param bool $writeable Return only writeable calendars
   * @param bool $active   Return only active calendars
   * @param bool $personal Return only personal calendars
   *
   * @return array List of calendars
   */
  protected function filter_calendars($writeable = false, $active = false, $personal = false)
  {
    $calendars = array();

    $plugin = $this->rc->plugins->exec_hook('calendar_list_filter', array(
      'list' => $this->calendars, 'calendars' => $calendars,
      'writeable' => $writeable, 'active' => $active, 'personal' => $personal,
    ));

    if ($plugin['abort']) {
      return $plugin['calendars'];
    }

    foreach ($this->calendars as $cal) {
      if (!$cal->ready) {
        continue;
      }
      if ($writeable && $cal->readonly) {
        continue;
      }
      if ($active && !$cal->is_active()) {
        continue;
      }
      if ($personal && $cal->get_namespace() != 'personal') {
        continue;
      }
      $calendars[$cal->id] = $cal;
    }

    return $calendars;
  }


  /**
   * Get the kolab_calendar instance for the given calendar ID
   *
   * @param string Calendar identifier (encoded imap folder name)
   * @return object kolab_calendar Object nor null if calendar doesn't exist
   */
  protected function get_calendar($id)
  {
    // create calendar object if necesary
    if (!$this->calendars[$id] && $id !== self::BIRTHDAY_CALENDAR_ID) {
      $calendar = kolab_calendar::factory($id, $this->cal);
      if ($calendar->ready)
        $this->calendars[$calendar->id] = $calendar;
    }

    return $this->calendars[$id];
  }

  /**
   * Create a new calendar assigned to the current user
   *
   * @param array Hash array with calendar properties
   *    name: Calendar name
   *   color: The color of the calendar
   * @return mixed ID of the calendar on success, False on error
   */
  public function create_calendar($prop)
  {
    $prop['type'] = 'event';
    $prop['active'] = true;
    $prop['subscribed'] = true;
    $folder = kolab_storage::folder_update($prop);

    if ($folder === false) {
      $this->last_error = $this->cal->gettext(kolab_storage::$last_error);
      return false;
    }

    // create ID
    $id = kolab_storage::folder_id($folder);

    // save color in user prefs (temp. solution)
    $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());

    if (isset($prop['color']))
      $prefs['kolab_calendars'][$id]['color'] = $prop['color'];
    if (isset($prop['showalarms']))
      $prefs['kolab_calendars'][$id]['showalarms'] = $prop['showalarms'] ? true : false;

    if ($prefs['kolab_calendars'][$id])
      $this->rc->user->save_prefs($prefs);

    return $id;
  }


  /**
   * Update properties of an existing calendar
   *
   * @see calendar_driver::edit_calendar()
   */
  public function edit_calendar($prop)
  {
    if ($prop['id'] && ($cal = $this->get_calendar($prop['id']))) {
      $id = $cal->update($prop);
    }
    else {
      $id = $prop['id'];
    }

    // fallback to local prefs
    $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
    unset($prefs['kolab_calendars'][$prop['id']]['color'], $prefs['kolab_calendars'][$prop['id']]['showalarms']);

    if (isset($prop['color']))
      $prefs['kolab_calendars'][$id]['color'] = $prop['color'];

    if (isset($prop['showalarms']) && $id == self::BIRTHDAY_CALENDAR_ID)
      $prefs['calendar_birthdays_alarm_type'] = $prop['showalarms'] ? $this->alarm_types[0] : '';
    else if (isset($prop['showalarms']))
      $prefs['kolab_calendars'][$id]['showalarms'] = $prop['showalarms'] ? true : false;

    if (!empty($prefs['kolab_calendars'][$id]))
      $this->rc->user->save_prefs($prefs);

    return true;
  }


  /**
   * Set active/subscribed state of a calendar
   *
   * @see calendar_driver::subscribe_calendar()
   */
  public function subscribe_calendar($prop)
  {
    if ($prop['id'] && ($cal = $this->get_calendar($prop['id']))) {
      $ret = false;
      if (isset($prop['permanent']))
        $ret |= $cal->storage->subscribe($prop['permanent']);
      if (isset($prop['active']))
        $ret |= $cal->storage->activate($prop['active']);
      return $ret;
    }
    else {
      // save state in local prefs
      $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
      $prefs['kolab_calendars'][$prop['id']]['active'] = (bool)$prop['active'];
      $this->rc->user->save_prefs($prefs);
      return true;
    }

    return false;
  }


  /**
   * Delete the given calendar with all its contents
   *
   * @see calendar_driver::remove_calendar()
   */
  public function remove_calendar($prop)
  {
    if ($prop['id'] && ($cal = $this->get_calendar($prop['id']))) {
      $folder = $cal->get_realname();
      // TODO: unsubscribe if no admin rights
      if (kolab_storage::folder_delete($folder)) {
        // remove color in user prefs (temp. solution)
        $prefs['kolab_calendars'] = $this->rc->config->get('kolab_calendars', array());
        unset($prefs['kolab_calendars'][$prop['id']]);

        $this->rc->user->save_prefs($prefs);
        return true;
      }
      else
        $this->last_error = kolab_storage::$last_error;
    }

    return false;
  }


  /**
   * Search for shared or otherwise not listed calendars the user has access
   *
   * @param string Search string
   * @param string Section/source to search
   * @return array List of calendars
   */
  public function search_calendars($query, $source)
  {
    if (!kolab_storage::setup())
      return array();

    $this->calendars = array();

    // find unsubscribed IMAP folders that have "event" type
    if ($source == 'folders') {
      foreach ((array)kolab_storage::search_folders('event', $query, array('other')) as $folder) {
        $calendar = new kolab_calendar($folder->name, $this->cal);
        $this->calendars[$calendar->id] = $calendar;
      }
    }
    // find other user's virtual calendars
    else if ($source == 'users') {
      foreach (kolab_storage::search_users($query, 0) as $user) {
        $calendar = new kolab_user_calendar($user, $this->cal);
        $this->calendars[$calendar->id] = $calendar;

        // search for calendar folders shared by this user
        foreach (kolab_storage::list_user_folders($user, 'event', false) as $foldername) {
          if (1 || !kolab_storage::folder_is_subscribed($foldername, true)) {
            $cal = new kolab_calendar($foldername, $this->cal);
            $this->calendars[$cal->id] = $cal;
          }
        }
      }
    }

    // don't list the birthday calendar
    $this->rc->config->set('calendar_contact_birthdays', false);

    return $this->list_calendars();
  }


  /**
   * Fetch a single event
   *
   * @see calendar_driver::get_event()
   * @return array Hash array with event properties, false if not found
   */
  public function get_event($event, $writeable = false, $active = false, $personal = false)
  {
    if (is_array($event)) {
      $id = $event['id'] ? $event['id'] : $event['uid'];
      $cal = $event['calendar'];
    }
    else {
      $id = $event;
    }

    if ($cal) {
      if ($storage = $this->get_calendar($cal)) {
        return $storage->get_event($id);
      }
    }
    // iterate over all calendar folders and search for the event ID
    else {
      foreach ($this->filter_calendars($writeable, $active, $personal) as $calendar) {
        if ($result = $calendar->get_event($id)) {
          return $result;
        }
      }
    }

    return false;
  }

  /**
   * Add a single event to the database
   *
   * @see calendar_driver::new_event()
   */
  public function new_event($event)
  {
    if (!$this->validate($event))
      return false;

    $cid = $event['calendar'] ? $event['calendar'] : reset(array_keys($this->calendars));
    if ($storage = $this->get_calendar($cid)) {
      // handle attachments to add
      if (!empty($event['attachments'])) {
        foreach ($event['attachments'] as $idx => $attachment) {
          // we'll read file contacts into memory, Horde/Kolab classes does the same
          // So we cannot save memory, rcube_imap class can do this better
          $event['attachments'][$idx]['content'] = $attachment['data'] ? $attachment['data'] : file_get_contents($attachment['path']);
        }
      }

      $success = $storage->insert_event($event);
      
      if ($success && $this->freebusy_trigger) {
        $this->rc->output->command('plugin.ping_url', array('action' => 'calendar/push-freebusy', 'source' => $storage->id));
        $this->freebusy_trigger = false; // disable after first execution (#2355)
      }
      
      return $success;
    }

    return false;
  }

  /**
   * Update an event entry with the given data
   *
   * @see calendar_driver::new_event()
   * @return boolean True on success, False on error
   */
  public function edit_event($event)
  {
    return $this->update_event($event);
  }

  /**
   * Move a single event
   *
   * @see calendar_driver::move_event()
   * @return boolean True on success, False on error
   */
  public function move_event($event)
  {
    if (($storage = $this->get_calendar($event['calendar'])) && ($ev = $storage->get_event($event['id']))) {
      unset($ev['sequence']);
      return $this->update_event($event + $ev);
    }

    return false;
  }

  /**
   * Resize a single event
   *
   * @see calendar_driver::resize_event()
   * @return boolean True on success, False on error
   */
  public function resize_event($event)
  {
    if (($storage = $this->get_calendar($event['calendar'])) && ($ev = $storage->get_event($event['id']))) {
      unset($ev['sequence']);
      return $this->update_event($event + $ev);
    }

    return false;
  }

  /**
   * Remove a single event
   *
   * @param array   Hash array with event properties:
   *      id: Event identifier
   * @param boolean Remove record(s) irreversible (mark as deleted otherwise)
   *
   * @return boolean True on success, False on error
   */
  public function remove_event($event, $force = true)
  {
    $success = false;
    $savemode = $event['_savemode'];

    if (($storage = $this->get_calendar($event['calendar'])) && ($event = $storage->get_event($event['id']))) {
      $event['_savemode'] = $savemode;
      $savemode = 'all';
      $master = $event;

      $this->rc->session->remove('calendar_restore_event_data');

      // read master if deleting a recurring event
      if ($event['recurrence'] || $event['recurrence_id']) {
        $master = $event['recurrence_id'] ? $storage->get_event($event['recurrence_id']) : $event;
        $savemode = $event['_savemode'];
      }

      // removing an exception instance
      if ($event['recurrence_id']) {
        $i = $event['_instance'] - 1;
        if (!empty($master['recurrence']['EXCEPTIONS'][$i])) {
          unset($master['recurrence']['EXCEPTIONS'][$i]);
        }
      }

      switch ($savemode) {
        case 'current':
          $_SESSION['calendar_restore_event_data'] = $master;

          // removing the first instance => just move to next occurence
          if ($master['id'] == $event['id']) {
            $recurring = reset($storage->_get_recurring_events($event, $event['start'], null, $event['id'].'-1'));

            // no future instances found: delete the master event (bug #1677)
            if (!$recurring['start']) {
              $success = $storage->delete_event($master, $force);
              break;
            }

            $master['start'] = $recurring['start'];
            $master['end'] = $recurring['end'];
            if ($master['recurrence']['COUNT'])
              $master['recurrence']['COUNT']--;
          }
          // remove the matching RDATE entry
          else if ($master['recurrence']['RDATE']) {
            foreach ($master['recurrence']['RDATE'] as $j => $rdate) {
              if ($rdate->format('Ymd') == $event['start']->format('Ymd')) {
                unset($master['recurrence']['RDATE'][$j]);
                break;
              }
            }
          }
          else {  // add exception to master event
            $master['recurrence']['EXDATE'][] = $event['start'];
          }
          $success = $storage->update_event($master);
          break;

        case 'future':
          if ($master['id'] != $event['id']) {
            $_SESSION['calendar_restore_event_data'] = $master;
            
            // set until-date on master event
            $master['recurrence']['UNTIL'] = clone $event['start'];
            $master['recurrence']['UNTIL']->sub(new DateInterval('P1D'));
            unset($master['recurrence']['COUNT']);

            // if all future instances are deleted, remove recurrence rule entirely (bug #1677)
            if ($master['recurrence']['UNTIL']->format('Ymd') == $master['start']->format('Ymd')) {
              $master['recurrence'] = array();
            }
            // remove matching RDATE entries
            else if ($master['recurrence']['RDATE']) {
              foreach ($master['recurrence']['RDATE'] as $j => $rdate) {
                if ($rdate->format('Ymd') == $event['start']->format('Ymd')) {
                  $master['recurrence']['RDATE'] = array_slice($master['recurrence']['RDATE'], 0, $j);
                  break;
                }
              }
            }

            $success = $storage->update_event($master);
            break;
          }

        default:  // 'all' is default
          $success = $storage->delete_event($master, $force);
          break;
      }
    }

    if ($success && $this->freebusy_trigger)
      $this->rc->output->command('plugin.ping_url', array('action' => 'calendar/push-freebusy', 'source' => $storage->id));

    return $success;
  }

  /**
   * Restore a single deleted event
   *
   * @param array Hash array with event properties:
   *      id: Event identifier
   * @return boolean True on success, False on error
   */
  public function restore_event($event)
  {
    if ($storage = $this->get_calendar($event['calendar'])) {
      if (!empty($_SESSION['calendar_restore_event_data']))
        $success = $storage->update_event($_SESSION['calendar_restore_event_data']);
      else
        $success = $storage->restore_event($event);
      
      if ($success && $this->freebusy_trigger)
        $this->rc->output->command('plugin.ping_url', array('action' => 'calendar/push-freebusy', 'source' => $storage->id));
      
      return $success;
    }

    return false;
  }

  /**
   * Wrapper to update an event object depending on the given savemode
   */
  private function update_event($event)
  {
    if (!($storage = $this->get_calendar($event['calendar'])))
      return false;

    // move event to another folder/calendar
    if ($event['_fromcalendar'] && $event['_fromcalendar'] != $event['calendar']) {
      if (!($fromcalendar = $this->get_calendar($event['_fromcalendar'])))
        return false;

      if ($event['_savemode'] != 'new') {
        if (!$fromcalendar->storage->move($event['id'], $storage->get_realname()))
          return false;

        $fromcalendar = $storage;
      }
    }
    else
      $fromcalendar = $storage;

    $success = false;
    $savemode = 'all';
    $attachments = array();
    $old = $master = $fromcalendar->get_event($event['id']);

    if (!$old || !$old['start']) {
      rcube::raise_error(array(
        'code' => 600, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Failed to load event object to update: id=" . $event['id']),
        true, false);
      return false;
    }

    // delete existing attachment(s)
    if (!empty($event['deleted_attachments'])) {
      foreach ($event['deleted_attachments'] as $attachment) {
        if (!empty($old['attachments'])) {
          foreach ($old['attachments'] as $idx => $att) {
            if ($att['id'] == $attachment) {
              $old['attachments'][$idx]['_deleted'] = true;
            }
          }
        }
      }
      unset($event['deleted_attachments']);
    }

    // handle attachments to add
    if (!empty($event['attachments'])) {
      foreach ($event['attachments'] as $attachment) {
        // skip entries without content (could be existing ones)
        if (!$attachment['data'] && !$attachment['path'])
          continue;

        $attachments[] = array(
          'name' => $attachment['name'],
          'mimetype' => $attachment['mimetype'],
          'content' => $attachment['data'],
          'path' => $attachment['path'],
        );
      }
    }

    $event['attachments'] = array_merge((array)$old['attachments'], $attachments);

    // modify a recurring event, check submitted savemode to do the right things
    if ($old['recurrence'] || $old['recurrence_id']) {
      $master = $old['recurrence_id'] ? $fromcalendar->get_event($old['recurrence_id']) : $old;
      $savemode = $event['_savemode'];
    }

    // keep saved exceptions (not submitted by the client)
    if ($old['recurrence']['EXDATE'])
      $event['recurrence']['EXDATE'] = $old['recurrence']['EXDATE'];
    if ($old['recurrence']['EXCEPTIONS'])
      $event['recurrence']['EXCEPTIONS'] = $old['recurrence']['EXCEPTIONS'];

    switch ($savemode) {
      case 'new':
        // save submitted data as new (non-recurring) event
        $event['recurrence'] = array();
        $event['uid'] = $this->cal->generate_uid();
        
        // copy attachment data to new event
        foreach ((array)$event['attachments'] as $idx => $attachment) {
          if (!$attachment['data'])
            $attachment['data'] = $fromcalendar->get_attachment_body($attachment['id'], $event);
        }
        
        $success = $storage->insert_event($event);
        break;

      case 'future':
      case 'current':
        // recurring instances shall not store recurrence rules
        $event['recurrence'] = array();
        $event['thisandfuture'] = $savemode == 'future';

        // remove some internal properties which should not be saved
        unset($event['_savemode'], $event['_fromcalendar'], $event['_identity']);

        // save properties to a recurrence exception instance
        if ($old['recurrence_id']) {
            $i = $old['_instance'] - 1;
            if (!empty($master['recurrence']['EXCEPTIONS'][$i])) {
                $master['recurrence']['EXCEPTIONS'][$i] = $event;
                $success = $storage->update_event($master, $old['id']);
                break;
            }
        }

        $add_exception = true;

        // adjust matching RDATE entry if dates changed
        if ($savemode == 'current' && $master['recurrence']['RDATE'] && ($old_date = $old['start']->format('Ymd')) != $event['start']->format('Ymd')) {
          foreach ($master['recurrence']['RDATE'] as $j => $rdate) {
            if ($rdate->format('Ymd') == $old_date) {
              $master['recurrence']['RDATE'][$j] = $event['start'];
              sort($master['recurrence']['RDATE']);
              $add_exception = false;
              break;
            }
          }
        }

        // save as new exception to master event
        if ($add_exception) {
          $master['recurrence']['EXCEPTIONS'][] = $event;
        }
        $success = $storage->update_event($master);
        break;

      default:  // 'all' is default
        $event['id'] = $master['id'];
        $event['uid'] = $master['uid'];

        // use start date from master but try to be smart on time or duration changes
        $old_start_date = $old['start']->format('Y-m-d');
        $old_start_time = $old['allday'] ? '' : $old['start']->format('H:i');
        $old_duration = $old['end']->format('U') - $old['start']->format('U');
        
        $new_start_date = $event['start']->format('Y-m-d');
        $new_start_time = $event['allday'] ? '' : $event['start']->format('H:i');
        $new_duration = $event['end']->format('U') - $event['start']->format('U');
        
        $diff = $old_start_date != $new_start_date || $old_start_time != $new_start_time || $old_duration != $new_duration;
        
        // shifted or resized
        if ($diff && ($old_start_date == $new_start_date || $old_duration == $new_duration)) {
          $event['start'] = $master['start']->add($old['start']->diff($event['start']));
          $event['end'] = clone $event['start'];
          $event['end']->add(new DateInterval('PT'.$new_duration.'S'));
          
          // remove fixed weekday, will be re-set to the new weekday in kolab_calendar::update_event()
          if ($old_start_date != $new_start_date) {
            if (strlen($event['recurrence']['BYDAY']) == 2)
              unset($event['recurrence']['BYDAY']);
            if ($old['recurrence']['BYMONTH'] == $old['start']->format('n'))
              unset($event['recurrence']['BYMONTH']);
          }
        }
        // dates did not change, use the ones from master
        else if ($event['start'] == $old['start'] && $event['end'] == $old['end']) {
          $event['start'] = $master['start'];
          $event['end'] = $master['end'];
        }

        // unset _dateonly flags in (cached) date objects
        unset($event['start']->_dateonly, $event['end']->_dateonly);

        $success = $storage->update_event($event);
        break;
    }
    
    if ($success && $this->freebusy_trigger)
      $this->rc->output->command('plugin.ping_url', array('action' => 'calendar/push-freebusy', 'source' => $storage->id));
    
    return $success;
  }

  /**
   * Get events from source.
   *
   * @param  integer Event's new start (unix timestamp)
   * @param  integer Event's new end (unix timestamp)
   * @param  string  Search query (optional)
   * @param  mixed   List of calendar IDs to load events from (either as array or comma-separated string)
   * @param  boolean Include virtual events (optional)
   * @param  integer Only list events modified since this time (unix timestamp)
   * @return array A list of event records
   */
  public function load_events($start, $end, $search = null, $calendars = null, $virtual = 1, $modifiedsince = null)
  {
    if ($calendars && is_string($calendars))
      $calendars = explode(',', $calendars);
    else if (!$calendars)
      $calendars = array_keys($this->calendars);

    $query = array();
    if ($modifiedsince)
      $query[] = array('changed', '>=', $modifiedsince);

    $events = $categories = array();
    foreach ($calendars as $cid) {
      if ($storage = $this->get_calendar($cid)) {
        $events = array_merge($events, $storage->list_events($start, $end, $search, $virtual, $query));
        $categories += $storage->categories;
      }
    }

    // add events from the address books birthday calendar
    if (in_array(self::BIRTHDAY_CALENDAR_ID, $calendars)) {
      $events = array_merge($events, $this->load_birthday_events($start, $end, $search, $modifiedsince));
    }

    // add new categories to user prefs
    $old_categories = $this->rc->config->get('calendar_categories', $this->default_categories);
    if ($newcats = array_udiff(array_keys($categories), array_keys($old_categories), function($a, $b){ return strcasecmp($a, $b); })) {
      foreach ($newcats as $category)
        $old_categories[$category] = '';  // no color set yet
      $this->rc->user->save_prefs(array('calendar_categories' => $old_categories));
    }

    return $events;
  }

  /**
   * Get a list of pending alarms to be displayed to the user
   *
   * @see calendar_driver::pending_alarms()
   */
  public function pending_alarms($time, $calendars = null)
  {
    $interval = 300;
    $time -= $time % 60;
    
    $slot = $time;
    $slot -= $slot % $interval;
    
    $last = $time - max(60, $this->rc->config->get('refresh_interval', 0));
    $last -= $last % $interval;
    
    // only check for alerts once in 5 minutes
    if ($last == $slot)
      return array();
    
    if ($calendars && is_string($calendars))
      $calendars = explode(',', $calendars);
    
    $time = $slot + $interval;
    
    $candidates = array();
    $query = array(array('tags', '=', 'x-has-alarms'));
    foreach ($this->calendars as $cid => $calendar) {
      // skip calendars with alarms disabled
      if (!$calendar->alarms || ($calendars && !in_array($cid, $calendars)))
        continue;

      foreach ($calendar->list_events($time, $time + 86400 * 365, null, 1, $query) as $e) {
        // add to list if alarm is set
        $alarm = libcalendaring::get_next_alarm($e);
        if ($alarm && $alarm['time'] && $alarm['time'] <= $time && in_array($alarm['action'], $this->alarm_types)) {
          $id = $alarm['id'];  // use alarm-id as primary identifier
          $candidates[$id] = array(
            'id'       => $id,
            'title'    => $e['title'],
            'location' => $e['location'],
            'start'    => $e['start'],
            'end'      => $e['end'],
            'notifyat' => $alarm['time'],
            'action'   => $alarm['action'],
          );
        }
      }
    }

    // get alarm information stored in local database
    if (!empty($candidates)) {
      $alarm_ids = array_map(array($this->rc->db, 'quote'), array_keys($candidates));
      $result = $this->rc->db->query(sprintf(
          "SELECT * FROM kolab_alarms
           WHERE alarm_id IN (%s) AND user_id=?",
           join(',', $alarm_ids),
           $this->rc->db->now()
          ),
          $this->rc->user->ID
       );

      while ($result && ($e = $this->rc->db->fetch_assoc($result))) {
        $dbdata[$e['alarm_id']] = $e;
      }
    }
    
    $alarms = array();
    foreach ($candidates as $id => $alarm) {
      // skip dismissed alarms
      if ($dbdata[$id]['dismissed'])
        continue;
      
      // snooze function may have shifted alarm time
      $notifyat = $dbdata[$id]['notifyat'] ? strtotime($dbdata[$id]['notifyat']) : $alarm['notifyat'];
      if ($notifyat <= $time)
        $alarms[] = $alarm;
    }
    
    return $alarms;
  }

  /**
   * Feedback after showing/sending an alarm notification
   *
   * @see calendar_driver::dismiss_alarm()
   */
  public function dismiss_alarm($alarm_id, $snooze = 0)
  {
    // delete old alarm entry
    $this->rc->db->query(
      "DELETE FROM kolab_alarms
       WHERE alarm_id=? AND user_id=?",
       $alarm_id,
       $this->rc->user->ID
    );

    // set new notifyat time or unset if not snoozed
    $notifyat = $snooze > 0 ? date('Y-m-d H:i:s', time() + $snooze) : null;

    $query = $this->rc->db->query(
      "INSERT INTO kolab_alarms
       (alarm_id, user_id, dismissed, notifyat)
       VALUES(?, ?, ?, ?)",
      $alarm_id,
      $this->rc->user->ID,
      $snooze > 0 ? 0 : 1,
      $notifyat
    );
    
    return $this->rc->db->affected_rows($query);
  }

  /**
   * List attachments from the given event
   */
  public function list_attachments($event)
  {
    if (!($storage = $this->get_calendar($event['calendar'])))
      return false;

    $event = $storage->get_event($event['id']);

    return $event['attachments'];
  }

  /**
   * Get attachment properties
   */
  public function get_attachment($id, $event)
  {
    if (!($storage = $this->get_calendar($event['calendar'])))
      return false;

    $event = $storage->get_event($event['id']);

    if ($event && !empty($event['attachments'])) {
      foreach ($event['attachments'] as $att) {
        if ($att['id'] == $id) {
          return $att;
        }
      }
    }

    return null;
  }

  /**
   * Get attachment body
   * @see calendar_driver::get_attachment_body()
   */
  public function get_attachment_body($id, $event)
  {
    if (!($cal = $this->get_calendar($event['calendar'])))
      return false;

    return $cal->storage->get_attachment($event['id'], $id);
  }

  /**
   * List availabale categories
   * The default implementation reads them from config/user prefs
   */
  public function list_categories()
  {
    // FIXME: complete list with categories saved in config objects (KEP:12)
    return $this->rc->config->get('calendar_categories', $this->default_categories);
  }

  /**
   * Fetch free/busy information from a person within the given range
   */
  public function get_freebusy_list($email, $start, $end)
  {
    if (empty($email)/* || $end < time()*/)
      return false;

    // map vcalendar fbtypes to internal values
    $fbtypemap = array(
      'FREE' => calendar::FREEBUSY_FREE,
      'BUSY-TENTATIVE' => calendar::FREEBUSY_TENTATIVE,
      'X-OUT-OF-OFFICE' => calendar::FREEBUSY_OOF,
      'OOF' => calendar::FREEBUSY_OOF);

    // ask kolab server first
    try {
      $request_config = array(
        'store_body'       => true,
        'follow_redirects' => true,
      );
      $request  = libkolab::http_request(kolab_storage::get_freebusy_url($email), 'GET', $request_config);
      $response = $request->send();

      // authentication required
      if ($response->getStatus() == 401) {
        $request->setAuth($this->rc->user->get_username(), $this->rc->decrypt($_SESSION['password']));
        $response = $request->send();
      }

      if ($response->getStatus() == 200)
        $fbdata = $response->getBody();

      unset($request, $response);
    }
    catch (Exception $e) {
      PEAR::raiseError("Error fetching free/busy information: " . $e->getMessage());
    }

    // get free-busy url from contacts
    if (!$fbdata) {
      $fburl = null;
      foreach ((array)$this->rc->config->get('autocomplete_addressbooks', 'sql') as $book) {
        $abook = $this->rc->get_address_book($book);

        if ($result = $abook->search(array('email'), $email, true, true, true/*, 'freebusyurl'*/)) {
          while ($contact = $result->iterate()) {
            if ($fburl = $contact['freebusyurl']) {
              $fbdata = @file_get_contents($fburl);
              break;
            }
          }
        }

        if ($fbdata)
          break;
      }
    }

    // parse free-busy information using Horde classes
    if ($fbdata) {
      $ical = $this->cal->get_ical();
      $ical->import($fbdata);
      if ($fb = $ical->freebusy) {
        $result = array();
        foreach ($fb['periods'] as $tuple) {
          list($from, $to, $type) = $tuple;
          $result[] = array($from->format('U'), $to->format('U'), isset($fbtypemap[$type]) ? $fbtypemap[$type] : calendar::FREEBUSY_BUSY);
        }

        // we take 'dummy' free-busy lists as "unknown"
        if (empty($result) && !empty($fb['comment']) && stripos($fb['comment'], 'dummy'))
          return false;

        // set period from $start till the begin of the free-busy information as 'unknown'
        if ($fb['start'] && ($fbstart = $fb['start']->format('U')) && $start < $fbstart) {
          array_unshift($result, array($start, $fbstart, calendar::FREEBUSY_UNKNOWN));
        }
        // pad period till $end with status 'unknown'
        if ($fb['end'] && ($fbend = $fb['end']->format('U')) && $fbend < $end) {
          $result[] = array($fbend, $end, calendar::FREEBUSY_UNKNOWN);
        }

        return $result;
      }
    }

    return false;
  }

  /**
   * Handler to push folder triggers when sent from client.
   * Used to push free-busy changes asynchronously after updating an event
   */
  public function push_freebusy()
  {
    // make shure triggering completes
    set_time_limit(0);
    ignore_user_abort(true);

    $cal = get_input_value('source', RCUBE_INPUT_GPC);
    if (!($cal = $this->get_calendar($cal)))
      return false;

    // trigger updates on folder
    $trigger = $cal->storage->trigger();
    if (is_object($trigger) && is_a($trigger, 'PEAR_Error')) {
      rcube::raise_error(array(
        'code' => 900, 'type' => 'php',
        'file' => __FILE__, 'line' => __LINE__,
        'message' => "Failed triggering folder. Error was " . $trigger->getMessage()),
        true, false);
    }

    exit;
  }

  /**
   * Callback function to produce driver-specific calendar create/edit form
   *
   * @param string Request action 'form-edit|form-new'
   * @param array  Calendar properties (e.g. id, color)
   * @param array  Edit form fields
   *
   * @return string HTML content of the form
   */
  public function calendar_form($action, $calendar, $formfields)
  {
    // show default dialog for birthday calendar
    if ($calendar['id'] == self::BIRTHDAY_CALENDAR_ID) {
      return parent::calendar_form($action, $calendar, $formfields);
    }

    if ($calendar['id'] && ($cal = $this->calendars[$calendar['id']])) {
      $folder = $cal->get_realname(); // UTF7
      $color  = $cal->get_color();
    }
    else {
      $folder = '';
      $color  = '';
    }

    $hidden_fields[] = array('name' => 'oldname', 'value' => $folder);

    $storage = $this->rc->get_storage();
    $delim   = $storage->get_hierarchy_delimiter();
    $form   = array();

    if (strlen($folder)) {
      $path_imap = explode($delim, $folder);
      array_pop($path_imap);  // pop off name part
      $path_imap = implode($path_imap, $delim);

      $options = $storage->folder_info($folder);
    }
    else {
      $path_imap = '';
    }

    // General tab
    $form['props'] = array(
      'name' => $this->rc->gettext('properties'),
    );

    // Disable folder name input
    if (!empty($options) && ($options['norename'] || $options['protected'])) {
      $input_name = new html_hiddenfield(array('name' => 'name', 'id' => 'calendar-name'));
      $formfields['name']['value'] = kolab_storage::object_name($folder)
        . $input_name->show($folder);
    }

    // calendar name (default field)
    $form['props']['fieldsets']['location'] = array(
      'name'  => $this->rc->gettext('location'),
      'content' => array(
        'name' => $formfields['name']
      ),
    );

    if (!empty($options) && ($options['norename'] || $options['protected'])) {
      // prevent user from moving folder
      $hidden_fields[] = array('name' => 'parent', 'value' => $path_imap);
    }
    else {
      $select = kolab_storage::folder_selector('event', array('name' => 'parent'), $folder);
      $form['props']['fieldsets']['location']['content']['path'] = array(
        'label' => $this->cal->gettext('parentcalendar'),
        'value' => $select->show(strlen($folder) ? $path_imap : ''),
      );
    }

    // calendar color (default field)
    $form['props']['fieldsets']['settings'] = array(
      'name'  => $this->rc->gettext('settings'),
      'content' => array(
        'color' => $formfields['color'],
        'showalarms' => $formfields['showalarms'],
      ),
    );
    
    
    if ($action != 'form-new') {
      $form['sharing'] = array(
          'name'    => Q($this->cal->gettext('tabsharing')),
          'content' => html::tag('iframe', array(
            'src' => $this->cal->rc->url(array('_action' => 'calendar-acl', 'id' => $calendar['id'], 'framed' => 1)),
            'width' => '100%',
            'height' => 350,
            'border' => 0,
            'style' => 'border:0'),
        ''),
      );
    }

    $this->form_html = '';
    if (is_array($hidden_fields)) {
        foreach ($hidden_fields as $field) {
            $hiddenfield = new html_hiddenfield($field);
            $this->form_html .= $hiddenfield->show() . "\n";
        }
    }

    // Create form output
    foreach ($form as $tab) {
      if (!empty($tab['fieldsets']) && is_array($tab['fieldsets'])) {
        $content = '';
        foreach ($tab['fieldsets'] as $fieldset) {
          $subcontent = $this->get_form_part($fieldset);
          if ($subcontent) {
            $content .= html::tag('fieldset', null, html::tag('legend', null, Q($fieldset['name'])) . $subcontent) ."\n";
          }
        }
      }
      else {
        $content = $this->get_form_part($tab);
      }

      if ($content) {
        $this->form_html .= html::tag('fieldset', null, html::tag('legend', null, Q($tab['name'])) . $content) ."\n";
      }
    }

    // Parse form template for skin-dependent stuff
    $this->rc->output->add_handler('calendarform', array($this, 'calendar_form_html'));
    return $this->rc->output->parse('calendar.kolabform', false, false);
  }

  /**
   * Handler for template object
   */
  public function calendar_form_html()
  {
    return $this->form_html;
  }

  /**
   * Helper function used in calendar_form_content(). Creates a part of the form.
   */
  private function get_form_part($form)
  {
    $content = '';

    if (is_array($form['content']) && !empty($form['content'])) {
      $table = new html_table(array('cols' => 2));
      foreach ($form['content'] as $col => $colprop) {
        $colprop['id'] = '_'.$col;
        $label = !empty($colprop['label']) ? $colprop['label'] : rcube_label($col);

        $table->add('title', sprintf('<label for="%s">%s</label>', $colprop['id'], Q($label)));
        $table->add(null, $colprop['value']);
      }
      $content = $table->show();
    }
    else {
      $content = $form['content'];
    }

    return $content;
  }


  /**
   * Handler to render ACL form for a calendar folder
   */
  public function calendar_acl()
  {
    $this->rc->output->add_handler('folderacl', array($this, 'calendar_acl_form'));
    $this->rc->output->send('calendar.kolabacl');
  }

  /**
   * Handler for ACL form template object
   */
  public function calendar_acl_form()
  {
    $calid = get_input_value('_id', RCUBE_INPUT_GPC);
    if ($calid && ($cal = $this->get_calendar($calid))) {
      $folder = $cal->get_realname(); // UTF7
      $color  = $cal->get_color();
    }
    else {
      $folder = '';
      $color  = '';
    }

    $storage = $this->rc->get_storage();
    $delim   = $storage->get_hierarchy_delimiter();
    $form   = array();

    if (strlen($folder)) {
      $path_imap = explode($delim, $folder);
      array_pop($path_imap);  // pop off name part
      $path_imap = implode($path_imap, $delim);

      $options = $storage->folder_info($folder);

      // Allow plugins to modify the form content (e.g. with ACL form)
      $plugin = $this->rc->plugins->exec_hook('calendar_form_kolab',
        array('form' => $form, 'options' => $options, 'name' => $folder));
    }

    if (!$plugin['form']['sharing']['content'])
        $plugin['form']['sharing']['content'] = html::div('hint', $this->cal->gettext('aclnorights'));

    return $plugin['form']['sharing']['content'];
  }

  /**
   * Handler for user_delete plugin hook
   */
  public function user_delete($args)
  {
    $db = $this->rc->get_dbh();
    foreach (array('kolab_alarms', 'itipinvitations') as $table) {
      $db->query("DELETE FROM $table WHERE user_id=?", $args['user']->ID);
    }
  }
}
