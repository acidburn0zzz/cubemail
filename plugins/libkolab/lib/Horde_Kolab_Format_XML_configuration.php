<?php
/**
 * Implementation for configuration objects in the Kolab XML format (KEP:9 and KEP:16)
 *
 * PHP version 5
 *
 * @category Kolab
 * @package  Kolab_Format
 * @author   Aleksander Machniak <machniak@kolabsys.com>
 * @author   Thomas Bruederli <bruederli@kolabsys.com>
 * @license  http://www.gnu.org/licenses/agpl-3.0.html AGPL 3
 */

/**
 * Kolab XML handler for client preferences.
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
 *
 * @category Kolab
 * @package  Kolab_Format
 * @author   Aleksander Machniak <machniak@kolabsys.com>
 * @author   Thomas Bruederli <bruederli@kolabsys.com>
 * @license  http://www.gnu.org/licenses/agpl-3.0.html AGPL 3
 */
class Horde_Kolab_Format_Xml_Configuration extends Horde_Kolab_Format_Xml
{
    /**
     * The name of the root element.
     *
     * @var string
     */
    protected $_root_name = 'configuration';

    /**
     * Specific data fields for the prefs object
     *
     * @var Kolab
     */
    protected $_fields_specific = array(
        'application' => 'Horde_Kolab_Format_Xml_Type_String_MaybeMissing',
        'type' => 'Horde_Kolab_Format_Xml_Type_String',
    );

    function __construct(
        Horde_Kolab_Format_Xml_Parser $parser,
        Horde_Kolab_Format_Factory $factory,
        $params = null
    )
    {
        // Dictionary fields
        if (!empty($params['subtype']) && preg_match('/^dictionary.*/', $params['subtype'])) {
            $this->_fields_specific += array(
                'language' => 'Horde_Kolab_Format_Xml_Type_String',
                'e' => 'Horde_Kolab_Format_Xml_Type_Multiple_String',
            );
        }

        parent::__construct($parser, $factory, $params);
    }
}

