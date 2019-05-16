<?php

/**
 * Allow specific user to impersonate as any other user
 * to services based on Roundcube Framework.
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2019, Kolab Systems AG <contact@kolabsys.com>
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
class kolab_auth_proxy extends rcube_plugin
{
    /**
     * Plugin initialization
     */
    public function init()
    {
        // Only iRony for now
        if (defined('KOLAB_DAV_VERSION')) {
            $this->add_hook('authenticate', array($this, 'authenticate'));
        }
    }

    /**
     * Authenticate hook handler
     */
    public function authenticate($args)
    {
        $this->load_config();
        $this->rc = rcube::get_instance();

        $proxy_user = $this->rc->config->get('kolab_auth_proxy_user');
        $proxy_pass = $this->rc->config->get('kolab_auth_proxy_pass');

        // Login is in a form of: <proxy_user>**<username>

        if ($proxy_user && $args['pass'] === $proxy_pass
            && strpos($args['user'], $proxy_user . '**') === 0
            && ($target = substr($args['user'], strlen($proxy_user . '**')))
        ) {
            $args['user'] = $target;
            $args['pass'] = '-dummy-'; // cannot be empty

            // Disable iRony's auth cache, otherwise 'authenticate' hook will not
            // be executed on each request
            $args['no-cache'] = true;

            $this->add_hook('storage_connect', array($this, 'storage_connect'));
//            $this->add_hook('managesieve_connect', array($this, 'storage_connect'));
            $this->add_hook('smtp_connect', array($this, 'smtp_connect'));
            $this->add_hook('ldap_connected', array($this, 'ldap_connected'));
        }

        return $args;
    }

    /**
     * Storage_connect/managesieve_connect hook handler
     */
    public function storage_connect($args)
    {
        $imap_user = $this->rc->config->get('kolab_auth_proxy_imap_user');
        $imap_pass = $this->rc->config->get('kolab_auth_proxy_imap_pass');

        $args['auth_cid']  = $imap_user;
        $args['auth_pw']   = $imap_pass;
        $args['auth_type'] = 'PLAIN';

        return $args;
    }

    /**
     * Smtp_connect hook handler
     */
    public function smtp_connect($args)
    {
        foreach (array('smtp_server', 'smtp_user', 'smtp_pass') as $prop) {
            $args[$prop] = $this->rc->config->get("kolab_auth_proxy_$prop", $args[$prop]);
        }

        return $args;
    }

    /**
     * ldap_connected hook handler
     */
    public function ldap_connected($args)
    {
        $ldap_user = $this->rc->config->get('kolab_auth_proxy_ldap_user');
        $ldap_pass = $this->rc->config->get('kolab_auth_proxy_ldap_pass');

        if ($ldap_user && $ldap_pass && $args['user_specific']) {
            $args['bind_dn']       = $ldap_user;
            $args['bind_pass']     = $ldap_pass;
            $args['search_filter'] = null;
        }

        return $args;
    }
}
