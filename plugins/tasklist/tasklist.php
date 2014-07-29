<?php

/**
 * Tasks plugin for Roundcube webmail
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

class tasklist extends rcube_plugin
{
    const FILTER_MASK_TODAY = 1;
    const FILTER_MASK_TOMORROW = 2;
    const FILTER_MASK_WEEK = 4;
    const FILTER_MASK_LATER = 8;
    const FILTER_MASK_NODATE = 16;
    const FILTER_MASK_OVERDUE = 32;
    const FILTER_MASK_FLAGGED = 64;
    const FILTER_MASK_COMPLETE = 128;

    const SESSION_KEY = 'tasklist_temp';

    public static $filter_masks = array(
        'today'    => self::FILTER_MASK_TODAY,
        'tomorrow' => self::FILTER_MASK_TOMORROW,
        'week'     => self::FILTER_MASK_WEEK,
        'later'    => self::FILTER_MASK_LATER,
        'nodate'   => self::FILTER_MASK_NODATE,
        'overdue'  => self::FILTER_MASK_OVERDUE,
        'flagged'  => self::FILTER_MASK_FLAGGED,
        'complete' => self::FILTER_MASK_COMPLETE,
    );

    public $task = '?(?!login|logout).*';
    public $rc;
    public $lib;
    public $driver;
    public $timezone;
    public $ui;
    public $home;  // declare public to be used in other classes

    private $collapsed_tasks = array();
    private $itip;
    private $ical;


    /**
     * Plugin initialization.
     */
    function init()
    {
        $this->require_plugin('libcalendaring');

        $this->rc  = rcube::get_instance();
        $this->lib = libcalendaring::get_instance();

        $this->register_task('tasks', 'tasklist');

        // load plugin configuration
        $this->load_config();

        $this->timezone = $this->lib->timezone;

        // proceed initialization in startup hook
        $this->add_hook('startup', array($this, 'startup'));

        $this->add_hook('user_delete', array($this, 'user_delete'));
    }

    /**
     * Startup hook
     */
    public function startup($args)
    {
        // the tasks module can be enabled/disabled by the kolab_auth plugin
        if ($this->rc->config->get('tasklist_disabled', false) || !$this->rc->config->get('tasklist_enabled', true))
            return;

        // load localizations
        $this->add_texts('localization/', $args['task'] == 'tasks' && (!$args['action'] || $args['action'] == 'print'));
        $this->rc->load_language($_SESSION['language'], array('tasks.tasks' => $this->gettext('navtitle')));  // add label for task title

        if ($args['task'] == 'tasks' && $args['action'] != 'save-pref') {
            $this->load_driver();

            // register calendar actions
            $this->register_action('index', array($this, 'tasklist_view'));
            $this->register_action('task', array($this, 'task_action'));
            $this->register_action('tasklist', array($this, 'tasklist_action'));
            $this->register_action('counts', array($this, 'fetch_counts'));
            $this->register_action('fetch', array($this, 'fetch_tasks'));
            $this->register_action('inlineui', array($this, 'get_inline_ui'));
            $this->register_action('mail2task', array($this, 'mail_message2task'));
            $this->register_action('get-attachment', array($this, 'attachment_get'));
            $this->register_action('upload', array($this, 'attachment_upload'));
            $this->register_action('mailimportitip', array($this, 'mail_import_itip'));
            $this->register_action('mailimportattach', array($this, 'mail_import_attachment'));
            $this->register_action('itip-status', array($this, 'task_itip_status'));
            $this->register_action('itip-remove', array($this, 'task_itip_remove'));
            $this->register_action('itip-decline-reply', array($this, 'mail_itip_decline_reply'));
            $this->add_hook('refresh', array($this, 'refresh'));

            $this->collapsed_tasks = array_filter(explode(',', $this->rc->config->get('tasklist_collapsed_tasks', '')));
        }
        else if ($args['task'] == 'mail') {
            if ($args['action'] == 'show' || $args['action'] == 'preview') {
                $this->add_hook('message_load', array($this, 'mail_message_load'));
                $this->add_hook('template_object_messagebody', array($this, 'mail_messagebody_html'));
            }

            // add 'Create event' item to message menu
            if ($this->api->output->type == 'html') {
                $this->api->add_content(html::tag('li', null, 
                    $this->api->output->button(array(
                        'command'  => 'tasklist-create-from-mail',
                        'label'    => 'tasklist.createfrommail',
                        'type'     => 'link',
                        'classact' => 'icon taskaddlink active',
                        'class'    => 'icon taskaddlink',
                        'innerclass' => 'icon taskadd',
                    ))),
                'messagemenu');

                $this->api->output->add_label('tasklist.createfrommail');
            }
        }

        if (!$this->rc->output->ajax_call && !$this->rc->output->env['framed']) {
            $this->load_ui();
            $this->ui->init();
        }

        // add hooks for alarms handling
        $this->add_hook('pending_alarms', array($this, 'pending_alarms'));
        $this->add_hook('dismiss_alarms', array($this, 'dismiss_alarms'));
    }

    /**
     *
     */
    private function load_ui()
    {
        if (!$this->ui) {
            require_once($this->home . '/tasklist_ui.php');
            $this->ui = new tasklist_ui($this);
        }
    }

    /**
     * Helper method to load the backend driver according to local config
     */
    private function load_driver()
    {
        if (is_object($this->driver))
            return;

        $driver_name = $this->rc->config->get('tasklist_driver', 'database');
        $driver_class = 'tasklist_' . $driver_name . '_driver';

        require_once($this->home . '/drivers/tasklist_driver.php');
        require_once($this->home . '/drivers/' . $driver_name . '/' . $driver_class . '.php');

        switch ($driver_name) {
        case "kolab":
            $this->require_plugin('libkolab');
        default:
            $this->driver = new $driver_class($this);
            break;
        }

        $this->rc->output->set_env('tasklist_driver', $driver_name);
    }


    /**
     * Dispatcher for task-related actions initiated by the client
     */
    public function task_action()
    {
        $filter = intval(get_input_value('filter', RCUBE_INPUT_GPC));
        $action = get_input_value('action', RCUBE_INPUT_GPC);
        $rec    = get_input_value('t', RCUBE_INPUT_POST, true);
        $oldrec = $rec;
        $success = $refresh = false;

        switch ($action) {
        case 'new':
            $oldrec = null;
            $rec = $this->prepare_task($rec);
            $rec['uid'] = $this->generate_uid();
            $temp_id = $rec['tempid'];
            if ($success = $this->driver->create_task($rec)) {
                $refresh = $this->driver->get_task($rec);
                if ($temp_id) $refresh['tempid'] = $temp_id;
                $this->cleanup_task($rec);
            }
            break;

        case 'edit':
            $rec = $this->prepare_task($rec);
            $clone = $this->handle_recurrence($rec, $this->driver->get_task($rec));
            if ($success = $this->driver->edit_task($rec)) {
                $refresh[] = $this->driver->get_task($rec);
                $this->cleanup_task($rec);

                // add clone from recurring task
                if ($clone && $this->driver->create_task($clone)) {
                    $refresh[] = $this->driver->get_task($clone);
                    $this->driver->clear_alarms($rec['id']);
                }

                // move all childs if list assignment was changed
                if (!empty($rec['_fromlist']) && !empty($rec['list']) && $rec['_fromlist'] != $rec['list']) {
                    foreach ($this->driver->get_childs(array('id' => $rec['id'], 'list' => $rec['_fromlist']), true) as $cid) {
                        $child = array('id' => $cid, 'list' => $rec['list'], '_fromlist' => $rec['_fromlist']);
                        if ($this->driver->move_task($child)) {
                            $r = $this->driver->get_task($child);
                            if ((bool)($filter & self::FILTER_MASK_COMPLETE) == $this->driver->is_complete($r)) {
                                $refresh[] = $r;
                            }
                        }
                    }
                }
            }
            break;

          case 'move':
              foreach ((array)$rec['id'] as $id) {
                  $r = $rec;
                  $r['id'] = $id;
                  if ($this->driver->move_task($r)) {
                      $refresh[] = $this->driver->get_task($r);
                      $success = true;

                      // move all childs, too
                      foreach ($this->driver->get_childs(array('id' => $rec['id'], 'list' => $rec['_fromlist']), true) as $cid) {
                          $child = $rec;
                          $child['id'] = $cid;
                          if ($this->driver->move_task($child)) {
                              $r = $this->driver->get_task($child);
                              if ((bool)($filter & self::FILTER_MASK_COMPLETE) == $this->driver->is_complete($r)) {
                                  $refresh[] = $r;
                              }
                          }
                      }
                  }
              }
              break;

        case 'delete':
            $mode  = intval(get_input_value('mode', RCUBE_INPUT_POST));
            $oldrec = $this->driver->get_task($rec);
            if ($success = $this->driver->delete_task($rec, false)) {
                // delete/modify all childs
                foreach ($this->driver->get_childs($rec, $mode) as $cid) {
                    $child = array('id' => $cid, 'list' => $rec['list']);

                    if ($mode == 1) {  // delete all childs
                        if ($this->driver->delete_task($child, false)) {
                            if ($this->driver->undelete)
                                $_SESSION['tasklist_undelete'][$rec['id']][] = $cid;
                        }
                        else
                            $success = false;
                    }
                    else {
                        $child['parent_id'] = strval($oldrec['parent_id']);
                        $this->driver->edit_task($child);
                    }
                }
                // update parent task to adjust list of children
                if (!empty($oldrec['parent_id'])) {
                    $refresh[] = $this->driver->get_task(array('id' => $oldrec['parent_id'], 'list' => $rec['list']));
                }
            }

            if (!$success)
                $this->rc->output->command('plugin.reload_data');
            break;

        case 'undelete':
            if ($success = $this->driver->undelete_task($rec)) {
                $refresh[] = $this->driver->get_task($rec);
                foreach ((array)$_SESSION['tasklist_undelete'][$rec['id']] as $cid) {
                    if ($this->driver->undelete_task($rec)) {
                        $refresh[] = $this->driver->get_task($rec);
                    }
                }
            }
            break;

        case 'collapse':
            foreach (explode(',', $rec['id']) as $rec_id) {
                if (intval(get_input_value('collapsed', RCUBE_INPUT_GPC))) {
                    $this->collapsed_tasks[] = $rec_id;
                }
                else {
                    $i = array_search($rec_id, $this->collapsed_tasks);
                    if ($i !== false)
                        unset($this->collapsed_tasks[$i]);
                }
            }

            $this->rc->user->save_prefs(array('tasklist_collapsed_tasks' => join(',', array_unique($this->collapsed_tasks))));
            return;  // avoid further actions
        }

        if ($success) {
            $this->rc->output->show_message('successfullysaved', 'confirmation');
            $this->update_counts($oldrec, $refresh);
        }
        else {
            $this->rc->output->show_message('tasklist.errorsaving', 'error');
        }

        // send out notifications
        if ($success && $rec['_notify'] && ($rec['attendees'] || $oldrec['attendees'])) {
            // make sure we have the complete record
            $task = $action == 'delete' ? $oldrec : $this->driver->get_task($rec);

            // only notify if data really changed (TODO: do diff check on client already)
            if (!$oldrec || $action == 'delete' || self::task_diff($event, $old)) {
                $sent = $this->notify_attendees($task, $oldrec, $action, $rec['_comment']);
                if ($sent > 0)
                    $this->rc->output->show_message('tasklist.itipsendsuccess', 'confirmation');
                else if ($sent < 0)
                    $this->rc->output->show_message('tasklist.errornotifying', 'error');
            }
        }

        // unlock client
        $this->rc->output->command('plugin.unlock_saving');

        if ($refresh) {
            if ($refresh['id']) {
                $this->encode_task($refresh);
            }
            else if (is_array($refresh)) {
                foreach ($refresh as $i => $r)
                    $this->encode_task($refresh[$i]);
            }
            $this->rc->output->command('plugin.update_task', $refresh);
        }
    }

    /**
     * Load iTIP functions
     */
    private function load_itip()
    {
        if (!$this->itip) {
            require_once realpath(__DIR__ . '/../libcalendaring/lib/libcalendaring_itip.php');
            $this->itip = new libcalendaring_itip($this, 'tasklist');

//            if ($this->rc->config->get('kolab_invitation_tasklists')) {
//                $this->itip->set_rsvp_actions(array('accepted','tentative','declined','needs-action'));
//            }
        }

        return $this->itip;
    }

    /**
     * repares new/edited task properties before save
     */
    private function prepare_task($rec)
    {
        // try to be smart and extract date from raw input
        if ($rec['raw']) {
            foreach (array('today','tomorrow','sunday','monday','tuesday','wednesday','thursday','friday','saturday','sun','mon','tue','wed','thu','fri','sat') as $word) {
                $locwords[] = '/^' . preg_quote(mb_strtolower($this->gettext($word))) . '\b/i';
                $normwords[] = $word;
                $datewords[] = $word;
            }
            foreach (array('jan','feb','mar','apr','may','jun','jul','aug','sep','oct','now','dec') as $month) {
                $locwords[] = '/(' . preg_quote(mb_strtolower($this->gettext('long'.$month))) . '|' . preg_quote(mb_strtolower($this->gettext($month))) . ')\b/i';
                $normwords[] = $month;
                $datewords[] = $month;
            }
            foreach (array('on','this','next','at') as $word) {
                $fillwords[] = preg_quote(mb_strtolower($this->gettext($word)));
                $fillwords[] = $word;
            }

            $raw = trim($rec['raw']);
            $date_str = '';

            // translate localized keywords
            $raw = preg_replace('/^(' . join('|', $fillwords) . ')\s*/i', '', $raw);
            $raw = preg_replace($locwords, $normwords, $raw);

            // find date pattern
            $date_pattern = '!^(\d+[./-]\s*)?((?:\d+[./-])|' . join('|', $datewords) . ')\.?(\s+\d{4})?[:;,]?\s+!i';
            if (preg_match($date_pattern, $raw, $m)) {
                $date_str .= $m[1] . $m[2] . $m[3];
                $raw = preg_replace(array($date_pattern, '/^(' . join('|', $fillwords) . ')\s*/i'), '', $raw);
                // add year to date string
                if ($m[1] && !$m[3])
                    $date_str .= date('Y');
            }

            // find time pattern
            $time_pattern = '/^(\d+([:.]\d+)?(\s*[hapm.]+)?),?\s+/i';
            if (preg_match($time_pattern, $raw, $m)) {
                $has_time = true;
                $date_str .= ($date_str ? ' ' : 'today ') . $m[1];
                $raw = preg_replace($time_pattern, '', $raw);
            }

            // yes, raw input matched a (valid) date
            if (strlen($date_str) && strtotime($date_str) && ($date = new DateTime($date_str, $this->timezone))) {
                $rec['date'] = $date->format('Y-m-d');
                if ($has_time)
                    $rec['time'] = $date->format('H:i');
                $rec['title'] = $raw;
            }
            else
                $rec['title'] = $rec['raw'];
        }

        // normalize input from client
        if (isset($rec['complete'])) {
            $rec['complete'] = floatval($rec['complete']);
            if ($rec['complete'] > 1)
                $rec['complete'] /= 100;
        }
        if (isset($rec['flagged']))
            $rec['flagged'] = intval($rec['flagged']);

        // fix for garbage input
        if ($rec['description'] == 'null')
            $rec['description'] = '';

        foreach ($rec as $key => $val) {
            if ($val === 'null')
                $rec[$key] = null;
        }

        if (!empty($rec['date'])) {
            $this->normalize_dates($rec, 'date', 'time');
        }

        if (!empty($rec['startdate'])) {
            $this->normalize_dates($rec, 'startdate', 'starttime');
        }

        // convert tags to array, filter out empty entries
        if (isset($rec['tags']) && !is_array($rec['tags'])) {
            $rec['tags'] = array_filter((array)$rec['tags']);
        }

        // convert the submitted alarm values
        if ($rec['valarms']) {
            $valarms = array();
            foreach (libcalendaring::from_client_alarms($rec['valarms']) as $alarm) {
                // alarms can only work with a date (either task start, due or absolute alarm date)
                if (is_a($alarm['trigger'], 'DateTime') || $rec['date'] || $rec['startdate'])
                    $valarms[] = $alarm;
            }
            $rec['valarms'] = $valarms;
        }

        // convert the submitted recurrence settings
        if (is_array($rec['recurrence'])) {
            $refdate = null;
            if (!empty($rec['date'])) {
                $refdate = new DateTime($rec['date'] . ' ' . $rec['time'], $this->timezone);
            }
            else if (!empty($rec['startdate'])) {
                $refdate = new DateTime($rec['startdate'] . ' ' . $rec['starttime'], $this->timezone);
            }

            if ($refdate) {
                $rec['recurrence'] = $this->lib->from_client_recurrence($rec['recurrence'], $refdate);

                // translate count into an absolute end date.
                // why? because when shifting completed tasks to the next recurrence,
                // the initial start date to count from gets lost.
                if ($rec['recurrence']['COUNT']) {
                    $engine = libcalendaring::get_recurrence();
                    $engine->init($rec['recurrence'], $refdate);
                    if ($until = $engine->end()) {
                        $rec['recurrence']['UNTIL'] = $until;
                        unset($rec['recurrence']['COUNT']);
                    }
                }
            }
            else {  // recurrence requires a reference date
                $rec['recurrence'] = '';
            }
        }

        $attachments = array();
        $taskid = $rec['id'];
        if (is_array($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY]['id'] == $taskid) {
            if (!empty($_SESSION[self::SESSION_KEY]['attachments'])) {
                foreach ($_SESSION[self::SESSION_KEY]['attachments'] as $id => $attachment) {
                    if (is_array($rec['attachments']) && in_array($id, $rec['attachments'])) {
                        $attachments[$id] = $this->rc->plugins->exec_hook('attachment_get', $attachment);
                        unset($attachments[$id]['abort'], $attachments[$id]['group']);
                    }
                }
            }
        }

        $rec['attachments'] = $attachments;

        if (is_numeric($rec['id']) && $rec['id'] < 0)
            unset($rec['id']);

        return $rec;
    }

    /**
     * Utility method to convert a tasks date/time values into a normalized format
     */
    private function normalize_dates(&$rec, $date_key, $time_key)
    {
        try {
            // parse date from user format (#2801)
            $date_format = $this->rc->config->get(empty($rec[$time_key]) ? 'date_format' : 'date_long', 'Y-m-d');
            $date = DateTime::createFromFormat($date_format, trim($rec[$date_key] . ' ' . $rec[$time_key]), $this->timezone);

            // fall back to default strtotime logic
            if (empty($date)) {
                $date = new DateTime($rec[$date_key] . ' ' . $rec[$time_key], $this->timezone);
            }

            $rec[$date_key] = $date->format('Y-m-d');
            if (!empty($rec[$time_key]))
                $rec[$time_key] = $date->format('H:i');

            return true;
        }
        catch (Exception $e) {
            $rec[$date_key] = $rec[$time_key] = null;
        }

        return false;
    }

    /**
     * Releases some resources after successful save
     */
    private function cleanup_task(&$rec)
    {
        // remove temp. attachment files
        if (!empty($_SESSION[self::SESSION_KEY]) && ($taskid = $_SESSION[self::SESSION_KEY]['id'])) {
            $this->rc->plugins->exec_hook('attachments_cleanup', array('group' => $taskid));
            $this->rc->session->remove(self::SESSION_KEY);
        }
    }

    /**
     * When flagging a recurring task as complete,
     * clone it and shift dates to the next occurrence
     */
    private function handle_recurrence(&$rec, $old)
    {
        $clone = null;
        if ($this->driver->is_complete($rec) && $old && !$this->driver->is_complete($old) && is_array($rec['recurrence'])) {
            $engine = libcalendaring::get_recurrence();
            $rrule = $rec['recurrence'];
            $updates = array();

            // compute the next occurrence of date attributes
            foreach (array('date'=>'time', 'startdate'=>'starttime') as $date_key => $time_key) {
                if (empty($rec[$date_key]))
                    continue;

                $date = new DateTime($rec[$date_key] . ' ' . $rec[$time_key], $this->timezone);
                $engine->init($rrule, $date);
                if ($next = $engine->next()) {
                    $updates[$date_key] = $next->format('Y-m-d');
                    if (!empty($rec[$time_key]))
                        $updates[$time_key] = $next->format('H:i');
                }
            }

            // shift absolute alarm dates
            if (!empty($updates) && is_array($rec['valarms'])) {
                $updates['valarms'] = array();
                unset($rrule['UNTIL'], $rrule['COUNT']);  // make recurrence rule unlimited

                foreach ($rec['valarms'] as $i => $alarm) {
                    if ($alarm['trigger'] instanceof DateTime) {
                        $engine->init($rrule, $alarm['trigger']);
                        if ($next = $engine->next()) {
                            $alarm['trigger'] = $next;
                        }
                    }
                    $updates['valarms'][$i] = $alarm;
                }
            }

            if (!empty($updates)) {
                // clone task to save a completed copy
                $clone = $rec;
                $clone['uid'] = $this->generate_uid();
                $clone['parent_id'] = $rec['id'];
                unset($clone['id'], $clone['recurrence'], $clone['attachments']);

                // update the task but unset completed flag
                $rec = array_merge($rec, $updates);
                $rec['complete'] = $old['complete'];
                $rec['status'] = $old['status'];
            }
        }

        return $clone;
    }

    /**
     * Send out an invitation/notification to all task attendees
     */
    private function notify_attendees($task, $old, $action = 'edit', $comment = null)
    {
        if ($action == 'delete' || ($task['status'] == 'CANCELLED' && $old['status'] != $task['status'])) {
            $task['cancelled'] = true;
            $is_cancelled      = true;
        }

        $itip   = $this->load_itip();
        $emails = $this->lib->get_user_emails();

        // add comment to the iTip attachment
        $task['comment'] = $comment;

        // needed to generate VTODO instead of VEVENT entry
        $task['_type'] = 'task';

        // compose multipart message using PEAR:Mail_Mime
        $method  = $action == 'delete' ? 'CANCEL' : 'REQUEST';
        $message = $itip->compose_itip_message($task, $method);

        // list existing attendees from the $old task
        $old_attendees = array();
        foreach ((array)$old['attendees'] as $attendee) {
            $old_attendees[] = $attendee['email'];
        }

        // send to every attendee
        $sent = 0; $current = array();
        foreach ((array)$task['attendees'] as $attendee) {
            $current[] = strtolower($attendee['email']);

            // skip myself for obvious reasons
            if (!$attendee['email'] || in_array(strtolower($attendee['email']), $emails)) {
                continue;
            }

            // skip if notification is disabled for this attendee
            if ($attendee['noreply']) {
                continue;
            }

            // which template to use for mail text
            $is_new   = !in_array($attendee['email'], $old_attendees);
            $bodytext = $is_cancelled ? 'eventcancelmailbody' : ($is_new ? 'invitationmailbody' : 'eventupdatemailbody');
            $subject  = $is_cancelled ? 'eventcancelsubject'  : ($is_new ? 'invitationsubject' : ($task['title'] ? 'eventupdatesubject' : 'eventupdatesubjectempty'));

            // finally send the message
            if ($itip->send_itip_message($task, $method, $attendee, $subject, $bodytext, $message))
                $sent++;
            else
                $sent = -100;
        }

        // send CANCEL message to removed attendees
        foreach ((array)$old['attendees'] as $attendee) {
            if ($attendee['ROLE'] == 'ORGANIZER' || !$attendee['email'] || in_array(strtolower($attendee['email']), $current)) {
                continue;
            }

            $vevent = $old;
            $vevent['cancelled'] = $is_cancelled;
            $vevent['attendees'] = array($attendee);
            $vevent['comment']   = $comment;

            if ($itip->send_itip_message($vevent, 'CANCEL', $attendee, 'eventcancelsubject', 'eventcancelmailbody'))
                $sent++;
            else
                $sent = -100;
        }

        return $sent;
    }

    /**
     * Compare two task objects and return differing properties
     *
     * @param array Event A
     * @param array Event B
     * @return array List of differing task properties
     */
    public static function task_diff($a, $b)
    {
        $diff   = array();
        $ignore = array('changed' => 1, 'attachments' => 1);

        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            if (!$ignore[$key] && $a[$key] != $b[$key])
                $diff[] = $key;
        }

        // only compare number of attachments
        if (count($a['attachments']) != count($b['attachments']))
            $diff[] = 'attachments';

        return $diff;
    }

    /**
     * Dispatcher for tasklist actions initiated by the client
     */
    public function tasklist_action()
    {
        $action = get_input_value('action', RCUBE_INPUT_GPC);
        $list  = get_input_value('l', RCUBE_INPUT_GPC, true);
        $success = false;

        if (isset($list['showalarms']))
          $list['showalarms'] = intval($list['showalarms']);

        switch ($action) {
        case 'form-new':
        case 'form-edit':
            echo $this->ui->tasklist_editform($action, $list);
            exit;

        case 'new':
            $list += array('showalarms' => true, 'active' => true, 'editable' => true);
            if ($insert_id = $this->driver->create_list($list)) {
                $list['id'] = $insert_id;
                if (!$list['_reload']) {
                    $this->load_ui();
                    $list['html'] = $this->ui->tasklist_list_item($insert_id, $list, $jsenv);
                    $list += (array)$jsenv[$insert_id];
                }
                $this->rc->output->command('plugin.insert_tasklist', $list);
                $success = true;
            }
            break;

        case 'edit':
            if ($newid = $this->driver->edit_list($list)) {
                $list['oldid'] = $list['id'];
                $list['id'] = $newid;
                $this->rc->output->command('plugin.update_tasklist', $list);
                $success = true;
            }
            break;

        case 'subscribe':
            $success = $this->driver->subscribe_list($list);
            break;

        case 'remove':
            if (($success = $this->driver->remove_list($list)))
                $this->rc->output->command('plugin.destroy_tasklist', $list);
            break;

        case 'search':
            $this->load_ui();
            $results = array();
            foreach ((array)$this->driver->search_lists(get_input_value('q', RCUBE_INPUT_GPC), get_input_value('source', RCUBE_INPUT_GPC)) as $id => $prop) {
                $editname = $prop['editname'];
                unset($prop['editname']);  // force full name to be displayed
                $prop['active'] = false;

                // let the UI generate HTML and CSS representation for this calendar
                $html = $this->ui->tasklist_list_item($id, $prop, $jsenv);
                $prop += (array)$jsenv[$id];
                $prop['editname'] = $editname;
                $prop['html'] = $html;

                $results[] = $prop;
            }
            // report more results available
            if ($this->driver->search_more_results) {
                $this->rc->output->show_message('autocompletemore', 'info');
            }

            $this->rc->output->command('multi_thread_http_response', $results, get_input_value('_reqid', RCUBE_INPUT_GPC));
            return;
        }

        if ($success)
            $this->rc->output->show_message('successfullysaved', 'confirmation');
        else
            $this->rc->output->show_message('tasklist.errorsaving', 'error');

        $this->rc->output->command('plugin.unlock_saving');
    }

    /**
     * Get counts for active tasks divided into different selectors
     */
    public function fetch_counts()
    {
        if (isset($_REQUEST['lists'])) {
            $lists = get_input_value('lists', RCUBE_INPUT_GPC);
        }
        else {
            foreach ($this->driver->get_lists() as $list) {
                if ($list['active'])
                    $lists[] = $list['id'];
            }
        }
        $counts = $this->driver->count_tasks($lists);
        $this->rc->output->command('plugin.update_counts', $counts);
    }

    /**
     * Adjust the cached counts after changing a task
     */
    public function update_counts($oldrec, $newrec)
    {
        // rebuild counts until this function is finally implemented
        $this->fetch_counts();

        // $this->rc->output->command('plugin.update_counts', $counts);
    }

    /**
     *
     */
    public function fetch_tasks()
    {
        $f = intval(get_input_value('filter', RCUBE_INPUT_GPC));
        $search = get_input_value('q', RCUBE_INPUT_GPC);
        $filter = array('mask' => $f, 'search' => $search);
        $lists = get_input_value('lists', RCUBE_INPUT_GPC);;
/*
        // convert magic date filters into a real date range
        switch ($f) {
        case self::FILTER_MASK_TODAY:
            $today = new DateTime('now', $this->timezone);
            $filter['from'] = $filter['to'] = $today->format('Y-m-d');
            break;

        case self::FILTER_MASK_TOMORROW:
            $tomorrow = new DateTime('now + 1 day', $this->timezone);
            $filter['from'] = $filter['to'] = $tomorrow->format('Y-m-d');
            break;

        case self::FILTER_MASK_OVERDUE:
            $yesterday = new DateTime('yesterday', $this->timezone);
            $filter['to'] = $yesterday->format('Y-m-d');
            break;

        case self::FILTER_MASK_WEEK:
            $today = new DateTime('now', $this->timezone);
            $filter['from'] = $today->format('Y-m-d');
            $weekend = new DateTime('now + 7 days', $this->timezone);
            $filter['to'] = $weekend->format('Y-m-d');
            break;

        case self::FILTER_MASK_LATER:
            $date = new DateTime('now + 8 days', $this->timezone);
            $filter['from'] = $date->format('Y-m-d');
            break;

        }
*/
        $data = $this->tasks_data($this->driver->list_tasks($filter, $lists), $f, $tags);
        $this->rc->output->command('plugin.data_ready', array('filter' => $f, 'lists' => $lists, 'search' => $search, 'data' => $data, 'tags' => array_values(array_unique($tags))));
    }

    /**
     * Prepare and sort the given task records to be sent to the client
     */
    private function tasks_data($records, $f, &$tags)
    {
        $data = $tags = $this->task_tree = $this->task_titles = array();
        foreach ($records as $rec) {
            if ($rec['parent_id']) {
                $this->task_tree[$rec['id']] = $rec['parent_id'];
            }
            $this->encode_task($rec);
            if (!empty($rec['tags']))
                $tags = array_merge($tags, (array)$rec['tags']);

            // apply filter; don't trust the driver on this :-)
            if ((!$f && !$this->driver->is_complete($rec)) || ($rec['mask'] & $f))
                $data[] = $rec;
        }

        // sort tasks according to their hierarchy level and due date
        array_walk($data, array($this, 'task_walk_tree'));
        usort($data, array($this, 'task_sort_cmp'));

        return $data;
    }

    /**
     * Prepare the given task record before sending it to the client
     */
    private function encode_task(&$rec)
    {
        $rec['mask'] = $this->filter_mask($rec);
        $rec['flagged'] = intval($rec['flagged']);
        $rec['complete'] = floatval($rec['complete']);
        $rec['changed'] = is_object($rec['changed']) ? $rec['changed']->format('U') : null;

        if ($rec['date']) {
            try {
                $date = new DateTime($rec['date'] . ' ' . $rec['time'], $this->timezone);
                $rec['datetime'] = intval($date->format('U'));
                $rec['date'] = $date->format($this->rc->config->get('date_format', 'Y-m-d'));
                $rec['_hasdate'] = 1;
            }
            catch (Exception $e) {
                $rec['date'] = $rec['datetime'] = null;
            }
        }
        else {
            $rec['date'] = $rec['datetime'] = null;
            $rec['_hasdate'] = 0;
        }

        if ($rec['startdate']) {
            try {
                $date = new DateTime($rec['startdate'] . ' ' . $rec['starttime'], $this->timezone);
                $rec['startdatetime'] = intval($date->format('U'));
                $rec['startdate'] = $date->format($this->rc->config->get('date_format', 'Y-m-d'));
            }
            catch (Exception $e) {
                $rec['startdate'] = $rec['startdatetime'] = null;
            }
        }

        if ($rec['valarms']) {
            $rec['alarms_text'] = libcalendaring::alarms_text($rec['valarms']);
            $rec['valarms'] = libcalendaring::to_client_alarms($rec['valarms']);
        }

        if ($rec['recurrence']) {
            $rec['recurrence_text'] = $this->lib->recurrence_text($rec['recurrence']);
            $rec['recurrence'] = $this->lib->to_client_recurrence($rec['recurrence'], $rec['time'] || $rec['starttime']);
        }

        foreach ((array)$rec['attachments'] as $k => $attachment) {
            $rec['attachments'][$k]['classname'] = rcube_utils::file2class($attachment['mimetype'], $attachment['name']);
        }

        if (!is_array($rec['tags']))
            $rec['tags'] = (array)$rec['tags'];
        sort($rec['tags'], SORT_LOCALE_STRING);

        if (in_array($rec['id'], $this->collapsed_tasks))
          $rec['collapsed'] = true;

        if (empty($rec['parent_id']))
            $rec['parent_id'] = null;

        $this->task_titles[$rec['id']] = $rec['title'];
    }

    /**
     * Callback function for array_walk over all tasks.
     * Sets tree depth and parent titles
     */
    private function task_walk_tree(&$rec)
    {
        $rec['_depth'] = 0;
        $parent_id = $this->task_tree[$rec['id']];
        while ($parent_id) {
            $rec['_depth']++;
            $rec['parent_title'] = $this->task_titles[$parent_id];
            $parent_id = $this->task_tree[$parent_id];
        }
    }

    /**
     * Compare function for task list sorting.
     * Nested tasks need to be sorted to the end.
     */
    private function task_sort_cmp($a, $b)
    {
        $d = $a['_depth'] - $b['_depth'];
        if (!$d) $d = $b['_hasdate'] - $a['_hasdate'];
        if (!$d) $d = $a['datetime'] - $b['datetime'];
        return $d;
    }

    /**
     * Compute the filter mask of the given task
     *
     * @param array Hash array with Task record properties
     * @return int Filter mask
     */
    public function filter_mask($rec)
    {
        static $today, $tomorrow, $weeklimit;

        if (!$today) {
            $today_date = new DateTime('now', $this->timezone);
            $today = $today_date->format('Y-m-d');
            $tomorrow_date = new DateTime('now + 1 day', $this->timezone);
            $tomorrow = $tomorrow_date->format('Y-m-d');
            $week_date = new DateTime('now + 7 days', $this->timezone);
            $weeklimit = $week_date->format('Y-m-d');
        }

        $mask = 0;
        $start = $rec['startdate'] ?: '1900-00-00';
        $duedate = $rec['date'] ?: '3000-00-00';

        if ($rec['flagged'])
            $mask |= self::FILTER_MASK_FLAGGED;
        if ($this->driver->is_complete($rec))
            $mask |= self::FILTER_MASK_COMPLETE;

        if (empty($rec['date']))
            $mask |= self::FILTER_MASK_NODATE;
        else if ($rec['date'] < $today)
            $mask |= self::FILTER_MASK_OVERDUE;

        if ($duedate <= $today || ($rec['startdate'] && $start <= $today))
            $mask |= self::FILTER_MASK_TODAY;
        if ($duedate <= $tomorrow || ($rec['startdate'] && $start <= $tomorrow))
            $mask |= self::FILTER_MASK_TOMORROW;
        if (($start > $tomorrow && $start <= $weeklimit) || ($duedate > $tomorrow && $duedate <= $weeklimit))
            $mask |= self::FILTER_MASK_WEEK;
        else if ($start > $weeklimit || ($rec['date'] && $duedate > $weeklimit))
            $mask |= self::FILTER_MASK_LATER;

        return $mask;
    }


    /*******  UI functions  ********/

    /**
     * Render main view of the tasklist task
     */
    public function tasklist_view()
    {
        $this->ui->init();
        $this->ui->init_templates();

        // set autocompletion env
        $this->rc->output->set_env('autocomplete_threads', (int)$this->rc->config->get('autocomplete_threads', 0));
        $this->rc->output->set_env('autocomplete_max', (int)$this->rc->config->get('autocomplete_max', 15));
        $this->rc->output->set_env('autocomplete_min_length', $this->rc->config->get('autocomplete_min_length'));
        $this->rc->output->add_label('autocompletechars', 'autocompletemore');

        $this->rc->output->set_pagetitle($this->gettext('navtitle'));
        $this->rc->output->send('tasklist.mainview');
    }


    /**
     *
     */
    public function get_inline_ui()
    {
        foreach (array('save','cancel','savingdata') as $label)
            $texts['tasklist.'.$label] = $this->gettext($label);

        $texts['tasklist.newtask'] = $this->gettext('createfrommail');

        $this->ui->init_templates();
        echo $this->api->output->parse('tasklist.taskedit', false, false);
        echo html::tag('link', array('rel' => 'stylesheet', 'type' => 'text/css', 'href' => $this->url($this->local_skin_path() . '/tagedit.css'), 'nl' => true));
        echo html::tag('script', array('type' => 'text/javascript'),
            "rcmail.set_env('tasklists', " . json_encode($this->api->output->env['tasklists']) . ");\n".
            "rcmail.add_label(" . json_encode($texts) . ");\n"
        );
        exit;
    }

    /**
     * Handler for keep-alive requests
     * This will check for updated data in active lists and sync them to the client
     */
    public function refresh($attr)
    {
        // refresh the entire list every 10th time to also sync deleted items
        if (rand(0,10) == 10) {
            $this->rc->output->command('plugin.reload_data');
            return;
        }

        $filter = array(
            'since'  => $attr['last'],
            'search' => get_input_value('q', RCUBE_INPUT_GPC),
            'mask'   => intval(get_input_value('filter', RCUBE_INPUT_GPC)) & self::FILTER_MASK_COMPLETE,
        );
        $lists = get_input_value('lists', RCUBE_INPUT_GPC);;

        $updates = $this->driver->list_tasks($filter, $lists);
        if (!empty($updates)) {
            $this->rc->output->command('plugin.refresh_tasks', $this->tasks_data($updates, 255, $tags), true);

            // update counts
            $counts = $this->driver->count_tasks($lists);
            $this->rc->output->command('plugin.update_counts', $counts);
        }
    }

    /**
     * Handler for pending_alarms plugin hook triggered by the calendar module on keep-alive requests.
     * This will check for pending notifications and pass them to the client
     */
    public function pending_alarms($p)
    {
        $this->load_driver();
        if ($alarms = $this->driver->pending_alarms($p['time'] ?: time())) {
            foreach ($alarms as $alarm) {
                // encode alarm object to suit the expectations of the calendaring code
                if ($alarm['date'])
                    $alarm['start'] = new DateTime($alarm['date'].' '.$alarm['time'], $this->timezone);

                $alarm['id'] = 'task:' . $alarm['id'];  // prefix ID with task:
                $alarm['allday'] = empty($alarm['time']) ? 1 : 0;
                $p['alarms'][] = $alarm;
            }
        }

        return $p;
    }

    /**
     * Handler for alarm dismiss hook triggered by the calendar module
     */
    public function dismiss_alarms($p)
    {
        $this->load_driver();
        foreach ((array)$p['ids'] as $id) {
            if (strpos($id, 'task:') === 0)
                $p['success'] |= $this->driver->dismiss_alarm(substr($id, 5), $p['snooze']);
        }

        return $p;
    }


    /******* Attachment handling  *******/

    /**
     * Handler for attachments upload
    */
    public function attachment_upload()
    {
        $this->lib->attachment_upload(self::SESSION_KEY);
    }

    /**
     * Handler for attachments download/displaying
     */
    public function attachment_get()
    {
        // show loading page
        if (!empty($_GET['_preload'])) {
            return $this->lib->attachment_loading_page();
        }

        $task = get_input_value('_t', RCUBE_INPUT_GPC);
        $list = get_input_value('_list', RCUBE_INPUT_GPC);
        $id   = get_input_value('_id', RCUBE_INPUT_GPC);

        $task = array('id' => $task, 'list' => $list);
        $attachment = $this->driver->get_attachment($id, $task);

        // show part page
        if (!empty($_GET['_frame'])) {
            $this->lib->attachment = $attachment;
            $this->register_handler('plugin.attachmentframe', array($this->lib, 'attachment_frame'));
            $this->register_handler('plugin.attachmentcontrols', array($this->lib, 'attachment_header'));
            $this->rc->output->send('tasklist.attachment');
        }
        // deliver attachment content
        else if ($attachment) {
            $attachment['body'] = $this->driver->get_attachment_body($id, $task);
            $this->lib->attachment_get($attachment);
        }

        // if we arrive here, the requested part was not found
        header('HTTP/1.1 404 Not Found');
        exit;
    }


    /*******  Email related function *******/

    public function mail_message2task()
    {
        $uid = get_input_value('_uid', RCUBE_INPUT_POST);
        $mbox = get_input_value('_mbox', RCUBE_INPUT_POST);
        $task = array();

        // establish imap connection
        $imap = $this->rc->get_storage();
        $imap->set_mailbox($mbox);
        $message = new rcube_message($uid);

        if ($message->headers) {
            $task['title'] = trim($message->subject);
            $task['description'] = trim($message->first_text_part());
            $task['id'] = -$uid;

            $this->load_driver();

            // copy mail attachments to task
            if ($message->attachments && $this->driver->attachments) {
                if (!is_array($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY]['id'] != $task['id']) {
                    $_SESSION[self::SESSION_KEY] = array();
                    $_SESSION[self::SESSION_KEY]['id'] = $task['id'];
                    $_SESSION[self::SESSION_KEY]['attachments'] = array();
                }

                foreach ((array)$message->attachments as $part) {
                    $attachment = array(
                        'data' => $imap->get_message_part($uid, $part->mime_id, $part),
                        'size' => $part->size,
                        'name' => $part->filename,
                        'mimetype' => $part->mimetype,
                        'group' => $task['id'],
                    );

                    $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);

                    if ($attachment['status'] && !$attachment['abort']) {
                        $id = $attachment['id'];
                        $attachment['classname'] = rcube_utils::file2class($attachment['mimetype'], $attachment['name']);

                        // store new attachment in session
                        unset($attachment['status'], $attachment['abort'], $attachment['data']);
                        $_SESSION[self::SESSION_KEY]['attachments'][$id] = $attachment;

                        $attachment['id'] = 'rcmfile' . $attachment['id'];  // add prefix to consider it 'new'
                        $task['attachments'][] = $attachment;
                    }
                }
            }

            $this->rc->output->command('plugin.mail2taskdialog', $task);
        }
        else {
            $this->rc->output->command('display_message', $this->gettext('messageopenerror'), 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Check mail message structure of there are .ics files attached
     *
     * @todo move to libcalendaring
     */
    public function mail_message_load($p)
    {
        $this->message = $p['object'];
        $itip_part     = null;

        // check all message parts for .ics files
        foreach ((array)$this->message->mime_parts as $part) {
            if ($this->is_vcalendar($part)) {
                if ($part->ctype_parameters['method'])
                    $itip_part = $part->mime_id;
                else
                    $this->ics_parts[] = $part->mime_id;
            }
        }

        // priorize part with method parameter
        if ($itip_part) {
            $this->ics_parts = array($itip_part);
        }
    }

    /**
     * Add UI element to copy event invitations or updates to the calendar
     *
     * @todo move to libcalendaring
     */
    public function mail_messagebody_html($p)
    {
        // load iCalendar functions (if necessary)
        if (!empty($this->ics_parts)) {
            $this->get_ical();
            $this->load_itip();
        }

        // @todo: Calendar plugin does the same, which means the
        // attachment body is fetched twice, this is not optimal
        $html = '';
        foreach ($this->ics_parts as $mime_id) {
            $part    = $this->message->mime_parts[$mime_id];
            $charset = $part->ctype_parameters['charset'] ? $part->ctype_parameters['charset'] : RCMAIL_CHARSET;
            $objects = $this->ical->import($this->message->get_part_content($mime_id), $charset);
            $title   = $this->gettext('title');

            // successfully parsed events?
            if (empty($objects)) {
                continue;
            }

            // show a box for every task in the file
            foreach ($objects as $idx => $task) {
                if ($task['_type'] != 'task') {
                    continue;
                }

                // get prepared inline UI for this event object
                $html .= html::div('tasklist-invitebox',
                    $this->itip->mail_itip_inline_ui(
                        $task,
                        $this->ical->method,
                        $mime_id . ':' . $idx,
                        'tasks',
                        rcube_utils::anytodatetime($this->message->headers->date)
                    )
                );

                // limit listing
                if ($idx >= 3) {
                    break;
                }
            }
        }

        // prepend event boxes to message body
        if ($html) {
            $this->load_ui();
            $this->ui->init();

            $p['content'] = $html . $p['content'];

            $this->rc->output->add_label('tasklist.savingdata','tasklist.deletetaskconfirm','tasklist.declinedeleteconfirm');

            // add "Save to calendar" button into attachment menu
            $this->add_button(array(
                'id'         => 'attachmentsavetask',
                'name'       => 'attachmentsavetask',
                'type'       => 'link',
                'wrapper'    => 'li',
                'command'    => 'attachment-save-task',
                'class'      => 'icon tasklistlink',
                'classact'   => 'icon tasklistlink active',
                'innerclass' => 'icon taskadd',
                'label'      => 'tasklist.savetotasklist',
            ), 'attachmentmenu');
        }

        return $p;
    }

    /**
     * Read the given mime message from IMAP and parse ical data
     *
     * @todo move to libcalendaring
     */
    private function mail_get_itip_task($mbox, $uid, $mime_id)
    {
        $charset = RCMAIL_CHARSET;

        // establish imap connection
        $imap = $this->rc->get_storage();
        $imap->set_mailbox($mbox);

        if ($uid && $mime_id) {
            list($mime_id, $index) = explode(':', $mime_id);

            $part    = $imap->get_message_part($uid, $mime_id);
            $headers = $imap->get_message_headers($uid);

            if ($part->ctype_parameters['charset']) {
                $charset = $part->ctype_parameters['charset'];
            }

            if ($part) {
                $tasks = $this->get_ical()->import($part, $charset);
            }
        }

        // successfully parsed events?
        if (!empty($tasks) && ($task = $tasks[$index])) {
            // store the message's sender address for comparisons
            $task['_sender'] = preg_match('/([a-z0-9][a-z0-9\-\.\+\_]*@[^&@"\'.][^@&"\']*\\.([^\\x00-\\x40\\x5b-\\x60\\x7b-\\x7f]{2,}|xn--[a-z0-9]{2,}))/', $headers->from, $m) ? $m[1] : '';
            $askt['_sender_utf'] = rcube_idn_to_utf8($task['_sender']);

            return $task;
        }

        return null;
    }

    /**
     * Checks if specified message part is a vcalendar data
     *
     * @param rcube_message_part Part object
     *
     * @return boolean True if part is of type vcard
     *
     * @todo move to libcalendaring
     */
    private function is_vcalendar($part)
    {
        return (
            in_array($part->mimetype, array('text/calendar', 'text/x-vcalendar', 'application/ics')) ||
            // Apple sends files as application/x-any (!?)
            ($part->mimetype == 'application/x-any' && $part->filename && preg_match('/\.ics$/i', $part->filename))
        );
    }

    /**
     * Load iCalendar functions
     */
    public function get_ical()
    {
        if (!$this->ical) {
            $this->ical = libcalendaring::get_ical();
        }

        return $this->ical;
    }

    /**
     * Get properties of the tasklist this user has specified as default
     */
    public function get_default_tasklist($writeable = false)
    {
//        $default_id = $this->rc->config->get('tasklist_default_list');
        $lists = $this->driver->get_lists();
//        $list  = $calendars[$default_id] ?: null;

        if (!$list || ($writeable && !$list['editable'])) {
            foreach ($lists as $l) {
                if ($l['default']) {
                    $list = $l;
                    break;
                }

                if (!$writeable || $l['editable']) {
                    $first = $l;
                }
            }
        }

        return $list ?: $first;
    }

    /**
     * Import the full payload from a mail message attachment
     */
    public function mail_import_attachment()
    {
        $uid     = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $mime_id = rcube_utils::get_input_value('_part', rcube_utils::INPUT_POST);
        $charset = RCMAIL_CHARSET;

        // establish imap connection
        $imap = $this->rc->get_storage();
        $imap->set_mailbox($mbox);

        if ($uid && $mime_id) {
            $part    = $imap->get_message_part($uid, $mime_id);
            $headers = $imap->get_message_headers($uid);

            if ($part->ctype_parameters['charset']) {
                $charset = $part->ctype_parameters['charset'];
            }

            if ($part) {
                $tasks = $this->get_ical()->import($part, $charset);
            }
        }

        $success = $existing = 0;

        if (!empty($tasks)) {
            // find writeable tasklist to store task
            $cal_id = !empty($_REQUEST['_list']) ? rcube_utils::get_input_value('_list', rcube_utils::INPUT_POST) : null;
            $lists  = $this->driver->get_lists();
            $list   = $lists[$cal_id] ?: $this->get_default_tasklist(true);

            foreach ($tasks as $task) {
                // save to tasklist
                if ($list && $list['editable'] && $task['_type'] == 'task') {
                    $task['list'] = $list['id'];

                    if (!$this->driver->get_task($task['uid'])) {
                        $success += (bool) $this->driver->create_task($task);
                    }
                    else {
                        $existing++;
                    }
                }
            }
        }

        if ($success) {
            $this->rc->output->command('display_message', $this->gettext(array(
                'name' => 'importsuccess',
                'vars' => array('nr' => $success),
            )), 'confirmation');
        }
        else if ($existing) {
            $this->rc->output->command('display_message', $this->gettext('importwarningexists'), 'warning');
        }
        else {
            $this->rc->output->command('display_message', $this->gettext('errorimportingtask'), 'error');
        }
    }

    /**
     * Handler for POST request to import an event attached to a mail message
     */
    public function mail_import_itip()
    {
        $uid     = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $mime_id = rcube_utils::get_input_value('_part', rcube_utils::INPUT_POST);
        $status  = rcube_utils::get_input_value('_status', rcube_utils::INPUT_POST);
        $delete  = intval(rcube_utils::get_input_value('_del', rcube_utils::INPUT_POST));
        $noreply = intval(rcube_utils::get_input_value('_noreply', rcube_utils::INPUT_POST)) || $status == 'needs-action';

        $error_msg = $this->gettext('errorimportingtask');
        $success   = false;

        // successfully parsed tasks?
        if ($task = $this->mail_get_itip_task($mbox, $uid, $mime_id)) {
            // find writeable list to store the task
            $list_id = !empty($_REQUEST['_list']) ? rcube_utils::get_input_value('_list', rcube_utils::INPUT_POST) : null;
            $lists   = $this->driver->get_lists();
            $list    = $lists[$list_id] ?: $this->get_default_tasklist(true);

            $metadata = array(
                'uid'      => $task['uid'],
                'changed'  => is_object($task['changed']) ? $task['changed']->format('U') : 0,
                'sequence' => intval($task['sequence']),
                'fallback' => strtoupper($status),
                'method'   => $this->ical->method,
                'task'     => 'tasks',
            );

            // update my attendee status according to submitted method
            if (!empty($status)) {
                $organizer = null;
                $emails    = $this->lib->get_user_emails();

                foreach ($task['attendees'] as $i => $attendee) {
                    if ($attendee['role'] == 'ORGANIZER') {
                        $organizer = $attendee;
                    }
                    else if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                        $metadata['attendee'] = $attendee['email'];
                        $metadata['rsvp']     = $attendee['role'] != 'NON-PARTICIPANT';
                        $reply_sender         = $attendee['email'];

                        $task['attendees'][$i]['status'] = strtoupper($status);
                        if ($task['attendees'][$i]['status'] != 'NEEDS-ACTION') {
                            unset($task['attendees'][$i]['rsvp']);  // remove RSVP attribute
                        }
                    }
                }

                // add attendee with this user's default identity if not listed
                if (!$reply_sender) {
                    $sender_identity = $this->rc->user->get_identity();
                    $task['attendees'][] = array(
                        'name'   => $sender_identity['name'],
                        'email'  => $sender_identity['email'],
                        'role'   => 'OPT-PARTICIPANT',
                        'status' => strtoupper($status),
                    );
                    $metadata['attendee'] = $sender_identity['email'];
                }
            }

            // save to tasklist
            if ($list && $list['editable']) {
                $task['list'] = $list['id'];

                // check for existing task with the same UID
                $existing = $this->driver->get_task($task['uid']);

                if ($existing) {
                    // only update attendee status
                    if ($this->ical->method == 'REPLY') {
                        // try to identify the attendee using the email sender address
                        $existing_attendee = -1;
                        foreach ($existing['attendees'] as $i => $attendee) {
                            if ($task['_sender'] && ($attendee['email'] == $task['_sender'] || $attendee['email'] == $task['_sender_utf'])) {
                                $existing_attendee = $i;
                                break;
                            }
                        }

                        $task_attendee = null;
                        foreach ($task['attendees'] as $attendee) {
                            if ($task['_sender'] && ($attendee['email'] == $task['_sender'] || $attendee['email'] == $task['_sender_utf'])) {
                                $task_attendee        = $attendee;
                                $metadata['fallback'] = $attendee['status'];
                                $metadata['attendee'] = $attendee['email'];
                                $metadata['rsvp']     = $attendee['rsvp'] || $attendee['role'] != 'NON-PARTICIPANT';
                                break;
                            }
                        }

                        // found matching attendee entry in both existing and new events
                        if ($existing_attendee >= 0 && $task_attendee) {
                            $existing['attendees'][$existing_attendee] = $task_attendee;
                            $success = $this->driver->edit_task($existing);
                        }
                        // update the entire attendees block
                        else if (($task['sequence'] >= $existing['sequence'] || $task['changed'] >= $existing['changed']) && $task_attendee) {
                            $existing['attendees'][] = $task_attendee;
                            $success = $this->driver->edit_task($existing);
                        }
                        else {
                            $error_msg = $this->gettext('newerversionexists');
                        }
                    }
                    // delete the task when declined
                    else if ($status == 'declined' && $delete) {
                        $deleted = $this->driver->delete_task($existing, true);
                        $success = true;
                    }
                    // import the (newer) task
                    else if ($task['sequence'] >= $existing['sequence'] || $task['changed'] >= $existing['changed']) {
                        $task['id']   = $existing['id'];
                        $task['list'] = $existing['list'];

                        // preserve my participant status for regular updates
                        if (empty($status)) {
                            $emails = $this->lib->get_user_emails();
                            foreach ($task['attendees'] as $i => $attendee) {
                                if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                                    foreach ($existing['attendees'] as $j => $_attendee) {
                                        if ($attendee['email'] == $_attendee['email']) {
                                            $task['attendees'][$i] = $existing['attendees'][$j];
                                            break;
                                        }
                                    }
                                }
                            }
                        }

                        // set status=CANCELLED on CANCEL messages
                        if ($this->ical->method == 'CANCEL') {
                            $task['status'] = 'CANCELLED';
                        }
                        // show me as free when declined (#1670)
                        if ($status == 'declined' || $task['status'] == 'CANCELLED') {
                            $task['free_busy'] = 'free';
                        }

                        $success = $this->driver->edit_task($task);
                    }
                    else if (!empty($status)) {
                        $existing['attendees'] = $task['attendees'];
                        if ($status == 'declined') { // show me as free when declined (#1670)
                            $existing['free_busy'] = 'free';
                        }

                        $success = $this->driver->edit_event($existing);
                    }
                    else {
                        $error_msg = $this->gettext('newerversionexists');
                    }
                }
                else if (!$existing && ($status != 'declined' || $this->rc->config->get('kolab_invitation_tasklists'))) {
                    $success = $this->driver->create_task($task);
                }
                else if ($status == 'declined') {
                    $error_msg = null;
                }
            }
            else if ($status == 'declined') {
                $error_msg = null;
            }
            else {
                $error_msg = $this->gettext('nowritetasklistfound');
            }
        }

        if ($success) {
            $message = $this->ical->method == 'REPLY' ? 'attendeupdateesuccess' : ($deleted ? 'successremoval' : ($existing ? 'updatedsuccessfully' : 'importedsuccessfully'));
            $this->rc->output->command('display_message', $this->gettext(array('name' => $message, 'vars' => array('list' => $list['name']))), 'confirmation');

            $metadata['rsvp']         = intval($metadata['rsvp']);
            $metadata['after_action'] = $this->rc->config->get('tasklist_itip_after_action');

            $this->rc->output->command('plugin.itip_message_processed', $metadata);
            $error_msg = null;
        }
        else if ($error_msg) {
            $this->rc->output->command('display_message', $error_msg, 'error');
        }

        // send iTip reply
        if ($this->ical->method == 'REQUEST' && $organizer && !$noreply && !in_array(strtolower($organizer['email']), $emails) && !$error_msg) {
            $task['comment'] = rcube_utils::get_input_value('_comment', rcube_utils::INPUT_POST);
            $itip = $this->load_itip();
            $itip->set_sender_email($reply_sender);

            if ($itip->send_itip_message($task, 'REPLY', $organizer, 'itipsubject' . $status, 'itipmailbody' . $status))
                $this->rc->output->command('display_message', $this->gettext(array('name' => 'sentresponseto', 'vars' => array('mailto' => $organizer['name'] ? $organizer['name'] : $organizer['email']))), 'confirmation');
            else
                $this->rc->output->command('display_message', $this->gettext('itipresponseerror'), 'error');
        }

        $this->rc->output->send();
    }


    /****  Task invitation plugin hooks ****/

    /**
     * Handler for calendar/itip-status requests
     */
    function task_itip_status()
    {
        $data = rcube_utils::get_input_value('data', rcube_utils::INPUT_POST, true);

        // find local copy of the referenced task
        $existing = $this->driver->get_task($data);
        $itip     = $this->load_itip();
        $response = $itip->get_itip_status($data, $existing);

        // get a list of writeable lists to save new tasks to
        if (!$existing && $response['action'] == 'rsvp' || $response['action'] == 'import') {
            $lists  = $this->driver->get_lists();
            $select = new html_select(array('name' => 'tasklist', 'id' => 'itip-saveto', 'is_escaped' => true));
            $num    = 0;

            foreach ($lists as $list) {
                if ($list['editable']) {
                    $select->add($list['name'], $list['id']);
                    $num++;
                }
            }

            if ($num <= 1) {
                $select = null;
            }
        }

        if ($select) {
            $default_list = $this->get_default_tasklist(true);
            $response['select'] = html::span('folder-select', $this->gettext('saveintasklist') . '&nbsp;' .
                $select->show($this->rc->config->get('tasklist_default_list', $default_list['id'])));
        }

        $this->rc->output->command('plugin.update_itip_object_status', $response);
    }

    /**
     * Handler for calendar/itip-remove requests
     */
    function task_itip_remove()
    {
        $success = false;
        $uid     = rcube_utils::get_input_value('uid', rcube_utils::INPUT_POST);

        // search for event if only UID is given
        if ($task = $this->driver->get_task($uid)) {
            $success = $this->driver->delete_task($task, true);
        }

        if ($success) {
            $this->rc->output->show_message('tasklist.successremoval', 'confirmation');
        }
        else {
            $this->rc->output->show_message('tasklist.errorsaving', 'error');
        }
    }


    /*******  Utility functions  *******/

    /**
     * Generate a unique identifier for an event
     */
    public function generate_uid()
    {
      return strtoupper(md5(time() . uniqid(rand())) . '-' . substr(md5($this->rc->user->get_username()), 0, 16));
    }

    /**
     * Handler for user_delete plugin hook
     */
    public function user_delete($args)
    {
       $this->load_driver();
       return $this->driver->user_delete($args);
    }
}
