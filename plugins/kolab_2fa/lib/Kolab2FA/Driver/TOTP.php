<?php

/**
 * Kolab 2-Factor-Authentication TOTP driver implementation
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

namespace Kolab2FA\Driver;

class TOTP extends Base
{
    public $method = 'totp';

    protected $config = array(
        'digits'   => 6,
        'interval' => 30,
        'digest'   => 'sha1',
    );

    public $user_settings = array(
        'secret' => array(
            'type' => 'text',
            'private' => true,
            'label' => 'secret',
            'generator' => 'generate_secret',
        ),
        'created' => array(
            'type' => 'datetime',
            'editable' => false,
            'hidden' => false,
            'label' => 'created',
            'generator' => 'time',
        ),
        'active' => array(
            'type' => 'boolean',
            'editable' => false,
            'hidden' => true,
        ),
    );

    protected $backend;

    /**
     *
     */
    public function init(array $config)
    {
        parent::init($config);

        // copy config options
        $this->backend = new \Kolab2FA\OTP\TOTP();
        $this->backend
            ->setDigits($this->config['digits'])
            ->setInterval($this->config['interval'])
            ->setDigest($this->config['digest'])
            ->setIssuer($this->config['issuer'])
            ->setIssuerIncludedAsParameter(true);
    }

    /**
     *
     */
    public function verify($code, $timestamp = null)
    {
        // get my secret from the user storage
        $secret = $this->get('secret');

        if (!strlen($secret)) {
            // LOG: "no secret set for user $this->username"
            return false;
        }

        $this->backend->setLabel($this->username)->setSecret($secret);
        $pass = $this->backend->verify($code);

        // try all codes from $timestamp till now
        if (!$pass && $timestamp) {
            $now = time();
            while (!$pass && $timestamp < $now) {
                $pass = $code === $this->backend->at($timestamp);
                $timestamp += $this->config['interval'];
            }
        }

        // console('VERIFY TOTP', $this->username, $secret, $code, $timestamp, $pass);
        return $pass;
    }

    /**
     *
     */
    public function get_provisioning_uri()
    {
        if (!$this->secret) {
            // generate new secret and store it
            $this->set('secret', $this->get('secret', true));
            $this->set('created', $this->get('created', true));
        }

        // TODO: deny call if already active?

        $this->backend->setLabel($this->username)->setSecret($this->secret);
        return $this->backend->getProvisioningUri();
    }

}
