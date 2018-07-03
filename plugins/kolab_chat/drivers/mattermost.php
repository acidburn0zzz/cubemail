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
    public $rc;
    public $plugin;


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
     * @return string The chat app location
     */
    public function url()
    {
        return rtrim($this->rc->config->get('kolab_chat_url'), '/');
    }

    /**
     * Authenticates the user and sets cookies to auto-login the user
     * Note: This works only if the user/pass is the same in Kolab and Mattermost
     *
     * @param string $user Username
     * @param string $pass Password
     */
    public function authenticate($user, $pass)
    {
        $url = $this->url() . '/api/v4/users/login';

        $config = array(
            'store_body'       => true,
            'follow_redirects' => true,
        );

        $config  = array_merge($config, (array) $this->rc->config->get('kolab_chat_http_request'));
        $request = libkolab::http_request($url, 'POST', $config);

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

        // Set cookies
        if ($user_id && $token) {
            rcube_utils::setcookie('MMUSERID', $user_id, 0, false);
            rcube_utils::setcookie('MMAUTHTOKEN', $token, 0, false);
        }
    }
}
