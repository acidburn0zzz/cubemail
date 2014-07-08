<?php

/**
 * iTIP functions for the calendar-based Roudncube plugins
 *
 * Class providing functionality to manage iTIP invitations
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2011-2014, Kolab Systems AG <contact@kolabsys.com>
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
class libcalendaring_itip
{
    protected $rc;
    protected $lib;
    protected $plugin;
    protected $sender;
    protected $domain;
    protected $itip_send = false;
    protected $rsvp_actions = array('accepted','tentative','declined');
    protected $rsvp_status  = array('accepted','tentative','declined','delegated');

    function __construct($plugin, $domain = 'libcalendaring')
    {
        $this->plugin = $plugin;
        $this->rc = rcube::get_instance();
        $this->lib = libcalendaring::get_instance();
        $this->domain = $domain;

        $hook = $this->rc->plugins->exec_hook('calendar_load_itip',
            array('identity' => $this->rc->user->get_identity()));
        $this->sender = $hook['identity'];

        $this->plugin->add_hook('message_before_send', array($this, 'before_send_hook'));
        $this->plugin->add_hook('smtp_connect', array($this, 'smtp_connect_hook'));
    }

    public function set_sender_email($email)
    {
        if (!empty($email))
            $this->sender['email'] = $email;
    }

    public function set_rsvp_actions($actions)
    {
        $this->rsvp_actions = (array)$actions;
        $this->rsvp_status = array_merge($this->rsvp_actions, array('delegated'));
    }

    /**
     * Wrapper for rcube_plugin::gettext()
     * Checking for a label in different domains
     *
     * @see rcube::gettext()
     */
    public function gettext($p)
    {
        $label = is_array($p) ? $p['name'] : $p;
        $domain = $this->domain;
        if (!$this->rc->text_exists($label, $domain)) {
            $domain = 'libcalendaring';
        }
        return $this->rc->gettext($p, $domain);
    }

    /**
     * Send an iTip mail message
     *
     * @param array   Event object to send
     * @param string  iTip method (REQUEST|REPLY|CANCEL)
     * @param array   Hash array with recipient data (name, email)
     * @param string  Mail subject
     * @param string  Mail body text label
     * @param object  Mail_mime object with message data
     * @return boolean True on success, false on failure
     */
    public function send_itip_message($event, $method, $recipient, $subject, $bodytext, $message = null)
    {
        if (!$this->sender['name'])
            $this->sender['name'] = $this->sender['email'];

        if (!$message)
            $message = $this->compose_itip_message($event, $method);

        $mailto = rcube_idn_to_ascii($recipient['email']);

        $headers = $message->headers();
        $headers['To'] = format_email_recipient($mailto, $recipient['name']);
        $headers['Subject'] = $this->gettext(array(
            'name' => $subject,
            'vars' => array(
                'title' => $event['title'],
                'name' => $this->sender['name']
            )
        ));

        // compose a list of all event attendees
        $attendees_list = array();
        foreach ((array)$event['attendees'] as $attendee) {
            $attendees_list[] = ($attendee['name'] && $attendee['email']) ?
                $attendee['name'] . ' <' . $attendee['email'] . '>' :
                ($attendee['name'] ? $attendee['name'] : $attendee['email']);
        }

        $mailbody = $this->gettext(array(
            'name' => $bodytext,
            'vars' => array(
                'title' => $event['title'],
                'date' => $this->lib->event_date_text($event, true),
                'attendees' => join(', ', $attendees_list),
                'sender' => $this->sender['name'],
                'organizer' => $this->sender['name'],
            )
        ));

        // if (!empty($event['comment'])) {
        //     $mailbody .= "\n\n" . $this->gettext('itipsendercomment') . $event['comment'];
        // }

        // append links for direct invitation replies
        if ($method == 'REQUEST' && ($token = $this->store_invitation($event, $recipient['email']))) {
            $mailbody .= "\n\n" . $this->gettext(array(
                'name' => 'invitationattendlinks',
                'vars' => array('url' => $this->plugin->get_url(array('action' => 'attend', 't' => $token))),
            ));
        }
        else if ($method == 'CANCEL' && $event['cancelled']) {
            $this->cancel_itip_invitation($event);
        }

        $message->headers($headers, true);
        $message->setTXTBody(rcube_mime::format_flowed($mailbody, 79));

        // finally send the message
        $this->itip_send = true;
        $sent = $this->rc->deliver_message($message, $headers['X-Sender'], $mailto, $smtp_error);
        $this->itip_send = false;

        return $sent;
    }

    /**
     * Plugin hook triggered by rcube::deliver_message() before delivering a message.
     * Here we can set the 'smtp_server' config option to '' in order to use
     * PHP's mail() function for unauthenticated email sending.
     */
    public function before_send_hook($p)
    {
        if ($this->itip_send && !$this->rc->user->ID && $this->rc->config->get('calendar_itip_smtp_server', null) === '') {
            $this->rc->config->set('smtp_server', '');
        }

        return $p;
    }

    /**
     * Plugin hook to alter SMTP authentication.
     * This is used if iTip messages are to be sent from an unauthenticated session
     */
    public function smtp_connect_hook($p)
    {
        // replace smtp auth settings if we're not in an authenticated session
        if ($this->itip_send && !$this->rc->user->ID) {
            foreach (array('smtp_server', 'smtp_user', 'smtp_pass') as $prop) {
                $p[$prop] = $this->rc->config->get("calendar_itip_$prop", $p[$prop]);
            }
        }

      return $p;
    }

    /**
     * Helper function to build a Mail_mime object to send an iTip message
     *
     * @param array   Event object to send
     * @param string  iTip method (REQUEST|REPLY|CANCEL)
     * @return object Mail_mime object with message data
     */
    public function compose_itip_message($event, $method)
    {
        $from = rcube_idn_to_ascii($this->sender['email']);
        $from_utf = rcube_idn_to_utf8($from);
        $sender = format_email_recipient($from, $this->sender['name']);

        // truncate list attendees down to the recipient of the iTip Reply.
        // constraints for a METHOD:REPLY according to RFC 5546
        if ($method == 'REPLY') {
            $replying_attendee = null; $reply_attendees = array();
            foreach ($event['attendees'] as $attendee) {
                if ($attendee['role'] == 'ORGANIZER') {
                    $reply_attendees[] = $attendee;
                }
                else if (strcasecmp($attedee['email'], $from) == 0 || strcasecmp($attendee['email'], $from_utf) == 0) {
                    $replying_attendee = $attendee;
                }
            }
            if ($replying_attendee) {
                $reply_attendees[] = $replying_attendee;
                $event['attendees'] = $reply_attendees;
            }
        }

        // compose multipart message using PEAR:Mail_Mime
        $message = new Mail_mime("\r\n");
        $message->setParam('text_encoding', 'quoted-printable');
        $message->setParam('head_encoding', 'quoted-printable');
        $message->setParam('head_charset', RCMAIL_CHARSET);
        $message->setParam('text_charset', RCMAIL_CHARSET . ";\r\n format=flowed");
        $message->setContentType('multipart/alternative');

        // compose common headers array
        $headers = array(
            'From' => $sender,
            'Date' => $this->rc->user_date(),
            'Message-ID' => $this->rc->gen_message_id(),
            'X-Sender' => $from,
        );
        if ($agent = $this->rc->config->get('useragent')) {
            $headers['User-Agent'] = $agent;
        }

        $message->headers($headers);

        // attach ics file for this event
        $ical = $this->plugin->get_ical();
        $ics = $ical->export(array($event), $method, false, $method == 'REQUEST' && $this->plugin->driver ? array($this->plugin->driver, 'get_attachment_body') : false);
        $message->addAttachment($ics, 'text/calendar', 'event.ics', false, '8bit', '', RCMAIL_CHARSET . "; method=" . $method);

        return $message;
    }


    /**
     * Handler for calendar/itip-status requests
     */
    public function get_itip_status($event, $existing = null)
    {
      $action = $event['rsvp'] ? 'rsvp' : '';
      $status = $event['fallback'];
      $latest = false;
      $html = '';

      if (is_numeric($event['changed']))
        $event['changed'] = new DateTime('@'.$event['changed']);

      // check if the given itip object matches the last state
      if ($existing) {
        $latest = ($event['sequence'] && $existing['sequence'] == $event['sequence']) ||
                  (!$event['sequence'] && $existing['changed'] && $existing['changed'] >= $event['changed']);
      }

      // determine action for REQUEST
      if ($event['method'] == 'REQUEST') {
        $html = html::div('rsvp-status', $this->gettext('acceptinvitation'));

        if ($existing) {
          $rsvp = $event['rsvp'];
          $emails = $this->lib->get_user_emails();
          foreach ($existing['attendees'] as $i => $attendee) {
            if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
              $status = strtoupper($attendee['status']);
              break;
            }
          }
        }
        else {
          $rsvp = $event['rsvp'] && $this->rc->config->get('calendar_allow_itip_uninvited', true);
        }

        $status_lc = strtolower($status);

        if ($status_lc == 'unknown' && !$this->rc->config->get('calendar_allow_itip_uninvited', true)) {
          $html = html::div('rsvp-status', $this->gettext('notanattendee'));
          $action = 'import';
        }
        else if (in_array($status_lc, $this->rsvp_status)) {
          $status_text = $this->gettext(($latest ? 'youhave' : 'youhavepreviously') . $status_lc);

          if ($existing && ($existing['sequence'] > $event['sequence'] || (!isset($event['sequence']) && $existing['changed'] && $existing['changed'] > $event['changed']))) {
            $action = '';  // nothing to do here, outdated invitation
            if ($status_lc == 'needs-action')
              $status_text = $this->gettext('outdatedinvitation');
          }
          else if (!$existing && !$rsvp) {
            $action = 'import';
          }
          else if ($latest && $status_lc != 'needs-action') {
            $action = 'update';
          }

          $html = html::div('rsvp-status ' . $status_lc, $status_text);
        }
      }
      // determine action for REPLY
      else if ($event['method'] == 'REPLY') {
        // check whether the sender already is an attendee
        if ($existing) {
          $action = $this->rc->config->get('calendar_allow_itip_uninvited', true) ? 'accept' : '';
          $listed = false;
          foreach ($existing['attendees'] as $attendee) {
            if ($attendee['role'] != 'ORGANIZER' && strcasecmp($attendee['email'], $event['attendee']) == 0) {
              if (in_array($status, array('ACCEPTED','TENTATIVE','DECLINED','DELEGATED'))) {
                $html = html::div('rsvp-status ' . strtolower($status), $this->gettext(array(
                    'name' => 'attendee'.strtolower($status),
                    'vars' => array(
                        'delegatedto' => Q($attendee['delegated-to'] ?: '?'),
                    )
                )));
              }
              $action = $attendee['status'] == $status ? '' : 'update';
              $listed = true;
              break;
            }
          }

          if (!$listed) {
            $html = html::div('rsvp-status', $this->gettext('itipnewattendee'));
          }
        }
        else {
          $html = html::div('rsvp-status hint', $this->gettext('itipobjectnotfound'));
          $action = '';
        }
      }
      else if ($event['method'] == 'CANCEL') {
        if (!$existing) {
          $html = html::div('rsvp-status hint', $this->gettext('itipobjectnotfound'));
          $action = '';
        }
      }

      return array(
          'uid' => $event['uid'],
          'id' => asciiwords($event['uid'], true),
          'saved' => $existing ? true : false,
          'latest' => $latest,
          'status' => $status,
          'action' => $action,
          'html' => $html,
      );
    }

    /**
     * Build inline UI elements for iTip messages
     */
    public function mail_itip_inline_ui($event, $method, $mime_id, $task, $message_date = null)
    {
        $buttons = array();
        $dom_id = asciiwords($event['uid'], true);
        $rsvp_status = 'unknown';

        // pass some metadata about the event and trigger the asynchronous status check
        $changed = is_object($event['changed']) ? $event['changed'] : $message_date;
        $metadata = array(
            'uid'      => $event['uid'],
            'changed'  => $changed ? $changed->format('U') : 0,
            'sequence' => intval($event['sequence']),
            'method'   => $method,
            'task'     => $task,
        );

        // create buttons to be activated from async request checking existence of this event in local calendars
        $buttons[] = html::div(array('id' => 'loading-'.$dom_id, 'class' => 'rsvp-status loading'), $this->gettext('loading'));

        // on iTip REPLY we have two options:
        if ($method == 'REPLY') {
            $title = $this->gettext('itipreply');

            foreach ($event['attendees'] as $attendee) {
                if (!empty($attendee['email']) && $attendee['role'] != 'ORGANIZER') {
                    $metadata['attendee'] = $attendee['email'];
                    $rsvp_status = strtoupper($attendee['status']);
                    break;
                }
            }

            // 1. update the attendee status on our copy
            $update_button = html::tag('input', array(
                'type' => 'button',
                'class' => 'button',
                'onclick' => "rcube_libcalendaring.add_from_itip_mail('" . JQ($mime_id) . "', '$task')",
                'value' => $this->gettext('updateattendeestatus'),
            ));

            // 2. accept or decline a new or delegate attendee
            $accept_buttons = html::tag('input', array(
                'type' => 'button',
                'class' => "button accept",
                'onclick' => "rcube_libcalendaring.add_from_itip_mail('" . JQ($mime_id) . "', '$task')",
                'value' => $this->gettext('acceptattendee'),
            ));
            $accept_buttons .= html::tag('input', array(
                'type' => 'button',
                'class' => "button decline",
                'onclick' => "rcube_libcalendaring.decline_attendee_reply('" . JQ($mime_id) . "', '$task')",
                'value' => $this->gettext('declineattendee'),
            ));

            $buttons[] = html::div(array('id' => 'update-'.$dom_id, 'style' => 'display:none'), $update_button);
            $buttons[] = html::div(array('id' => 'accept-'.$dom_id, 'style' => 'display:none'), $accept_buttons);
        }
        // when receiving iTip REQUEST messages:
        else if ($method == 'REQUEST') {
            $emails = $this->lib->get_user_emails();
            $title = $event['sequence'] > 0 ? $this->gettext('itipupdate') : $this->gettext('itipinvitation');
            $metadata['rsvp'] = true;

            // 1. display RSVP buttons (if the user was invited)
            foreach ($this->rsvp_actions as $method) {
                $rsvp_buttons .= html::tag('input', array(
                    'type' => 'button',
                    'class' => "button $method",
                    'onclick' => "rcube_libcalendaring.add_from_itip_mail('" . JQ($mime_id) . "', '$task', '$method', '$dom_id')",
                    'value' => $this->gettext('itip' . $method),
                ));
            }

            // 2. update the local copy with minor changes
            $update_button = html::tag('input', array(
                'type' => 'button',
                'class' => 'button',
                'onclick' => "rcube_libcalendaring.add_from_itip_mail('" . JQ($mime_id) . "', '$task')",
                'value' => $this->gettext('updatemycopy'),
            ));

            // 3. Simply import the event without replying
            $import_button = html::tag('input', array(
                'type' => 'button',
                'class' => 'button',
                'onclick' => "rcube_libcalendaring.add_from_itip_mail('" . JQ($mime_id) . "', '$task')",
                'value' => $this->gettext('importtocalendar'),
            ));

            // check my status
            foreach ($event['attendees'] as $attendee) {
                if ($attendee['email'] && in_array(strtolower($attendee['email']), $emails)) {
                    $metadata['attendee'] = $attendee['email'];
                    $metadata['rsvp'] = $attendee['rsvp'] || $attendee['role'] != 'NON-PARTICIPANT';
                    $rsvp_status = !empty($attendee['status']) ? strtoupper($attendee['status']) : 'NEEDS-ACTION';
                    break;
                }
            }

            // add itip reply message controls
            $rsvp_buttons .= html::div('itip-reply-controls', $this->itip_rsvp_options_ui($dom_id));

            $buttons[] = html::div(array('id' => 'rsvp-'.$dom_id, 'class' => 'rsvp-buttons', 'style' => 'display:none'), $rsvp_buttons);
            $buttons[] = html::div(array('id' => 'update-'.$dom_id, 'style' => 'display:none'), $update_button);
        }
        // for CANCEL messages, we can:
        else if ($method == 'CANCEL') {
            $title = $this->gettext('itipcancellation');

            // 1. remove the event from our calendar
            $button_remove = html::tag('input', array(
                'type' => 'button',
                'class' => 'button',
                'onclick' => "rcube_libcalendaring.remove_from_itip('" . JQ($event['uid']) . "', '$task', '" . JQ($event['title']) . "')",
                'value' => $this->gettext('removefromcalendar'),
            ));

            // 2. update our copy with status=cancelled
            $button_update = html::tag('input', array(
              'type' => 'button',
              'class' => 'button',
              'onclick' => "rcube_libcalendaring.add_from_itip_mail('" . JQ($mime_id) . "', '$task')",
              'value' => $this->gettext('updatemycopy'),
            ));

            $buttons[] = html::div(array('id' => 'rsvp-'.$dom_id, 'style' => 'display:none'), $button_remove . $button_update);

            $rsvp_status = 'CANCELLED';
            $metadata['rsvp'] = true;
        }

        // append generic import button
        if ($import_button) {
            $buttons[] = html::div(array('id' => 'import-'.$dom_id, 'style' => 'display:none'), $import_button);
        }

        // TODO: add field for COMMENT on iTip replies
        // TODO: add option/checkbox to delete this message after update

        // pass some metadata about the event and trigger the asynchronous status check
        $metadata['fallback'] = $rsvp_status;
        $metadata['rsvp'] = intval($metadata['rsvp']);

        $this->rc->output->add_script("rcube_libcalendaring.fetch_itip_object_status(" . json_serialize($metadata) . ")", 'docready');

        // get localized texts from the right domain
        foreach (array('savingdata','deleteobjectconfirm','declinedeleteconfirm','declineattendee','declineattendeeconfirm','cancel') as $label) {
          $this->rc->output->command('add_label', "itip.$label", $this->gettext($label));
        }

        // show event details with buttons
        return $this->itip_object_details_table($event, $title) .
            html::div(array('class' => 'itip-buttons', 'id' => 'itip-buttons-' . asciiwords($metadata['uid'], true)), join('', $buttons));
    }

    /**
     * Render UI elements to control iTip reply message sending
     */
    public function itip_rsvp_options_ui($dom_id)
    {
        // add checkbox to suppress itip reply message
        $rsvp_additions = html::label(array('class' => 'noreply-toggle'),
            html::tag('input', array('type' => 'checkbox', 'id' => 'noreply-'.$dom_id, 'value' => 1))
            . ' ' . $this->gettext('itipsuppressreply')
        );

        // add input field for reply comment
        $rsvp_additions .= html::a(array('href' => '#toggle', 'class' => 'reply-comment-toggle'), $this->gettext('itipeditresponse'));
        $rsvp_additions .= html::div('itip-reply-comment',
            html::tag('textarea', array('id' => 'reply-comment-'.$dom_id, 'cols' => 40, 'rows' => 6, 'style' => 'display:none', 'placeholder' => $this->gettext('itipcomment')), '')
        );

        return $rsvp_additions;
    }

    /**
     * Render event details in a table
     */
    function itip_object_details_table($event, $title)
    {
        $table = new html_table(array('cols' => 2, 'border' => 0, 'class' => 'calendar-eventdetails'));
        $table->add('ititle', $title);
        $table->add('title', Q($event['title']));
        $table->add('label', $this->plugin->gettext('date'), $this->domain);
        $table->add('date', Q($this->lib->event_date_text($event)));
        if ($event['location']) {
            $table->add('label', $this->plugin->gettext('location'), $this->domain);
            $table->add('location', Q($event['location']));
        }
        if ($event['comment']) {
            $table->add('label', $this->plugin->gettext('comment'), $this->domain);
            $table->add('location', Q($event['comment']));
        }

        return $table->show();
    }


    /**
     * Create iTIP invitation token for later replies via URL
     *
     * @param array Hash array with event properties
     * @param string Attendee email address
     * @return string Invitation token
     */
    public function store_invitation($event, $attendee)
    {
        // empty stub
        return false;
    }

    /**
     * Mark invitations for the given event as cancelled
     *
     * @param array Hash array with event properties
     */
    public function cancel_itip_invitation($event)
    {
        // empty stub
        return false;
    }

}
