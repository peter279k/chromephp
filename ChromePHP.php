<?php
/**
 * Copyright 2010-2015 Craig Campbell.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace ChromePHP;

use ReflectionClass;
use ReflectionProperty;

/**
 * Server Side Chrome PHP debugger class.
 *
 * @author Craig Campbell <iamcraigcampbell@gmail.com>
 */
class ChromePHP
{
    /**
     * @var string
     */
    const VERSION = '4.1.0';

    /**
     * @var string
     */
    const HEADER_NAME = 'X-ChromeLogger-Data';

    /**
     * @var string
     */
    const BACKTRACE_LEVEL = 'backtrace_level';

    /**
     * @var string
     */
    const LOG = 'log';

    /**
     * @var string
     */
    const WARN = 'warn';

    /**
     * @var string
     */
    const ERROR = 'error';

    /**
     * @var string
     */
    const GROUP = 'group';

    /**
     * @var string
     */
    const INFO = 'info';

    /**
     * @var string
     */
    const GROUP_END = 'groupEnd';

    /**
     * @var string
     */
    const GROUP_COLLAPSED = 'groupCollapsed';

    /**
     * @var string
     */
    const TABLE = 'table';

    /**
     * @var int
     */
    const HTTPD_HEADER_LIMIT = 8192; // 8Kb - Default for most HTTPD Servers

    /**
     * @var int
     */
    protected $_timestamp;

    /**
     * @var array
     */
    protected $_json = [
        'version' => self::VERSION,
        'columns' => ['log', 'backtrace', 'type'],
        'rows'    => [],
    ];

    /**
     * @var array
     */
    protected $_backtraces = [];

    /**
     * @var bool
     */
    protected $_error_triggered = false;

    /**
     * @var array
     */
    protected $_settings = [
        self::BACKTRACE_LEVEL => 1,
    ];

    /**
     * @var ChromePHP
     */
    protected static $_instance;

    /**
     * Prevent recursion when working with objects referring to each other.
     *
     * @var array
     */
    protected $_processed = [];

    /**
     * constructor.
     */
    private function __construct()
    {
        $this->_timestamp = $_SERVER['REQUEST_TIME'];
        $this->_json['request_uri'] = $_SERVER['REQUEST_URI'];
    }

    /**
     * gets instance of this class.
     *
     * @return ChromePHP
     */
    public static function getInstance()
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * logs a variable to the console.
     *
     * @param mixed $data,... unlimited OPTIONAL number of additional logs [...]
     */
    public static function log()
    {
        $args = func_get_args();

        return self::_log('', $args);
    }

    /**
     * logs a warning to the console.
     *
     * @param mixed $data,... unlimited OPTIONAL number of additional logs [...]
     */
    public static function warn()
    {
        $args = func_get_args();

        return self::_log(self::WARN, $args);
    }

    /**
     * logs an error to the console.
     *
     * @param mixed $data,... unlimited OPTIONAL number of additional logs [...]
     */
    public static function error()
    {
        $args = func_get_args();

        return self::_log(self::ERROR, $args);
    }

    /**
     * sends a group log.
     *
     * @param string value
     */
    public static function group()
    {
        $args = func_get_args();

        return self::_log(self::GROUP, $args);
    }

    /**
     * sends an info log.
     *
     * @param mixed $data,... unlimited OPTIONAL number of additional logs [...]
     */
    public static function info()
    {
        $args = func_get_args();

        return self::_log(self::INFO, $args);
    }

    /**
     * sends a collapsed group log.
     *
     * @param string value
     */
    public static function groupCollapsed()
    {
        $args = func_get_args();

        return self::_log(self::GROUP_COLLAPSED, $args);
    }

    /**
     * ends a group log.
     *
     * @param string value
     */
    public static function groupEnd()
    {
        $args = func_get_args();

        return self::_log(self::GROUP_END, $args);
    }

    /**
     * sends a table log.
     *
     * @param string value
     */
    public static function table()
    {
        $args = func_get_args();

        return self::_log(self::TABLE, $args);
    }

    /**
     * internal logging call.
     *
     * @param string $type
     *
     * @return ChromePHP
     */
    protected static function _log($type, array $args)
    {
        $logger = self::getInstance();

        // nothing passed in, don't do anything
        if (empty($args) && $type != self::GROUP_END) {
            return $logger;
        }

        $logger->_processed = [];
        $logs = array_map([$logger, '_convert'], $args);

        $backtrace = debug_backtrace(false);
        $level = $logger->getSetting(self::BACKTRACE_LEVEL);

        $backtrace_message = 'unknown';
        if (isset($backtrace[$level]['file'], $backtrace[$level]['line'])) {
            $backtrace_message = $backtrace[$level]['file'] . ' : ' . $backtrace[$level]['line'];
        }

        $logger->_addRow($logs, $backtrace_message, $type);

        return $logger;
    }

