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
            return class_exists('Horde_Kolab_Format');

        return false;
    }

    /**
     * Convert the given date/time value into a cDateTime object
     *
     * @param mixed         Date/Time value either as unix timestamp, date string or PHP DateTime object
     * @param DateTimeZone  The timezone the date/time is in. Use global default if Null, local time if False
     * @param boolean       True of the given date has no time component
     * @return object       The libkolabxml date/time object
     */
    public static function get_datetime($datetime, $tz = null, $dateonly = false)
    {
        // use timezone information from datetime of global setting
        if (!$tz && $tz !== false) {
            if ($datetime instanceof DateTime)
                $tz = $datetime->getTimezone();
            if (!$tz)
                $tz = self::$timezone;
        }
        $result = new cDateTime();

        // got a unix timestamp (in UTC)
        if (is_numeric($datetime)) {
            $datetime = new DateTime('@'.$datetime, new DateTimeZone('UTC'));
            if ($tz) $datetime->setTimezone($tz);
        }
        else if (is_string($datetime) && strlen($datetime))
            $datetime = new DateTime($datetime, $tz ?: null);

        if ($datetime instanceof DateTime) {
            $result->setDate($datetime->format('Y'), $datetime->format('n'), $datetime->format('j'));

            if (!$dateonly)
                $result->setTime($datetime->format('G'), $datetime->format('i'), $datetime->format('s'));

            if ($tz && $tz->getName() == 'UTC')
                $result->setUTC(true);
            else if ($tz !== false)
                $result->setTimezone($tz->getName());
        }

        return $result;
    }

    /**
     * Convert the given cDateTime into a PHP DateTime object
     *
     * @param object cDateTime  The libkolabxml datetime object
     * @return object DateTime  PHP datetime instance
     */
    public static function php_datetime($cdt)
    {
        if (!is_object($cdt) || !$cdt->isValid())
            return null;

        $d = new DateTime;
        $d->setTimezone(self::$timezone);

        try {
            if ($tzs = $cdt->timezone()) {
                $tz = new DateTimeZone($tzs);
                $d->setTimezone($tz);
            }
            else if ($cdt->isUTC()) {
                $d->setTimezone(new DateTimeZone('UTC'));
            }
        }
        catch (Exception $e) { }

        $d->setDate($cdt->year(), $cdt->month(), $cdt->day());

        if ($cdt->isDateOnly()) {
            $d->_dateonly = true;
            $d->setTime(12, 0, 0);  // set time to noon to avoid timezone troubles
        }
        else {
            $d->setTime($cdt->hour(), $cdt->minute(), $cdt->second());
        }

        return $d;
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
     * Default constructor of all kolab_format_* objects
     */
    public function __construct($xmldata = null, $subtype = null)
    {
        $this->subtype = $subtype;

        $handler = Horde_Kolab_Format::factory('XML', $this->xmltype, array('subtype' => $this->subtype));
        if (!is_object($handler) || is_a($handler, 'PEAR_Error')) {
            return;
        }

        $this->handler = $handler;
        $this->xmldata = $xmldata;
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
        $object = $this->handler->load($xml);
        if (!$this->format_errors($object)) {
            $this->kolab_object = $object;
            $this->fromkolab2($object);
            $this->loaded = true;
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

        $xml = $this->handler->save($this->kolab_object);

        if (!$this->format_errors($xml) && strlen($xml)) {
            $this->xmldata = $xml;
            $this->data['uid'] = $this->kolab_object['uid'];
        }
        else {
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
