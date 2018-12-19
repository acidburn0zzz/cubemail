<?php

/**
 * Kolab Chat
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2014-2018, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_chat extends rcube_plugin
{
    public $task = '^(?!login|logout).*$';

    private $rc;
    private $driver;


    public function init()
    {
        $this->rc = rcube::get_instance();

        $this->add_hook('startup', array($this, 'startup'));
    }

    /**
     * Startup hook handler, initializes/enables Chat
     */
    public function startup($args)
    {
        // the files module can be enabled/disabled by the kolab_auth plugin
        if ($this->rc->config->get('kolab_chat_disabled') || !$this->rc->config->get('kolab_chat_enabled', true)) {
            return;
        }

        $this->load_config();

        $extwin = $this->rc->config->get('kolab_chat_extwin');
        $driver = $this->rc->config->get('kolab_chat_driver', 'mattermost');

        if (!$driver || !file_exists(__DIR__ . "/drivers/$driver.php")) {
            return;
        }

        // Load the driver
        require_once __DIR__ . "/drivers/$driver.php";

        $class_name = "kolab_chat_$driver";
        $this->driver = new $class_name($this);

        $this->add_texts('localization/');

        // Register UI end-points
        $this->register_task('kolab-chat');
        $this->register_action('index', array($this, 'ui'));
        $this->register_action('action', array($this, 'action'));

        if ($this->rc->output->type == 'html' && !$this->rc->output->get_env('framed')) {
            $this->include_stylesheet($this->local_skin_path() . '/kolab_chat.css');
            $this->rc->output->set_env('kolab_chat_extwin', (bool) $extwin);
            $this->rc->output->add_script(
"rcmail.addEventListener('beforeswitch-task', function(p) {
    if (p == 'kolab-chat' && rcmail.env.kolab_chat_extwin) {
        rcmail.open_window('?_task=kolab-chat&redirect=1', false, false, true);
        return false;
    }
});",
                'foot'
            );

            $this->add_button(array(
                    'command'    => 'kolab-chat',
                    'class'      => 'button-chat',
                    'classsel'   => 'button-chat button-selected',
                    'innerclass' => 'button-inner',
                    'label'      => 'kolab_chat.chat',
                    'type'       => 'link',
                ), 'taskbar');

            $this->driver->ui();
        }

        if ($this->rc->output->type == 'html' && $args['task'] == 'settings') {
            // add hooks for Chat settings
            $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
            $this->add_hook('preferences_list', array($this, 'preferences_list'));
            $this->add_hook('preferences_save', array($this, 'preferences_save')); 
        }
    }

    /**
     * Display chat iframe wrapped by Roundcube interface elements (taskmenu)
     * or a dummy page with redirect to the chat app.
     */
    function ui()
    {
        $this->driver->ui();

        $url = rcube::JQ($this->driver->url());

        if (!empty($_GET['redirect'])) {
            echo '<!DOCTYPE html><html><head>'
                . '<meta http-equiv="refresh" content="0; url=' . $url . '">'
                . '</head><body></body></html>';
            exit;
        }
        else {
            $this->rc->output->add_script(
                "rcmail.addEventListener('init', function() {"
                    . "rcmail.location_href('$url', rcmail.get_frame_window(rcmail.env.contentframe));"
                . "});",
                'foot'
            );

            $this->rc->output->send('kolab_chat.chat');
        }
    }

    /**
     * Handler for driver specific actions
     */
    function action()
    {
        $this->driver->action();
    }

    /**
     * Handler for preferences_sections_list hook.
     * Adds Chat settings section into preferences sections list.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function preferences_sections_list($p)
    {
        $p['list']['kolab-chat'] = array(
            'id' => 'kolab-chat', 'section' => $this->gettext('chat'), 'class' => 'chat'
        );

        return $p;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into Chat settings sections in Preferences.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function preferences_list($p)
    {
        if ($p['section'] != 'kolab-chat') {
            return $p;
        }

        $no_override = array_flip((array) $this->rc->config->get('dont_override'));

        $p['blocks']['main']['name'] = $this->gettext('mainoptions');

        if (!isset($no_override['kolab_chat_extwin'])) {
            if (!$p['current']) {
                $p['blocks']['main']['content'] = true;
                return $p;
            }

            $field_id = 'rcmfd_kolab_chat_extwin';
            $input    = new html_checkbox(array('name' => '_kolab_chat_extwin', 'id' => $field_id, 'value' => 1));

            $p['blocks']['main']['options']['kolab_chat_extwin'] = array(
                'title'   => html::label($field_id, rcube::Q($this->gettext('showinextwin'))),
                'content' => $input->show($this->rc->config->get('kolab_chat_extwin') ? 1 : 0),
            );
        }

        if ($p['current']) {
            // update env flag in the parent window
            $this->rc->output->command('parent.set_env', array('kolab_chat_extwin' => (bool) $this->rc->config->get('kolab_chat_extwin')));

            // Add driver-specific options
            $this->driver->preferences_list($p);
        }

        return $p;
    }

    /**
     * Handler for preferences_save hook.
     * Executed on Chat settings form submit.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    function preferences_save($p)
    {
        if ($p['section'] == 'kolab-chat') {
            $p['prefs'] = array(
                'kolab_chat_extwin' => isset($_POST['_kolab_chat_extwin']),
            );

            // Add driver-specific options
            $this->driver->preferences_save($p);
        }

        return $p;
    }
}