    /**
     * converts an object to a better format for logging.
     *
     * @param object
     *
     * @return array
     */
    protected function _convert($object)
    {
        // if this isn't an object then just return it
        if (!is_object($object)) {
            return $object;
        }

        //Mark this object as processed so we don't convert it twice and it
        //Also avoid recursion when objects refer to each other
        $this->_processed[] = $object;

        $object_as_array = [];

        // first add the class name
        $object_as_array['___class_name'] = get_class($object);

        // loop through object vars
        $object_vars = get_object_vars($object);
        foreach ($object_vars as $key => $value) {
            // same instance as parent object
            if ($value === $object || in_array($value, $this->_processed, true)) {
                $value = 'recursion - parent object [' . get_class($value) . ']';
            }
            $object_as_array[$key] = $this->_convert($value);
        }

        $reflection = new ReflectionClass($object);

        // loop through the properties and add those
        foreach ($reflection->getProperties() as $property) {
            // if one of these properties was already added above then ignore it
            if (array_key_exists($property->getName(), $object_vars)) {
                continue;
            }
            $type = $this->_getPropertyKey($property);
            $property->setAccessible(true);
            $value = $property->getValue($object);

            // same instance as parent object
            if ($value === $object || in_array($value, $this->_processed, true)) {
                $value = 'recursion - parent object [' . get_class($value) . ']';
            }

            $object_as_array[$type] = $this->_convert($value);
        }

        return $object_as_array;
    }

    /**
     * takes a reflection property and returns a nicely formatted key of the property name.
     *
     * @param ReflectionProperty $property
     *
     * @return string
     */
    protected function _getPropertyKey(ReflectionProperty $property)
    {
        $static = $property->isStatic() ? ' static' : '';
        if ($property->isPublic()) {
            return 'public' . $static . ' ' . $property->getName();
        }

        if ($property->isProtected()) {
            return 'protected' . $static . ' ' . $property->getName();
        }

        if ($property->isPrivate()) {
            return 'private' . $static . ' ' . $property->getName();
        }
    }

    /**
     * adds a value to the data array.
     *
     * @var mixed
     */
    protected function _addRow(array $logs, $backtrace, $type)
    {
        // if this is logged on the same line for example in a loop, set it to null to save space
        if (in_array($backtrace, $this->_backtraces)) {
            $backtrace = null;
        }

        // for group, groupEnd, and groupCollapsed
        // take out the backtrace since it is not useful
        if ($type == self::GROUP || $type == self::GROUP_END || $type == self::GROUP_COLLAPSED) {
            $backtrace = null;
        }

        if ($backtrace !== null) {
            $this->_backtraces[] = $backtrace;
        }

        $row = [$logs, $backtrace, $type];

        $this->_json['rows'][] = $row;
        $this->_writeHeader($this->_json);
    }

    protected function _writeHeader($data)
    {
        $header = self::HEADER_NAME . ': ' . $this->_encode($data);
        // Most HTTPD servers have a default header line length limit of 8kb, must test to avoid 500 Internal Server Error.
        if (strlen($header) > self::HTTPD_HEADER_LIMIT) {
            $data['rows'] = [];
            $data['rows'][] = [
                [
                    'ChromePHP Error: The HTML header will surpass the limit of ' .
                    $this->_formatSize(self::HTTPD_HEADER_LIMIT) . ' (' . $this->_formatSize(strlen($header)) .
                    ') - You can increase the HTTPD_HEADER_LIMIT on ChromePHP class, according to your Apache ' .
                    'LimitRequestFieldsize directive',
                ], '', self::ERROR,
            ];
            $header = self::HEADER_NAME . ': ' . $this->_encode($data);
        }
        header($header);
    }

    protected function _formatSize($arg)
    {
        if ($arg > 0) {
            $j = 0;
            $ext = ['bytes', 'Kb', 'Mb', 'Gb', 'Tb'];
            while ($arg >= pow(1024, $j)) {
                ++$j;
            }
            $arg = (round($arg / pow(1024, $j - 1) * 100) / 100) . ($ext[$j - 1]);

            return $arg;
        }

        return '0Kb';
    }

    /**
     * encodes the data to be sent along with the request.
     *
     * @param array $data
     *
     * @return string
     */
    protected function _encode($data)
    {
        return base64_encode(utf8_encode(json_encode($data)));
    }

    /**
     * adds a setting.
     *
     * @param string key
     * @param mixed value
     */
    public function addSetting($key, $value)
    {
        $this->_settings[$key] = $value;
    }

    /**
     * add ability to set multiple settings in one call.
     *
     * @param array $settings
     */
    public function addSettings(array $settings)
    {
        foreach ($settings as $key => $value) {
            $this->addSetting($key, $value);
        }
    }

    /**
     * gets a setting.
     *
     * @param string key
     *
     * @return mixed
     */
    public function getSetting($key)
    {
        if (!isset($this->_settings[$key])) {
            return null;
        }

        return $this->_settings[$key];
    }
}
