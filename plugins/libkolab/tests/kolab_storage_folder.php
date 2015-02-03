<?php

/**
 * libkolab/kolab_storage_folder class tests
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

class kolab_storage_folder_test extends PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        // load libkolab plugin
        $rcmail = rcmail::get_instance();
        $rcmail->plugins->load_plugin('libkolab', true, true);

        if ($rcmail->config->get('tests_username')) {
            $authenticated = $rcmail->login(
                $rcmail->config->get('tests_username'),
                $rcmail->config->get('tests_password'),
                $rcmail->config->get('default_host'),
                false
            );

            if (!$authenticated) {
                throw new Exception('IMAP login failed for user ' . $rcmail->config->get('tests_username'));
            }
        }
        else {
            throw new Exception('Missing test account username/password in config-test.inc.php');
        }

        kolab_storage::setup();
    }

    function test_folder_type_check()
    {
        $folder = new kolab_storage_folder('Calendar', 'event', 'event.default');
        $this->assertTrue($folder->valid);
        $this->assertEquals($folder->get_error(), 0);

        $folder = new kolab_storage_folder('Calendar', 'event', 'mail');
        $this->assertFalse($folder->valid);
        $this->assertEquals($folder->get_error(), kolab_storage::ERROR_INVALID_FOLDER);
    }

    function test_get_resource_uri()
    {
        $rcmail = rcmail::get_instance();
        $foldername = 'Calendar';

        $folder = new kolab_storage_folder($foldername, 'event', 'event.default');
        $this->assertEquals($folder->get_resource_uri(), sprintf('imap://%s@%s/%s',
            urlencode($rcmail->config->get('tests_username')),
            $rcmail->config->get('default_host'),
            $foldername
        ));
    }

    function test_get_owner()
    {
        $rcmail = rcmail::get_instance();
        $folder = new kolab_storage_folder('Calendar', 'event', 'event');
        $this->assertEquals($folder->get_owner(), $rcmail->config->get('tests_username'));

        $domain = preg_replace('/^.+@/', '@', $rcmail->config->get('tests_username'));

        $shared_ns = kolab_storage::namespace_root('shared');
        $folder = new kolab_storage_folder($shared_ns . 'A-shared-folder', 'event', 'event');
        $this->assertEquals($folder->get_owner(true), 'anonymous' . $domain);

        $other_ns = kolab_storage::namespace_root('other');
        $folder = new kolab_storage_folder($other_ns . 'major.tom/Calendar', 'event', 'event');
        $this->assertEquals($folder->get_owner(true), 'major.tom' . $domain);
    }

    function test_get_uid()
    {
        $rcmail = rcmail::get_instance();
        $folder = new kolab_storage_folder('Doesnt-Exist', 'event', 'event');

        // generate UID from folder name if IMAP operations fail
        $uid1 = $folder->get_uid();
        $this->assertEquals($folder->get_uid(), $uid1);
        $this->assertEquals($folder->get_error(), kolab_storage::ERROR_IMAP_CONN);
    }
}
