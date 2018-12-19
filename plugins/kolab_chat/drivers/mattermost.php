<?php

/**
 * Mattermost driver for Kolab Chat
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

class kolab_chat_mattermost
{
    private $rc;
    private $plugin;
    private $token_valid = false;


    /**
     * Object constructor
     *
     * @param rcube_plugin $plugin Kolab_chat plugin object
     */
    public function __construct($plugin)
    {
        $this->rc     = rcube::get_instance();
        $this->plugin = $plugin;
    }

    /**
     * Returns location of the chat app
     *
     * @param bool $websocket Return websocket URL
     *
     * @return string The chat app location
     */
    public function url($websocket = false)
    {
        $url = rtrim($this->rc->config->get('kolab_chat_url'), '/');

        if ($websocket) {
            $url  = str_replace(array('http://', 'https://'), array('ws://', 'wss://'), $url);
            $url .= '/api/v4/websocket';
        }
        else if ($this->rc->action == 'index' && $this->rc->task == 'kolab-chat') {
            $channel = rcube_utils::get_input_value('_channel', rcube_utils::INPUT_GET);
            if (empty($channel)) {
                $channel = $this->rc->config->get('kolab_chat_channel');
            }

            if ($channel) {
                $team = rcube_utils::get_input_value('_team', rcube_utils::INPUT_GET);
                if (empty($team) && ($_channel = $this->get_channel($channel, true))) {
                    $team = $_channel['team_name'];
                }

                if ($channel && $team) {
                    $url .= '/' . urlencode($team) . '/channels/' . urlencode($channel);
                }
            }
        }

        return $url;
    }

    /**
     * Add/register UI elements
     */
    public function ui()
    {
        if ($this->rc->task != 'kolab-chat') {
            $this->plugin->include_script("js/mattermost.js");
            $this->plugin->add_label('openchat', 'directmessage', 'mentionmessage');
        }
        else if ($this->get_token()) {
            $this->setcookie('MMUSERID', $_SESSION['mattermost'][0]);
            $this->setcookie('MMAUTHTOKEN', $_SESSION['mattermost'][1]);
        }
    }

    /**
     * Driver specific actions handler
     */
    public function action()
    {
        $result = array(
            'url'     => $this->url(true),
            'token'   => $this->get_token(),
            'user_id' => $this->get_user_id(),
        );

        echo rcube_output::json_serialize($result);
        exit;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into Chat settings sections in Preferences.
     *
     * @param array &$p Hook parameters
     */
    public function preferences_list(&$p)
    {
        $no_override = array_flip((array) $this->rc->config->get('dont_override'));

        if (!isset($no_override['kolab_chat_channel'])) {
            $field_id = 'rcmfd_kolab_chat_channel';
            $select   = new html_select(array('name' => '_kolab_chat_channel', 'id' => $field_id));

            $select->add('---', '');
            if ($channels = $this->get_channels_list()) {
                foreach ($channels as $channel) {
                    if ($channel['name'] && $channel['display_name']) {
                        $select->add($channel['display_name'], $channel['id']);
                    }
                }
            }

            $p['blocks']['main']['options']['kolab_chat_channel'] = array(
                'title'   => html::label($field_id, rcube::Q($this->plugin->gettext('defaultchannel'))),
                'content' => $select->show($this->rc->config->get('kolab_chat_channel')),
            );
        }
    }

    /**
     * Handler for preferences_save hook.
     * Executed on Chat settings form submit.
     *
     * @param array &$p Hook parameters
     */
    public function preferences_save(&$p)
    {
        if (isset($_POST['_kolab_chat_channel'])) {
            $p['prefs']['kolab_chat_channel'] = rcube_utils::get_input_value('_kolab_chat_channel', rcube_utils::INPUT_POST);
        }
    }

    /**
     * Returns the Mattermost session token
     * Note: This works only if the user/pass is the same in Kolab and Mattermost
     *
     * @return string Session token
     */
    protected function get_token()
    {
        $user = $_SESSION['username'];
        $pass = $this->rc->decrypt($_SESSION['password']);

        // Use existing token if still valid
        if (!empty($_SESSION['mattermost'])) {
            $user_id = $_SESSION['mattermost'][0];
            $token   = $_SESSION['mattermost'][1];

            if ($this->token_valid) {
                return $token;
            }

            try {
                $request = $this->get_api_request('GET', '/users/me');
                $request->setHeader('Authorization', "Bearer $token");

                $response = $request->send();
                $status   = $response->getStatus();

                if ($status != 200) {
                    $token = null;
                }
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, false);
                $token = null;
            }
        }

        // Request a new session token
        if (empty($token)) {
            $request = $this->get_api_request('POST', '/users/login');
            $request->setBody(json_encode(array(
                    'login_id' => $user,
                    'password' => $pass,
            )));

            // send request to the API, get token and user ID
            try {
                $response = $request->send();
                $status   = $response->getStatus();
                $token    = $response->getHeader('Token');
                $body     = json_decode($response->getBody(), true);

                if ($status == 200) {
                    $user_id = $body['id'];
                }
                else if (is_array($body) && $body['message']) {
                    throw new Exception($body['message']);
                }
                else {
                    throw new Exception("Failed to authenticate the chat user ($user). Status: $status");
                }
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, false);
            }
        }

        if ($user_id && $token) {
            $this->token_valid = true;
            $_SESSION['mattermost'] = array($user_id, $token);
            return $token;
        }
    }

    /**
     * Returns the Mattermost user ID
     * Note: This works only if the user/pass is the same in Kolab and Mattermost
     *
     * @return string User ID
     */
    protected function get_user_id()
    {
        if ($token = $this->get_token()) {
            return $_SESSION['mattermost'][0];
        }
    }

    /**
     * Returns the Mattermost channel info
     *
     * @param string $channel_id Channel ID
     * @param bool   $extended   Return extended information (i.e. team information)
     *
     * @return array Channel information
     */
    protected function get_channel($channel_id, $extended = false)
    {
        $channel = $this->api_get('/channels/' . urlencode($channel_id));

        if ($extended && is_array($channel)) {
            if (!empty($channel['team_id'])) {
                 $team = $this->api_get('/teams/' . urlencode($channel['team_id']));
            }
            else if ($teams = $this->get_user_teams()) {
                // if there's no team assigned to the channel, we'll get the user's team
                // so we can build proper channel URL, there's no other way in the API
                $team = $teams[0];
            }

            if (!empty($team)) {
                $channel['team_id']   = $team['id'];
                $channel['team_name'] = $team['name'];
            }
        }

        return $channel;
    }

    /**
     * Returns the Mattermost channels list for the user
     *
     * @return array Channels list (id, name, display_name, etc.)
     */
    protected function get_channels_list()
    {
        // FIXME: Should we list only public channels here?
        // FIXME: SHould we check all user teams?

        $user_id = $this->get_user_id();
        $teams   = $this->get_user_teams();

        if (!empty($teams) && ($team = $teams[0])) {
            // FIXME: Why it does return channels not assigned to the specified team?
            return $this->api_get(sprintf('/users/%s/teams/%s/channels', urlencode($user_id), urlencode($team['id'])));
        }

        return false;
    }

    /**
     * Returns the Mattermost teams of the user
     *
     * @return array List of teams
     */
    protected function get_user_teams()
    {
        return $this->api_get('/users/' . urlencode($this->get_user_id()) . '/teams');
    }

    /**
     * Return HTTP/Request2 instance for Mattermost API connection
     */
    protected function get_api_request($type, $path)
    {
        $url      = rtrim($this->rc->config->get('kolab_chat_url'), '/');
        $defaults = array(
            'store_body'       => true,
            'follow_redirects' => true,
        );

        $config = array_merge($defaults, (array) $this->rc->config->get('kolab_chat_http_request'));

        return libkolab::http_request($url . '/api/v4' . $path, $type, $config);
    }

    /**
     * Call API GET command
     */
    protected function api_get($path)
    {
        if ($token = $this->get_token()) {
            try {
                $request = $this->get_api_request('GET', $path);
                $request->setHeader('Authorization', "Bearer $token");

                $response = $request->send();
                $status   = $response->getStatus();
                $body     = $response->getBody();

                if ($status == 200) {
                    return json_decode($body, true);
                }
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, false);
            }
        }
    }

    /**
     * Set mattermost session cookies
     */
    protected function setcookie($name, $value)
    {
        if (headers_sent()) {
            return;
        }

        $cookie = session_get_cookie_params();
        $secure = $cookie['secure'] || self::https_check();

        if ($domain = $this->rc->config->get('kolab_chat_session_domain')) {
            $cookie['domain'] = $domain;
        }

        setcookie($name, $value, 0, $cookie['path'], $cookie['domain'], $secure, false);
    }
}
