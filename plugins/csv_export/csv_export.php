<?php

/**
 * Contacts export in csv format
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011-2016, Kolab Systems AG <contact@kolabsys.com>
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

class csv_export extends rcube_plugin
{
    public $task = 'addressbook';


    /**
     * Startup method of a Roundcube plugin
     */
    public function init()
    {
        $rcmail = rcube::get_instance();

        // register hooks
        $this->add_hook('addressbook_export', array($this, 'addressbook_export'));

        // Add localization and js script
        if ($this->api->output->type == 'html' && !$this->rcmail->action) {
            $this->add_texts('localization', true);
            $this->api->output->add_label('export', 'cancel');
            $this->include_script('csv_export.js');
        }
    }

    /**
     * Handler for the addressbook_export hook.
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function addressbook_export($p)
    {
        if ($_GET['_format'] != 'csv') {
            return $p;
        }

        global $CONTACTS;

        require_once(__DIR__ . '/vcard2csv.php');

        $csv = new vcard2csv;

        // send downlaod headers
        $csv->headers();

        if (!$p['result']) {
            exit;
        }

        echo $csv->head();

        while ($row = $p['result']->next()) {
            if ($CONTACTS) {
                prepare_for_export($row, $CONTACTS);
            }

            echo $csv->record($row['vcard']);
        }

        exit;
    }
}
