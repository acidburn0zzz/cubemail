<?php

/**
 * Single Sign On Authentication for Kolab
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2018, Kolab Systems AG <contact@kolabsys.com>
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
class kolab_sso extends rcube_plugin
{
    public $rc;

    private $data;
    private $driver;
    private $debug = false;


    /**
     * Plugin initialization
     */
    public function init()
    {
        $this->rc = rcube::get_instance();

        // Disable the plugin in Syncroton, iRony, etc.
        if (!($this->rc instanceof rcmail)) {
            return;
        }

        $this->add_hook('startup', array($this, 'startup'));
        $this->add_hook('ready', array($this, 'ready'));
        $this->add_hook('login_after', array($this, 'ready'));
        $this->add_hook('authenticate', array($this, 'authenticate'));
    }

    /**
     * Startup hook handler
     */
    public function startup($args)
    {
        // On login or logout (or when the session expired)...
        if ($args['task'] == 'login' || $args['task'] == 'logout') {
            $mode = $args['action'] == 'sso' ? $_SESSION['sso_mode'] : rcube_utils::get_input_value('_sso', rcube_utils::INPUT_GP);

            // Authorization
            if ($mode) {
                $driver = $this->get_driver($mode);

                // This is where we handle redirections from the SSO provider
                if ($args['action'] == 'sso') {
                    $this->data = $driver->response();

                    if (!empty($this->data)) {
                        $this->data['timezone'] = $_SESSION['sso_timezone'];
                        $this->data['url']      = $_SESSION['sso_url'];
                        $this->data['mode']     = $mode;
                    }
                }
                // This is where we handle clicking one of "Login by SSO" buttons
                else if ($_SESSION['temp'] && $this->rc->check_request()) {
                    // Remember some logon params for use on SSO response above
                    $_SESSION['sso_timezone'] = rcube_utils::get_input_value('_timezone', rcube_utils::INPUT_POST);
                    $_SESSION['sso_url']      = rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST);
                    $_SESSION['sso_mode']     = $mode;

                    $driver->authorize();
                }

                $args['action'] = 'login';
                $args['task']   = 'login';
            }
        }
        // On valid session...
        else if (isset($_SESSION['user_id'])
            && ($data = $_SESSION['sso_data'])
            && ($data = json_decode($this->rc->decrypt($data), true))
            && ($mode = $data['mode'])
        ) {
            $driver = $this->get_driver($mode);

            // Session validation, token refresh, etc.
            if ($this->data = $driver->validate_session($data)) {
                // register storage connection hooks
                $this->authenticate(array(), true);
            }
            else {
                // Destroy the session
                $this->rc->kill_session();
                // TODO: error message beter explaining the reason
                // $this->rc->output->show_message('sessionferror', 'error');
            }
        }

        // Register login form modifications
        $this->add_hook('template_object_loginform', array($this, 'login_form'));

        return $args;
    }

    /**
     * Authenticate hook handler
     */
    public function authenticate($args, $internal = false)
    {
        if (!empty($this->data) && ($email = $this->data['email'])) {
            if (!$internal) {
                $args['user']        = $email;
                $args['pass']        = 'fake-sso-password';
                $args['valid']       = true;
                $args['cookiecheck'] = false;

                $_POST['_timezone'] = $this->data['timezone'];
                $_POST['_url']      = $this->data['url'];
            }

            $this->add_hook('storage_connect', array($this, 'storage_connect'));
            $this->add_hook('managesieve_connect', array($this, 'storage_connect'));
            $this->add_hook('smtp_connect', array($this, 'smtp_connect'));
        }

        return $args;
    }

    /**
     * Login_after hook handler
     */
    public function login_after($args)
    {
        // Between startup and authenticate the session is destroyed.
        // So, we save the data later than that. Here, because of
        // a redirect after successful login
        if (!empty($this->data)) {
            $_SESSION['sso_data'] = $this->rc->encrypt(json_encode($this->data));
        }

        return $args;
    }

    /**
     * Ready hook handler
     */
    public function ready($args)
    {
        // Between startup and authenticate the session is destroyed.
        // So, we save the data later than that.
        if (!empty($this->data)) {
            $_SESSION['sso_data'] = $this->rc->encrypt(json_encode($this->data));
        }

        return $args;
    }

    /**
     * Storage_connect/managesieve_connect hook handler
     */
    public function storage_connect($args)
    {
        $user = $this->rc->config->get('kolab_sso_username');
        $pass = $this->rc->config->get('kolab_sso_password');

        if ($user && $pass) {
            $args['auth_cid']  = $user;
            $args['auth_pw']   = $pass;
            $args['auth_type'] = 'PLAIN';
        }

        return $args;
    }

    /**
     * Smtp_connect hook handler
     */
    public function smtp_connect($args)
    {
        foreach (array('smtp_server', 'smtp_user', 'smtp_pass') as $prop) {
            $args[$prop] = $this->rc->config->get("kolab_sso_$prop", $args[$prop]);
        }

        return $args;
    }

    /**
     * Login form object
     */
    public function login_form($args)
    {
        $this->load_config();

        $options       = (array) $this->rc->config->get('kolab_sso_options');
        $disable_login = $this->rc->config->get('kolab_sso_disable_login');

        if (empty($options)) {
            return $args;
        }

        $doc = new DOMDocumentHelper('1.0');
        $doc->loadHTML($args['content']);

        $body = $doc->getElementsByTagName('body')->item(0);

        if ($disable_login) {
            // Remove login form inputs table
            $table = $doc->getElementsByTagName('table')->item(0);
            $table->parentNode->removeChild($table);

            // Remove original Submit button
            $submit = $doc->getElementsByTagName('button')->item(0);
            $submit->parentNode->removeChild($submit);
        }

        if (!$this->driver) {
            $this->add_texts('localization/');
        }

        // Add SSO form elements
        $form = $doc->createNode('p', null, array('id' => 'sso-form', 'class' => 'formbuttons'), $body);

        foreach ($options as $idx => $option) {
            $label = array('name' => 'loginby', 'vars' => array('provider' => $option['name'] ?: $this->gettext('sso')));
            $doc->createNode('button', $this->gettext($label), array(
                    'type'    => 'button',
                    'value'   => $idx,
                    'class'   => 'button sso w-100',
                    'onclick' => 'kolab_sso_submit(this)',
                ), $form);
        }

        $doc->createNode('input', null, array('name' => '_sso', 'type' => 'hidden'), $form);

        // Save the form content back
        $args['content'] = preg_replace('|</?body>|', '', $doc->saveHTML($body));

        // Add script
        $args['content'] .= "<script>"
            . "function kolab_sso_submit(button) {"
                . "\$('[name=_sso]').val(button.value);"
                . "\$('input[type=text],input[type=password]').attr('required', false);"
                . "rcmail.gui_objects.loginform.submit();"
            . "}"
            . "</script>";

        return $args;
    }

    /**
     * Debug function for drivers
     */
    public function debug($line)
    {
        if ($this->debug) {
            rcube::write_log('sso', $line);
        }
    }

    /**
     * Initialize SSO driver object
     */
    private function get_driver($name)
    {
        if ($this->driver) {
            return $this->driver;
        }

        $this->load_config();
        $this->add_texts('localization/');

        $options = (array) $this->rc->config->get('kolab_sso_options');
        $options = (array) $options[$name];
        $driver  = $options['driver'] ?: 'openidc';
        $class   = "kolab_sso_$driver";

        if (empty($options) || !file_exists($this->home . "/drivers/$driver.php")) {
            rcube::raise_error(array(
                    'line' => __LINE__, 'file' => __FILE__,
                    'message' => "Unable to find SSO driver"
                ), true, true);
        }

        // Add /lib to include_path
        $include_path = $this->home . '/lib' . PATH_SEPARATOR;
        $include_path .= ini_get('include_path');
        set_include_path($include_path);

        require_once $this->home . "/drivers/$driver.php";

        $this->debug  = $this->rc->config->get('kolab_sso_debug');
        $this->driver = new $class($this, $options);

        return $this->driver;
    }
}

/**
 * DOMDocument wrapper with some shortcut method
 */
class DOMDocumentHelper extends DOMDocument
{
    public function createNode($name, $value = null, $args = array(), $parent = null, $prepend = false)
    {
        $node = parent::createElement($name);

        if ($value) {
            $node->appendChild(new DOMText(rcube::Q($value)));
        }

        foreach ($args as $attr_name => $attr_value) {
            $node->setAttribute($attr_name, $attr_value);
        }

        if ($parent) {
            if ($prepend && $parent->firstChild) {
                $parent->insertBefore($node, $parent->firstChild);
            }
            else {
                $parent->appendChild($node);
            }
        }

        return $node;
    }
}
