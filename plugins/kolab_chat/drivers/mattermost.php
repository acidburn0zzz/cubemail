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
            if (($channel = rcube_utils::get_input_value('_channel', rcube_utils::INPUT_GET))
                && ($channel = $this->get_channel($channel))
            ) {
                // FIXME: This does not work yet because team_id is empty for direct-message channels
                $url .= '/' . urlencode($channel['team_name']) . '/channels/' . urlencode($channel['id']);
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
            $this->plugin->add_label('openchat', 'directmessage');
        }
        else if ($this->get_token()) {
            rcube_utils::setcookie('MMUSERID', $_SESSION['mattermost'][0], 0, false);
            rcube_utils::setcookie('MMAUTHTOKEN', $_SESSION['mattermost'][1], 0, false);
        }
    }

    /**
     * Driver specific actions handler
     */
    public function action()
    {
        $result = array(
            'url'   => $this->url(true),
            'token' => $this->get_token(),
        );

        echo rcube_output::json_serialize($result);
        exit;
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

            try {
                $request = $this->get_api_request('GET', '/api/v4/users/me');
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
            $request = $this->get_api_request('POST', '/api/v4/users/login');
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
            $_SESSION['mattermost'] = array($user_id, $token);
            return $token;
        }
    }

    /**
     * Returns the Mattermost channel info
     *
     * @param string $channel_id Channel ID
     *
     * @return array Channel information
     */
    protected function get_channel($channel_id)
    {
        $token = $this->get_token();

        if ($token) {
            $channel = $this->api_get('/api/v4/channels/' . urlencode($channel_id), $token);
        }

        if (is_array($channel) && !empty($channel['team_id'])) {
            if ($team = $this->api_get('/api/v4/teams/' . urlencode($channel['team_id']), $token)) {
                $channel['team_name'] = $team['name'];
            }
        }

        return $channel;
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

        return libkolab::http_request($url . $path, $type, $config);
    }

    /**
     * Call API GET command
     */
    protected function api_get($path, $token = null)
    {
        if (!$token) {
            $token = $this->get_token();
        }

        if ($token) {
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
}
