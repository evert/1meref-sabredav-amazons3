<?php

/**
 * HTTP Request information
 *
 * This object can be used to easily access information about an HTTP request.
 * It can additionally be used to create 'mock' requests.
 *
 * This class mostly operates indepentend, but because of the nature of a single 
 * request per run it can operate as a singleton. For more information check out 
 * the behaviour around 'defaultInputStream'.
 *
 * @package Sabre
 * @subpackage HTTP 
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_HTTP_Request {

    /**
     * PHP's $_SERVER data
     * 
     * @var string 
     */
    protected $_SERVER;

    /**
     * The request body, if any.
     *
     * This is stored in the form of a stream resource.
     *
     * @var resource 
     */
    protected $body = null;

    /**
     * This will be set as the 'default' inputStream for a specific HTTP request
     * We sometimes need to retain, or rebuild this if we need multiple runs 
     * of parsing the original HTTP request.
     * 
     * @var resource 
     */
    static $defaultInputStream=null;

    /**
     * Sets up the object
     *
     * The serverData array can be used to override usage of PHP's 
     * global _SERVER variable. 
     * 
     * @param array $serverData 
     */
    public function __construct($serverData = null) {

       if ($serverData) $this->_SERVER = $serverData;
       else $this->_SERVER =& $_SERVER;

    }

    /**
     * Returns the value for a specific http header.
     *
     * This method returns null if the header did not exist.
     * 
     * @param string $name 
     * @return string 
     */
    public function getHeader($name) {

        $serverName = 'HTTP_' . strtoupper(str_replace(array('-'),array('_'),$name));
        return isset($this->_SERVER[$serverName])?$this->_SERVER[$serverName]:null;

    }

    /**
     * Returns all (known) HTTP headers.
     *
     * All headers are converted to lower-case, and additionally all underscores
     * are automatically converted to dashes 
     * 
     * @return array 
     */
    public function getHeaders() {

        $hdrs = array();
        foreach($this->_SERVER as $key=>$value) {

            if (strpos($key,'HTTP_')===0) {
                $hdrs[substr(strtolower(str_replace('_','-',$key)),5)] = $value;
            }

        }

        return $hdrs;

    }

    /**
     * Returns the HTTP request method
     *
     * This is for example POST or GET 
     *
     * @return string 
     */
    public function getMethod() {

        return $this->_SERVER['REQUEST_METHOD'];

    }

    /**
     * Returns the requested uri
     *
     * @return string 
     */
    public function getUri() {
       
        return $this->_SERVER['REQUEST_URI'];

    }

    /**
     * Will return protocol + the hostname + the uri 
     * 
     * @return void
     */
    public function getAbsoluteUri() {

        // Checking if the request was made through HTTPS. The last in line is for IIS
        $protocol = isset($this->_SERVER['HTTPS']) && ($this->_SERVER['HTTPS']) && ($this->_SERVER['HTTPS']!='off');
        return ($protocol?'https':'http') . '://'  . $this->getHeader('Host') . $this->getUri();

    }

    /**
     * Returns everything after the ? from the current url 
     * 
     * @return string 
     */
    public function getQueryString() {

        return isset($this->_SERVER['QUERY_STRING'])?$this->_SERVER['QUERY_STRING']:'';

    }

    /**
     * Returns the HTTP request body body 
     *
     * This method returns a readable stream resource.
     * If the asString parameter is set to true, a string is sent instead. 
     *
     * @param bool asString
     * @return resource 
     */
    public function getBody($asString = false) {

        if (is_null($this->body)) {
            if (!is_null(self::$defaultInputStream)) {
                $this->body = self::$defaultInputStream;
            } else {
                $this->body = fopen('php://input','r');
                self::$defaultInputStream = $this->body;
            }
        }
        if ($asString) {
            $body = stream_get_contents($this->body);
            return $body;
        } else {
            return $this->body;
        }

    }

    /**
     * Sets the contents of the HTTP requet body 
     * 
     * This method can either accept a string, or a readable stream resource.
     *
     * If the setAsDefaultInputStream is set to true, it means for this run of the 
     * script the supplied body will be used instead of php://input.
     *
     * @param mixed $body 
     * @param bool $setAsDefaultInputStream
     * @return void
     */
    public function setBody($body,$setAsDefaultInputStream = false) {

        if(is_resource($body)) {
            $this->body = $body;
        } else {

            $stream = fopen('php://temp','r+');
            fputs($stream,$body);
            rewind($stream);
            // String is assumed
            $this->body = $stream;
        }
        if ($setAsDefaultInputStream) {
            self::$defaultInputStream = $this->body;
        }

    }

    /**
     * Returns a specific item from the _SERVER array. 
     *
     * Do not rely on this feature, it is for internal use only.
     *
     * @param string $field 
     * @return string 
     */
    public function getRawServerValue($field) {

        return isset($this->_SERVER[$field])?$this->_SERVER[$field]:null;

    }

}

