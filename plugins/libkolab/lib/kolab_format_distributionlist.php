<?php

/**
 * Kolab Distribution List model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_distributionlist extends kolab_format
{
    public $CTYPE = 'application/x-vnd.kolab.distribution-list';

    protected $xmltype = 'distributionlist';


    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        if ($object['uid'])
            $this->kolab_object['uid'] = $object['uid'];

        $this->kolab_object['changed'] = new DateTime();
        $this->kolab_object['display-name'] = $object['name'];
        $this->kolab_object['member'] = array();

        foreach ($object['member'] as $member) {
            $this->kolab_object['member'][] = array(
                'uid' => $member['uid'],
                'smtp-address' => $member['email'],
                'display-name' => $member['name'],
            );
        }

        // set type property for proper caching
        $object['_type'] = 'distribution-list';

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    public function is_valid()
    {
        return !empty($this->data['uid']) && !empty($this->data['name']);
    }

    /**
     * Load data from old Kolab2 format
     */
    public function fromkolab2($record)
    {
        $object = array(
            'uid'     => $record['uid'],
            'changed' => $record['changed'],
            'name'    => $record['display-name'],
            'member'  => array(),
        );

        foreach ((array)$record['member'] as $member) {
            $object['member'][] = array(
                'email' => $member['smtp-address'],
                'name' => $member['display-name'],
                'uid' => $member['uid'],
            );
        }

        $this->data = $object;
    }

}
