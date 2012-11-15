<?php

/**
 * Kolab format model class
 *
 * Abstract base class for different Kolab groupware objects read from/written
 * to the Kolab 2 format using Horde_Kolab_Format classes.
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

abstract class kolab_format
{
    public static $timezone;

    public /*abstract*/ $CTYPE;

    protected /*abstract*/ $xmltype;
    protected /*abstract*/ $subtype;

    protected $handler;
    protected $data;
    protected $xmldata;
    protected $kolab_object;
    protected $loaded = false;
    protected $version = 2.0;

    const KTYPE_PREFIX = 'application/x-vnd.kolab.';
    const PRODUCT_ID = 'Roundcube-libkolab-horde-0.9';

    /**
     * Factory method to instantiate a kolab_format object of the given type and version
     *
     * @param string Object type to instantiate
     * @param float  Format version
     * @param string Cached xml data to initialize with
     * @return object kolab_format
     */
    public static function factory($type, $version = 2.0, $xmldata = null)
    {
        if (!isset(self::$timezone))
            self::$timezone = new DateTimeZone('UTC');

        if (!self::supports($version))
            return PEAR::raiseError("No support for Kolab format version " . $version);

        list($xmltype, $subtype) = explode('.', $type);

        $type = preg_replace('/configuration\.[a-z.]+$/', 'configuration', $type);
        $suffix = preg_replace('/[^a-z]+/', '', $xmltype);
        $classname = 'kolab_format_' . $suffix;
        if (class_exists($classname))
            return new $classname($xmldata, $subtype);

        return PEAR::raiseError("Failed to load Kolab Format wrapper for type " . $type);
    }

    /**
     * Determine support for the given format version
     *
     * @param float Format version to check
     * @return boolean True if supported, False otherwise
     */
    public static function supports($version)
    {
        if ($version == 2.0)
            return class_exists('Horde_Kolab_Format_Xml');

        return false;
    }

    /**
     * Convert the given date/time value into a structure for Horde_Kolab_Format_Xml_Type_DateTime
     *
     * @param mixed         Date/Time value either as unix timestamp, date string or PHP DateTime object
     * @param DateTimeZone  The timezone the date/time is in. Use global default if Null, local time if False
     * @param boolean       True of the given date has no time component
     * @return array        Hash array with date
     */
    public static function horde_datetime($datetime, $tz = null, $dateonly = false)
    {
        // use timezone information from datetime of global setting
        if (!$tz && $tz !== false) {
            if ($datetime instanceof DateTime)
                $tz = $datetime->getTimezone();
            if (!$tz)
                $tz = self::$timezone;
        }
        $result = null;

        // got a unix timestamp (in UTC)
        if (is_numeric($datetime)) {
            $datetime = new DateTime('@'.$datetime, new DateTimeZone('UTC'));
            if ($tz) $datetime->setTimezone($tz);
        }
        else if (is_string($datetime) && strlen($datetime)) {
            try { $datetime = new DateTime($datetime, $tz ?: null); }
            catch (Exception $e) { }
        }

        if ($datetime instanceof DateTime) {
            $result = array('date' => $datetime, 'date-only' => $dateonly || $datetime->_dateonly);
        }

        return $result;
    }

    /**
     * Convert the given Horde_Kolab_Format_Xml_Type_DateTime structure into a simple PHP DateTime object
     *
     * @param arrry   Hash array with datetime properties
     * @return object DateTime  PHP datetime instance
     */
    public static function php_datetime($data)
    {
        if (is_array($data)) {
            $d = $data['date'];
            if (is_a($d, 'DateTime')) {
                if ($data['date-only'])
                    $d->_dateonly = $data['date-only'];
                return $d;
            }
        }
        else if (is_object($data) && is_a($data, 'DateTime'))
            return $data;

        return null;
    }

    /**
     * Parse the X-Kolab-Type header from MIME messages and return the object type in short form
     *
     * @param string X-Kolab-Type header value
     * @return string Kolab object type (contact,event,task,note,etc.)
     */
    public static function mime2object_type($x_kolab_type)
    {
        return preg_replace('/dictionary.[a-z.]+$/', 'dictionary', substr($x_kolab_type, strlen(self::KTYPE_PREFIX)));
    }

    /**
     * Convert alarm time into internal ical-based format
     *
     * @param int  Alarm value as saved in Kolab 2 format
     * @return string iCal-style alarm value for internal use
     */
    public static function from_kolab2_alarm($alarm_value)
    {
        if (!$alarm_value)
            return null;

        $alarm_unit = 'M';
        if ($alarm_value % 1440 == 0) {
            $alarm_value /= 1440;
            $alarm_unit = 'D';
        }
        else if ($alarm_value % 60 == 0) {
            $alarm_value /= 60;
            $alarm_unit = 'H';
        }
        $alarm_value *= -1;

        return $alarm_value . $alarm_unit;
    }

    /**
     * Utility function to convert from Roundcube's internal alarms format
     * to an alarm offset in minutes used by the Kolab 2 format.
     *
     * @param string iCal-style alarm string
     * @return int Alarm offset in minutes
     */
    public static function to_kolab2_alarm($alarms)
    {
        $ret = null;

        if (!$alarms)
            return $ret;

        $alarmbase = explode(":", $alarms);
        $avalue = intval(preg_replace('/[^0-9]/', '', $alarmbase[0]));

        if (preg_match("/^@/", $alarmbase[0])) {
            $ret = null;
        }
        else if (preg_match("/H/",$alarmbase[0])) {
            $ret = $avalue*60;
        }
        else if (preg_match("/D/",$alarmbase[0])) {
            $ret = $avalue*24*60;
        }
        else {
            $ret = $avalue;
        }

        return $ret;
    }


    /**
     * Default constructor of all kolab_format_* objects
     */
    public function __construct($xmldata = null, $subtype = null)
    {
        $this->subtype = $subtype;

        try {
            $factory = new Horde_Kolab_Format_Factory();
            $handler = $factory->create('Xml', $this->xmltype, array('subtype' => $this->subtype));
            if (is_object($handler) && !is_a($handler, 'PEAR_Error')) {
                $this->handler = $handler;
                $this->xmldata = $xmldata;
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true);
        }
    }

    /**
     * Check for format errors after calling kolabformat::write*()
     *
     * @return boolean True if there were errors, False if OK
     */
    protected function format_errors($p)
    {
        $ret = false;

        if (is_object($p) && is_a($p, 'PEAR_Error')) {
            rcube::raise_error(array(
                'code' => 660,
                'type' => 'php',
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => "Horde_Kolab_Format error: " . $p->getMessage(),
            ), true);

            $ret = true;
        }

        return $ret;
    }

    /**
     * Generate a unique identifier for a Kolab object
     */
    protected function generate_uid()
    {
        $rc = rcube::get_instance();
        return strtoupper(md5(time() . uniqid(rand())) . '-' . substr(md5($rc->user ? $rc->user->get_username() : rand()), 0, 16));
    }

    /**
     * Initialize libkolabxml object with cached xml data
     */
    protected function init()
    {
        if (!$this->loaded) {
            if ($this->xmldata) {
                $this->load($this->xmldata);
                $this->xmldata = null;
            }
            $this->loaded = true;
        }
    }

    /**
     * Direct getter for object properties
     */
    public function __get($var)
    {
        return $this->data[$var];
    }

    /**
     * Load Kolab object data from the given XML block
     *
     * @param string XML data
     * @return boolean True on success, False on failure
     */
    public function load($xml)
    {
        $this->loaded = false;

        // XML-to-array
        try {
            $object = $this->handler->load($xml, array('relaxed' => true));
            $this->kolab_object = $object;
            $this->fromkolab2($object);
            $this->loaded = true;
        }
        catch (Exception $e) {
            rcube::raise_error($e, true);
            console($xml);
        }
    }

    /**
     * Write object data to XML format
     *
     * @param float Format version to write
     * @return string XML data
     */
    public function write($version = null)
    {
        $this->init();

        if ($version && !self::supports($version))
            return false;

        // generate UID if not set
        if (!$this->kolab_object['uid']) {
            $this->kolab_object['uid'] = $this->generate_uid();
        }

        try {
            $xml = $this->handler->save($this->kolab_object);
            if (strlen($xml)) {
                $this->xmldata = $xml;
                $this->data['uid'] = $this->kolab_object['uid'];
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true);
            $this->xmldata = null;
        }

        return $this->xmldata;
    }

    /**
     * Set properties to the kolabformat object
     *
     * @param array  Object data as hash array
     */
    abstract public function set(&$object);

    /**
     *
     */
    abstract public function is_valid();

    /**
     * Getter for the parsed object data
     *
     * @return array  Kolab object data as hash array
     */
    public function to_array()
    {
        // load from XML if not done yet
        if (!empty($this->data))
            $this->init();

        return $this->data;
    }

    /**
     * Load object data from Kolab2 format
     *
     * @param array Hash array with object properties (produced by Horde Kolab_Format classes)
     */
    abstract public function fromkolab2($object);

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        return array();
    }

    /**
     * Callback for kolab_storage_cache to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words()
    {
        return array();
    }
}
