<?php

/**
 * Kolab notes module
 *
 * Adds simple notes management features to the web client
 *
 * @version @package_version@
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

class kolab_notes extends rcube_plugin
{
    public $task = '?(?!login|logout).*';
    public $rc;

    private $ui;
    private $lists;
    private $folders;

    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        $this->require_plugin('libkolab');

        $this->rc = rcube::get_instance();

        $this->register_task('notes');

        // load plugin configuration
        $this->load_config();

        // proceed initialization in startup hook
        $this->add_hook('startup', array($this, 'startup'));
    }

    /**
     * Startup hook
     */
    public function startup($args)
    {
        // the notes module can be enabled/disabled by the kolab_auth plugin
        if ($this->rc->config->get('notes_disabled', false) || !$this->rc->config->get('notes_enabled', true)) {
            return;
        }

        // load localizations
        $this->add_texts('localization/', $args['task'] == 'notes' && !$args['action']);

        if ($args['task'] == 'notes') {
            // register task actions
            $this->register_action('index', array($this, 'notes_view'));
            $this->register_action('fetch', array($this, 'notes_fetch'));
            $this->register_action('get',   array($this, 'note_record'));
            $this->register_action('action', array($this, 'note_action'));
        }

        if (!$this->rc->output->ajax_call && !$this->rc->output->env['framed']) {
            require_once($this->home . '/kolab_notes_ui.php');
            $this->ui = new kolab_notes_ui($this);
            $this->ui->init();
        }
        
    }

    /**
     * Read available calendars for the current user and store them internally
     */
    private function _read_lists($force = false)
    {
        // already read sources
        if (isset($this->lists) && !$force)
            return $this->lists;

        // get all folders that have type "task"
        $folders = kolab_storage::sort_folders(kolab_storage::get_folders('note'));
        $this->lists = $this->folders = array();

        // find default folder
        $default_index = 0;
        foreach ($folders as $i => $folder) {
            if ($folder->default)
                $default_index = $i;
        }

        // put default folder on top of the list
        if ($default_index > 0) {
            $default_folder = $folders[$default_index];
            unset($folders[$default_index]);
            array_unshift($folders, $default_folder);
        }

        $delim = $this->rc->get_storage()->get_hierarchy_delimiter();
        $listnames = array();

        // include virtual folders for a full folder tree
        if (!$this->rc->output->ajax_call && in_array($this->rc->action, array('index','')))
            $folders = kolab_storage::folder_hierarchy($folders);

        foreach ($folders as $folder) {
            $utf7name = $folder->name;

            $path_imap = explode($delim, $utf7name);
            $editname = rcube_charset::convert(array_pop($path_imap), 'UTF7-IMAP');  // pop off raw name part
            $path_imap = join($delim, $path_imap);

            $fullname = $folder->get_name();
            $listname = kolab_storage::folder_displayname($fullname, $listnames);

            // special handling for virtual folders
            if ($folder->virtual) {
                $list_id = kolab_storage::folder_id($utf7name);
                $this->lists[$list_id] = array(
                    'id' => $list_id,
                    'name' => $fullname,
                    'listname' => $listname,
                    'virtual' => true,
                    'editable' => false,
                );
                continue;
            }

            if ($folder->get_namespace() == 'personal') {
                $norename = false;
                $readonly = false;
                $alarms = true;
            }
            else {
                $alarms = false;
                $readonly = true;
                if (($rights = $folder->get_myrights()) && !PEAR::isError($rights)) {
                    if (strpos($rights, 'i') !== false)
                      $readonly = false;
                }
                $info = $folder->get_folder_info();
                $norename = $readonly || $info['norename'] || $info['protected'];
            }

            $list_id = kolab_storage::folder_id($utf7name);
            $item = array(
                'id' => $list_id,
                'name' => $fullname,
                'listname' => $listname,
                'editname' => $editname,
                'editable' => !$readionly,
                'norename' => $norename,
                'parentfolder' => $path_imap,
                'default' => $folder->default,
                'class_name' => trim($folder->get_namespace() . ($folder->default ? ' default' : '')),
            );
            $this->lists[$item['id']] = $item;
            $this->folders[$item['id']] = $folder;
            $this->folders[$folder->name] = $folder;
        }
    }

    /**
     * Get a list of available folders from this source
     */
    public function get_lists()
    {
        $this->_read_lists();

        // attempt to create a default folder for this user
        if (empty($this->lists)) {
            #if ($this->create_list(array('name' => 'Tasks', 'color' => '0000CC', 'default' => true)))
            #    $this->_read_lists(true);
        }

        return $this->lists;
    }


    /*******  UI functions  ********/

    /**
     * Render main view of the tasklist task
     */
    public function notes_view()
    {
        $this->ui->init();
        $this->ui->init_templates();
        $this->rc->output->set_pagetitle($this->gettext('navtitle'));
        $this->rc->output->send('kolab_notes.notes');
    }

    /**
     * 
     */
    public function notes_fetch()
    {
        $search = rcube_utils::get_input_value('_q', RCUBE_INPUT_GPC);
        $list = rcube_utils::get_input_value('_list', RCUBE_INPUT_GPC);

        $data = $this->notes_data($this->list_notes($list, $search), $tags);
        $this->rc->output->command('plugin.data_ready', array('list' => $list, 'search' => $search, 'data' => $data, 'tags' => array_values(array_unique($tags))));
    }

    /**
     *
     */
    protected function notes_data($records, &$tags)
    {
        $tags = array();

        foreach ($records as $i => $rec) {
            $this->_client_encode($records[$i]);
            unset($records[$i]['description']);

            foreach ((array)$reg['categories'] as $tag) {
                $tags[] = $tag;
            }
        }

        $tags = array_unique($tags);
        return $records;
    }

    /**
     *
     */
    protected function list_notes($list_id, $search = null)
    {
        $results = array();

        // query Kolab storage
        $query = array();

        // full text search (only works with cache enabled)
        if (strlen($search)) {
            foreach (rcube_utils::normalize_string(mb_strtolower($search), true) as $word) {
                $query[] = array('words', '~', $word);
            }
        }

        $this->_read_lists();
        if ($folder = $this->folders[$list_id]) {
            foreach ($folder->select($query) as $record) {
                $record['list'] = $list_id;
                $results[] = $record;
            }
        }

        return $results;
    }

    public function note_record()
    {
        $data = $this->get_note(array(
            'uid' => rcube_utils::get_input_value('_id', RCUBE_INPUT_GPC),
            'list' => rcube_utils::get_input_value('_list', RCUBE_INPUT_GPC),
        ));

        // encode for client use
        if (is_array($data)) {
            $this->_client_encode($data);
        }

        $this->rc->output->command('plugin.render_note', $data);
    }

    public function get_note($note)
    {
        if (is_array($note)) {
            $uid = $note['id'] ?: $note['uid'];
            $list_id = $note['list'];
        }
        else {
            $uid = $note;
        }

        $this->_read_lists();
        if ($list_id) {
            if ($folder = $this->folders[$list_id]) {
                return $folder->get_object($uid);
            }
        }
        // iterate over all calendar folders and search for the event ID
        else {
            foreach ($this->folders as $list_id => $folder) {
                if ($result = $folder->get_object($uid)) {
                    $result['list'] = $list_id;
                    return $result;
                }
            }
        }

        return false;
    }

    /**
     *
     */
    private function _client_encode(&$note)
    {
        foreach ($note as $key => $prop) {
            if ($key[0] == '_') {
                unset($note[$key]);
            }
        }

        foreach (array('created','changed') as $key) {
            if (is_object($note[$key]) && $note[$key] instanceof DateTime) {
                $note[$key.'_'] = $note[$key]->format('U');
                $note[$key] = $this->rc->format_date($note[$key]);
            }
        }

        return $note;
    }

    public function note_action()
    {
        $action = rcube_utils::get_input_value('_do', RCUBE_INPUT_POST);
        $note = rcube_utils::get_input_value('_data', RCUBE_INPUT_POST, true);

        $success =false;
        switch ($action) {
            case 'save':
                console($action, $note);
                sleep(3);
                $success = true;
                break;
        }

        // show confirmation/error message
        if ($success) {
            $this->rc->output->show_message('successfullysaved', 'confirmation');
        }
        else {
            $this->rc->output->show_message('kolab_notes.errorsaving', 'error');
        }
 
        // unlock client
        $this->rc->output->command('plugin.unlock_saving');
        // $this->rc->output->command('plugin.update_note', $note);
    }

}

