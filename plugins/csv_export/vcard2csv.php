<?php

/**
 * vCard to CSV converter
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

class vcard2csv
{
    /**
     * CSV label to text mapping for English
     *
     * @var array
     */
    protected $fields = array(
        'name'              => "Display Name",
        'prefix'            => "Prefix",
        'firstname'         => "First Name",
        'middlename'        => "Middle Name",
        'surname'           => "Last Name",
        'suffix'            => "Suffix",
        'nickname'          => "Nick Name",

        'birthday'          => "Birthday",
        'anniversary'       => "Anniversary",

        'email:home'        => "E-mail - Home",
        'email:work'        => "E-mail - Work",
        'email:other'       => "E-mail - Other",

        'address:home^street'   => "Home Address Street",
        'address:home^locality' => "Home Address City",
        'address:home^zipcode'  => "Home Address Zip Code",
        'address:home^region'   => "Home Address Region",
        'address:home^country'  => "Home Address Country",

        'address:work^street'   => "Work Address Street",
        'address:work^locality' => "Work Address City",
        'address:work^zipcode'  => "Work Address Zip Code",
        'address:work^region'   => "Work Address Region",
        'address:work^country'  => "Work Address Country",

        'address:other^street'   => "Other Address Street",
        'address:other^locality' => "Other Address City",
        'address:other^zipcode'  => "Other Address Zip Code",
        'address:other^region'   => "Other Address Region",
        'address:other^country'  => "Other Address Country",

        'phone:home'        => "Home Phone",
        'phone:work'        => "Work Phone",
        'phone:mobile'      => "Mobile Phone",
        'phone:other'       => "Other Phone",
        'phone:homefax'     => "Home Fax",
        'phone:workfax'     => "Work Fax",
        'phone:pager'       => "Pager",

        'organization'      => "Organization",
        'department'        => "Department",
        'jobtitle'          => "Job Title",
        'manager'           => "Manager",

        'gender'            => "Gender",
        'assistant'         => "Assistant",
        'phone:assistant'   => "Assistant's Phone",
        'spouse'            => "Spouse",

        'groups'            => "Categories",
        'notes'             => "Notes",

        'website:homepage'  => "Home Web Page",
        'website:work'      => "Work Web Page",

        'im:jabber' => "Jabber",
        'im:skype'  => "Skype",
        'im:msn'    => "MSN",
    );


    /**
     * Convert vCard to CSV record
     *
     * @param string $vcard vCard data (single contact)
     *
     * @return string CSV record
     */
    public function record($vcard)
    {
        // Parse vCard
        $rcube_vcard = new rcube_vcard();
        $list        = $rcube_vcard->import($vcard);

        if (empty($list)) {
            return;
        }

        // Get contact data
        $data = $list[0]->get_assoc();
        $csv  = array();

        foreach (array_keys($this->fields) as $key) {
            list($key, $subkey) = explode('^', $key);
            $value = $data[$key];

            if (is_array($value)) {
                $value = $value[0];
            }

            if ($subkey) {
                $value = is_array($value) ? $value[$subkey] : '';
            }

            switch ($key) {
            case 'groups':
                $value = implode(';', (array) $data['groups']);
                break;
/*
            case 'photo':
                if ($value && !preg_match('/^https?:/', $value)) {
                    $value = base64_encode($value);
                }
                break;
*/
            }

            $csv[] = $value;
        }

        return $this->csv($csv);
    }

    /**
     * Build csv data header (list of field names)
     *
     * @return string CSV file header
     */
    public function head()
    {
        return $this->csv($this->fields);
    }

    /**
     * Send headers of file download
     */
    public static function headers()
    {
        // send downlaod headers
        header('Content-Type: text/csv; charset=' . RCUBE_CHARSET);
        header('Content-Disposition: attachment; filename="contacts.csv"');
    }

    protected function csv($fields = array(), $delimiter = ',', $enclosure = '"')
    {
        $str         = '';
        $escape_char = "\\";

        foreach ($fields as $value) {
            if (strpos($value, $delimiter) !== false
                || strpos($value, $enclosure) !== false
                || strpos($value, ' ') !== false
                || strpos($value, "\n") !== false
                || strpos($value, "\r") !== false
                || strpos($value, "\t") !== false
            ) {
                $str2    = $enclosure;
                $escaped = 0;
                $len     = strlen($value);

                for ($i=0; $i<$len; $i++) {
                    if ($value[$i] == $escape_char) {
                        $escaped = 1;
                    }
                    else if (!$escaped && $value[$i] == $enclosure) {
                        $str2 .= $enclosure;
                    }
                    else {
                        $escaped = 0;
                    }

                    $str2 .= $value[$i];
                }

                $str2 .= $enclosure;
                $str  .= $str2 . $delimiter;
            }
            else {
                $str .= $value . $delimiter;
            }
        }

        if (!empty($fields)) {
            $str[strlen($str)-1] = "\n";
        }

        return $str;
   }
}
