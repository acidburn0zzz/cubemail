<?php

/**
 * Kolab 2-Factor-Authentication plugin
 *
 * ...
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2015, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_2fa extends rcube_plugin
{
    public $task = '(login|settings)';

    protected $login_verified = null;
    protected $login_factors = array();
    protected $drivers = array();

    /**
     * Plugin init
     */
    public function init()
    {
        $this->load_config();
        $this->add_hook('startup', array($this, 'startup'));
    }

    /**
     * Startup hook
     */
    public function startup($args)
    {
        $rcmail = rcmail::get_instance();

        if ($args['task'] === 'login' && $this->api->output) {
            $this->add_texts('localization/', false);
            $this->add_hook('authenticate', array($this, 'authenticate'));

            // process 2nd factor auth step after regular login
            if ($args['action'] === 'plugin.kolab-2fa-login' /* || !empty($_SESSION['kolab_2fa_factors']) */) {
                return $this->login_verify($args);
            }
        }
        else if ($args['task'] === 'settings') {
            $this->add_texts('localization/', !$this->api->output->ajax_call);
            $this->add_hook('settings_actions', array($this, 'settings_actions'));
            $this->register_action('plugin.kolab-2fa', array($this, 'settings_view'));
            $this->register_action('plugin.kolab-2fa-data', array($this, 'settings_data'));
            $this->register_action('plugin.kolab-2fa-save', array($this, 'settings_save'));
            $this->register_action('plugin.kolab-2fa-verify', array($this, 'settings_verify'));
        }

        return $args;
    }

    /**
     * Handler for 'authenticate' plugin hook.
     *
     * ATTENTION: needs to be called *after* kolab_auth::authenticate()
     */
    public function authenticate($args)
    {
        // nothing to be done for me
        if ($args['abort'] || $this->login_verified !== null) {
            return $args;
        }

        $rcmail = rcmail::get_instance();

        // parse $host URL
        $a_host = parse_url($args['host']);
        $hostname = $a_host['host'] ?: $args['host'];

        // 1. find user record (and its prefs) before IMAP login
        if ($user = rcube_user::query($args['user'], $hostname)) {
            $rcmail->config->set_user_prefs($user->get_prefs());
        }

        // 2. check if this user/system has 2FA enabled
        if (count($factors = (array)$rcmail->config->get('kolab_2fa_factors', array())) > 0) {
            $args['abort'] = true;

            // 3. flag session as temporary (no further actions allowed)
            $_SESSION['kolab_2fa_time'] = time();
            $_SESSION['kolab_2fa_nonce'] = bin2hex(openssl_random_pseudo_bytes(32));
            $_SESSION['kolab_2fa_factors'] = $factors;

            $_SESSION['username'] = $args['user'];
            $_SESSION['hostname'] = $hostname;
            $_SESSION['host']     = $args['host'];
            $_SESSION['password'] = $rcmail->encrypt($args['pass']);

            // 4. render to 2nd auth step
            $this->login_step($factors);
        }

        return $args;
    }

    /**
     * Handler for the additional login step requesting the 2FA verification code
     */
    public function login_step($factors)
    {
        // replace handler for login form
        $this->login_factors = $factors;
        $this->api->output->add_handler('loginform', array($this, 'auth_form'));

        // focus the code input field on load
        $this->api->output->add_script('$("input.kolab2facode").first().select();', 'docready');

        $this->api->output->send('login');
    }

    /**
     * Process the 2nd factor code verification form submission
     */
    public function login_verify($args)
    {
        $rcmail = rcmail::get_instance();

        $time  = $_SESSION['kolab_2fa_time'];
        $nonce = $_SESSION['kolab_2fa_nonce'];
        $factors = (array)$_SESSION['kolab_2fa_factors'];
        $sign = rcube_utils::get_input_value('_sign', rcube_utils::INPUT_POST);

        $this->login_verified = false;
        $expired = $time < time() - $rcmail->config->get('kolab_2fa_timeout', 120);

        if (!empty($sign) && !empty($factors) && !empty($nonce) && !$expired) {
            console('VERIFY', $sign, $factors);

            // TODO: check signature

            // try to verify each configured factor
            foreach ($factors as $method) {
                // verify the submitted code
                $code = rcube_utils::get_input_value("_${nonce}_${method}", rcube_utils::INPUT_POST);
                $this->login_verified = $this->verify_factor_auth($method, $code);

                // accept first successful method
                if ($this->login_verified) {
                    break;
                }
            }
        }

        if ($this->login_verified) {
            // restore POST data from session
            $_POST['_user'] = $_SESSION['username'];
            $_POST['_host'] = $_SESSION['host'];
            $_POST['_pass'] = $rcmail->decrypt($_SESSION['password']);
        }

        // proceed with regular login ...
        $args['action'] = 'login';

        // session data will be reset in index.php thus additional
        // auth attempts with intercepted data will be rejected
        // $rcmail->kill_session();

        // we can't display any custom error messages on failed login
        // but that's actually desired to expose as little information as possible

        return $args;
    }
    
    /**
     * Helper method to verify the given method/code tuple
     */
    protected function verify_factor_auth($method, $code)
    {
        if (strlen($code) && ($driver = $this->get_driver($method))) {
            // set properties from login
            $driver->username  = $_SESSION['username'];

            try {
                // verify the submitted code
                return $driver->verify($code, $_SESSION['kolab_2fa_time']);
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, false);
            }
        }

        return false;
    }

    /**
     * Render 2nd factor authentication form in place of the regular login form
     */
    public function auth_form($attrib = array())
    {
        $form_name  = !empty($attrib['form']) ? $attrib['form'] : 'form';
        $nonce = $_SESSION['kolab_2fa_nonce'];

        // forward these values as the regular login screen would submit them
        $input_task   = new html_hiddenfield(array('name' => '_task', 'value' => 'login'));
        $input_action = new html_hiddenfield(array('name' => '_action', 'value' => 'plugin.kolab-2fa-login'));
        $input_tzone  = new html_hiddenfield(array('name' => '_timezone', 'id' => 'rcmlogintz', 'value' => rcube_utils::get_input_value('_timezone', rcube_utils::INPUT_POST)));
        $input_url    = new html_hiddenfield(array('name' => '_url', 'id' => 'rcmloginurl', 'value' => rcube_utils::get_input_value('_url', rcube_utils::INPUT_POST)));
        // TODO: generate request signature
        $input_sign   = new html_hiddenfield(array('name' => '_sign', 'id' => 'rcmloginsign', 'value' => 'XXX'));

        // create HTML table with two cols
        $table = new html_table(array('cols' => 2));
        $required = count($this->login_factors) > 1 ? null : 'required';

        // render input for each configured auth method
        foreach ($this->login_factors as $i => $method) {
            if ($i > 0) {
                $table->add(array('colspan' => 2, 'class' => 'hint'), $this->gettext('or'));
            }

            $field_id = "rcmlogin2fa$method";
            $input_code = new html_inputfield(array('name' => "_${nonce}_${method}", 'class' => 'kolab2facode', 'id' => $field_id, 'required' => $required, 'autocomplete' => 'off')
                + $attrib);
            $table->add('title', html::label($field_id, html::quote($this->gettext($method))));
            $table->add('input', $input_code->show(''));
        }

        $out  = $input_task->show();
        $out .= $input_action->show();
        $out .= $input_tzone->show();
        $out .= $input_url->show();
        $out .= $input_sign->show();
        $out .= $table->show();

        // add submit button
        if (rcube_utils::get_boolean($attrib['submit'])) {
            $submit = new html_inputfield(array('type' => 'submit', 'id' => 'rcmloginsubmit',
                'class' => 'button mainaction', 'value' => $this->gettext('continue')));
            $out .= html::p('formbuttons', $submit->show());
        }

        // surround html output with a form tag
        if (empty($attrib['form'])) {
            $out = $this->api->output->form_tag(array('name' => $form_name, 'method' => 'post'), $out);
        }

        return $out;
    }

    /**
     * Load driver class for the given method
     */
    public function get_driver($method)
    {
        $rcmail = rcmail::get_instance();
        $method = strtolower($method);
        $valid  = in_array($method, $rcmail->config->get('kolab_2fa_drivers', array()));

        if (!$valid) {
            return false;
        }

        if ($this->drivers[$method]) {
            return $this->drivers[$method];
        }

        // register library namespace to autoloader
        if (!class_exists('\\Kolab3FA\\Driver\\Base', false)) {
            $loader = include(INSTALL_PATH . 'vendor/autoload.php');
            $loader->set('Kolab2FA', array($this->home . '/lib'));
        }

        $config = $rcmail->config->get('kolab_2fa_' . $method, array());

        // use product name as "issuer""
        if (empty($config['issuer'])) {
            $config['issuer'] = $rcmail->config->get('product_name');
        }

        try {
            // TODO: use external auth service if configured

            $driver = \Kolab2FA\Driver\Base::factory($method, $config);

            // attach storage
            $driver->storage = \Kolab2FA\Storage\Base::factory(
                $rcmail->config->get('kolab_2fa_storage', 'roundcube'),
                $rcmail->config->get('kolab_2fa_storage_config', array())
            );

            // set user properties from active session
            if ($rcmail->user->ID) {
                $driver->username  = $rcmail->get_user_name();
            }

            $this->drivers[$method] = $driver;
            return $driver;
        }
        catch (Exception $e) {
            $error = strval($e);
        }

        rcube::raise_error(array(
                'code' => 600,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => $error),
            true, false);

        return false;
    }

    /**
     * Handler for 'settings_actions' hook
     */
    public function settings_actions($args)
    {
        // register as settings action
        $args['actions'][] = array(
            'action' => 'plugin.kolab-2fa',
            'class'  => '2factorauth',
            'label'  => 'settingslist',
            'title'  => 'settingstitle',
            'domain' => 'kolab_2fa',
        );

        return $args;
    }

    /**
     * Handler for settings/plugin.kolab-2fa requests
     */
    public function settings_view()
    {
        $this->register_handler('plugin.settingsform', array($this, 'settings_form'));
        $this->register_handler('plugin.settingslist', array($this, 'settings_list'));
        $this->register_handler('plugin.factoradder', array($this, 'settings_factoradder'));
        $this->register_handler('plugin.highsecuritydialog', array($this, 'settings_highsecuritydialog'));

        $this->include_script('kolab2fa.js');
        $this->include_stylesheet($this->local_skin_path() . '/kolab2fa.css');

        if ($this->check_secure_mode()) {
            $this->api->output->set_env('session_secured', $_SESSION['kolab_2fa_secure_mode']);
        }

        $this->api->output->add_label('save','cancel');
        $this->api->output->set_pagetitle($this->gettext('settingstitle'));
        $this->api->output->send('kolab_2fa.config');
    }

    /**
     * Render the menu to add another authentication factor
     */
    public function settings_factoradder($attrib)
    {
        $rcmail = rcmail::get_instance();
        $active = (array)$rcmail->config->get('kolab_2fa_factors', array());

        $select = new html_select(array('id' => 'kolab2fa-add'));
        $select->add($this->gettext('addfactor') . '...', '');
        foreach ((array)$rcmail->config->get('kolab_2fa_drivers', array()) as $method) {
            $select->add($this->gettext($method), $method, array('disabled' => in_array($method, $active)));
        }

        return $select->show();
    }

    /**
     * Render a list of active factor this user has configured
     */
    public function settings_list($attrib = array())
    {
        $attrib['id'] = 'kolab2fa-factors';
        $table = new html_table(array('cols' => 3));

        $table->add_header('name', $this->gettext('factor'));
        $table->add_header('created', $this->gettext('created'));
        $table->add_header('actions', '');

        return $table->show($attrib);
    }

    /**
     * Render the settings form template object
     */
    public function settings_form($attrib = array())
    {
        $rcmail = rcmail::get_instance();
        $drivers = (array)$rcmail->config->get('kolab_2fa_drivers', array());
        $factors = (array)$rcmail->config->get('kolab_2fa_factors', array());

        foreach ($drivers as $j => $method) {
            $out .= $this->settings_factor($method, $attrib);
        }

        $me = $this;
        $this->api->output->set_env('kolab_2fa_factors', array_combine(
            $drivers,
            array_map(function($method) use ($me, $factors) {
                $props = array(
                    'name'   => $me->gettext($method),
                    'active' => in_array($method, $factors),
                );

                if ($props['active'] && ($driver = $me->get_driver($method))) {
                    $props += $this->format_props($driver->props());
                }

                return $props;
            }, $drivers)
        ));

        return html::div(array('id' => 'kolab2fapropform'), $out);
    }

    /**
     * Render the settings UI for the given method/driver
     */
    protected function settings_factor($method, $attrib)
    {
        $out = '';
        $rcmail = rcmail::get_instance();
        $attrib += array('class' => 'propform');
        $factors = (array)$rcmail->config->get('kolab_2fa_factors', array());

        if ($driver = $this->get_driver($method)) {
            $active = in_array($method, $factors);
            $table = new html_table(array('cols' => 2, 'class' => $attrib['class']));

            foreach ($driver->props() as $field => $prop) {
                if (!$prop['editable']) {
                    continue;
                }

                switch ($prop['type']) {
                    case 'boolean':
                    case 'checkbox':
                        $input = new html_checkbox(array('value' => '1'));
                        break;

                    case 'enum':
                    case 'select':
                        $input = new html_select(array('disabled' => $prop['readonly']));
                        $input->add(array_map(array($this, 'gettext'), $prop['options']), $prop['options']);
                        break;

                    default:
                        $input = new html_inputfield(array('size' => $prop['size'] ?: 30, 'disabled' => !$prop['editable']));
                }

                $explain_label = $field . 'explain' . $method;
                $explain_html = $rcmail->text_exists($explain_label, 'kolab_2fa') ? html::p('explain', $this->gettext($explain_label)) : '';

                $field_id = 'rcmk2fa' . $method . $field;
                $table->add('title', html::label($field_id, $this->gettext($field)));
                $table->add(null, $input->show('', array('id' => $field_id, 'name' => "_prop[$field]")) . $explain_html);
            }

            // add row for displaying the QR code
            if (method_exists($driver, 'get_provisioning_uri')) {
                $table->add('title', $this->gettext('qrcode'));
                $table->add(null,
                    html::p('explain',
                        $this->gettext("qrcodeexplain$method")
                    ) .
                    html::p(null,
                        html::tag('img', array('src' => 'data:image/gif;base64,R0lGODlhDwAPAIAAAMDAwAAAACH5BAEAAAAALAAAAAAPAA8AQAINhI+py+0Po5y02otnAQA7', 'class' => 'qrcode', 'rel' => $method))
                    )
                );

                // add row for testing the factor
                $field_id = 'rcmk2faverify' . $method;
                $table->add('title', html::label($field_id, $this->gettext('verifycode')));
                $table->add(null,
                    html::tag('input', array('type' => 'text', 'name' => '_verify_code', 'id' => $field_id, 'class' => 'k2fa-verify', 'size' => 20, 'required' => true)) .
                    html::p('explain', $this->gettext("verifycodeexplain$method"))
                );

            }

            $out .= html::tag('form', array(
                    'method' => 'post',
                    'action' => '#',
                    'id' => 'kolab2fa-prop-' . $method,
                    'style' => 'display:none',
                ),
                html::tag('fieldset', array(),
                    html::tag('legend', array(), $this->gettext($method)) .
                    html::div('factorprop', $table->show())
                )
            );
        }

        return $out;
    }
    
    /**
     * Render th
     */
    public function settings_highsecuritydialog($attrib = array())
    {
        $attrib += array('id' => 'kolab2fa-highsecuritydialog');

        $field_id = 'rcmk2facode';
        $input = new html_inputfield(array('name' => '_code', 'id' => $field_id, 'class' => 'verifycode', 'size' => 20));

        return html::div($attrib,
            html::p('explain', $this->gettext('highsecuritydialog')) .
            html::div('propform', html::label($field_id, '$name') . $input->show(''))
        );
    }

    /**
     * Handler for settings/plugin.kolab-2fa-save requests
     */
    public function settings_save()
    {
        $method = rcube_utils::get_input_value('_method', rcube_utils::INPUT_POST);
        $data = @json_decode(rcube_utils::get_input_value('_data', rcube_utils::INPUT_POST), true);

        $rcmail = rcmail::get_instance();
        $active = (array)$rcmail->config->get('kolab_2fa_factors', array());
        $success = false;
        $errors = 0;
        $save_data = array();

        if ($driver = $this->get_driver($method)) {
            if ($data === false) {
                if ($this->check_secure_mode()) {
                    // remove method from active factors and clear stored settings
                    $active = array_filter($active, function($f) use ($method) { return $f != $method; });
                    $driver->clear();
                }
                else {
                    $errors++;
                }
            }
            else {
                // verify the submitted code before saving
                $verify_code = rcube_utils::get_input_value('_verify_code', rcube_utils::INPUT_POST);
                $timestamp = intval(rcube_utils::get_input_value('_timestamp', rcube_utils::INPUT_POST));
                if (!empty($verify_code)) {
                    if (!$driver->verify($verify_code, $timestamp)) {
                        $this->api->output->command('plugin.verify_response', array(
                            'method' => $method,
                            'success' => false,
                            'message' => str_replace('$method', $this->gettext($method), $this->gettext('codeverificationfailed'))
                        ));
                        $this->api->output->send();
                    }
                }

                foreach ($data as $prop => $value) {
                    if (!$driver->set($prop, $value)) {
                        $errors++;
                    }
                }

                if (!in_array($method, $active)) {
                    $driver->set('active', true);
                    $active[] = $method;
                }
            }

            // update list of active factors for this user
            if (!$errors) {
                $success = $rcmail->user->save_prefs(array('kolab_2fa_factors' => $active));
                $save_data = $data !== false ? $this->format_props($driver->props()) : array();
            }
        }

        if ($success) {
            $this->api->output->show_message($data === false ? $this->gettext('factorremovesuccess') : $this->gettext('factorsavesuccess'), 'confirmation');
            $this->api->output->command('plugin.save_success', array('method' => $method, 'active' => $data !== false) + $save_data);
        }
        else if ($errors) {
            $this->api->output->show_message($this->gettext('factorsaveerror'), 'error');
            $this->api->output->command('plugin.reset_form', $method);
        }

        $this->api->output->send();
    }

    /**
     * Handler for settings/plugin.kolab-2fa-data requests
     */
    public function settings_data()
    {
        $method = rcube_utils::get_input_value('_method', rcube_utils::INPUT_POST);

        if ($driver = $this->get_driver($method)) {
            $data = array('_method' => $method);

            // abort if session is not authorized
            /*
            if ($driver->active && !$this->check_secure_mode()) {
                $this->api->output->send();
            }
            */

            foreach ($driver->props(true) as $field => $prop) {
                $data[$field] = $prop['text'] ?: $prop['value'];
            }

            // generate QR code for provisioning URI
            if (method_exists($driver, 'get_provisioning_uri')) {
                try {
                    $uri = $driver->get_provisioning_uri();

                    $qr = new Endroid\QrCode\QrCode();
                    $qr->setText($uri)
                       ->setSize(240)
                       ->setPadding(10)
                       ->setErrorCorrection('high')
                       ->setForegroundColor(array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0))
                       ->setBackgroundColor(array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 0));
                    $data['qrcode'] = base64_encode($qr->get());
                }
                catch (Exception $e) {
                    rcube::raise_error($e, true, false);
                }
            }

            $this->api->output->command('plugin.render_data', $data);
        }

        $this->api->output->send();
    }

    /**
     * Handler for settings/plugin.kolab-2fa-verify requests
     */
    public function settings_verify()
    {
        $method = rcube_utils::get_input_value('_method', rcube_utils::INPUT_POST);
        $timestamp = intval(rcube_utils::get_input_value('_timestamp', rcube_utils::INPUT_POST));
        $success = false;

        if ($driver = $this->get_driver($method)) {
            $data = @json_decode(rcube_utils::get_input_value('_data', rcube_utils::INPUT_POST), true);
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    if ($value !== '******') {
                        $driver->$key = $value;
                    }
                }
            }
            $success = $driver->verify(rcube_utils::get_input_value('_code', rcube_utils::INPUT_POST), $timestamp);
        }

        // put session into high-security mode
        if ($success && !empty($_POST['_session'])) {
            $_SESSION['kolab_2fa_secure_mode'] = time();
        }

        $this->api->output->command('plugin.verify_response', array(
            'method' => $method,
            'success' => $success,
            'message' => str_replace('$method', $this->gettext($method),
                $this->gettext($success ? 'codeverificationpassed' : 'codeverificationfailed'))
        ));
        $this->api->output->send();
    }

    /**
     *
     */
    protected function format_props($props)
    {
        $rcmail = rcmail::get_instance();
        $values = array();

        foreach ($props as $key => $prop) {
            switch ($prop['type']) {
                case 'datetime':
                    $value = $rcmail->format_date($prop['value']);
                    break;

                case 'boolean':
                    $value = $this->gettext($prop['value'] ? 'yes' : 'no');
                    break;

                default:
                    $value = $prop['value'];
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     *
     */
    protected function check_secure_mode()
    {
        $valid = ($_SESSION['kolab_2fa_secure_mode'] && $_SESSION['kolab_2fa_secure_mode'] > time() - 180);
        return $valid;
    }

}