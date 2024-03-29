<?php

/**
 * The CalendarObject represents a single VEVENT or VTODO within a Calendar. 
 * 
 * @package Sabre
 * @subpackage CalDAV
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_CalDAV_CalendarObject extends Sabre_DAV_File implements Sabre_DAV_IProperties, Sabre_DAVACL_IACL {

    /**
     * Sabre_CalDAV_Backend_Abstract 
     * 
     * @var array 
     */
    private $caldavBackend;

    /**
     * Array with information about this CalendarObject 
     * 
     * @var array 
     */
    private $objectData;

    /**
     * Array with information about the containing calendar
     * 
     * @var array 
     */
    private $calendarInfo;

    /**
     * Constructor 
     * 
     * @param Sabre_CalDAV_Backend_Abstract $caldavBackend 
     * @param array $objectData 
     */
    public function __construct(Sabre_CalDAV_Backend_Abstract $caldavBackend,$calendarInfo,$objectData) {

        $this->caldavBackend = $caldavBackend;
        $this->calendarInfo = $calendarInfo;
        $this->objectData = $objectData;

    }

    /**
     * Returns the uri for this object 
     * 
     * @return string 
     */
    public function getName() {

        return $this->objectData['uri'];

    }

    /**
     * Returns the ICalendar-formatted object 
     * 
     * @return string 
     */
    public function get() {

        return $this->objectData['calendardata'];

    }

    /**
     * Updates the ICalendar-formatted object 
     * 
     * @param string $calendarData 
     * @return void 
     */
    public function put($calendarData) {

        if (is_resource($calendarData))
            $calendarData = stream_get_contents($calendarData);

        $supportedComponents = $this->calendarInfo['{' . Sabre_CalDAV_Plugin::NS_CALDAV . '}supported-calendar-component-set']->getValue();
        Sabre_CalDAV_ICalendarUtil::validateICalendarObject($calendarData, $supportedComponents);

        $this->caldavBackend->updateCalendarObject($this->calendarInfo['id'],$this->objectData['uri'],$calendarData);
        $this->objectData['calendardata'] = $calendarData;

    }

    /**
     * Deletes the calendar object 
     * 
     * @return void
     */
    public function delete() {

        $this->caldavBackend->deleteCalendarObject($this->calendarInfo['id'],$this->objectData['uri']);

    }

    /**
     * Returns the mime content-type 
     * 
     * @return string 
     */
    public function getContentType() {

        return 'text/calendar';

    }

    /**
     * Returns an ETag for this object.
     *
     * The ETag is an arbritrary string, but MUST be surrounded by double-quotes.
     * 
     * @return string 
     */
    public function getETag() {

        return '"' . md5($this->objectData['calendardata']). '"';

    }

    /**
     * Returns the list of properties for this object
     * 
     * @param array $properties 
     * @return array 
     */
    public function getProperties($properties) {

        $response = array();
        if (in_array('{urn:ietf:params:xml:ns:caldav}calendar-data',$properties)) 
            $response['{urn:ietf:params:xml:ns:caldav}calendar-data'] = str_replace("\r","",$this->objectData['calendardata']);
       

        return $response;

    }

    /**
     * Updates properties
     * 
     * @param array $properties
     * @return array 
     */
    public function updateProperties($properties) {

        return false;

    }

    /**
     * Returns the last modification date as a unix timestamp
     * 
     * @return time 
     */
    public function getLastModified() {

        return strtotime($this->objectData['lastmodified']);

    }

    /**
     * Returns the size of this object in bytes 
     * 
     * @return int
     */
    public function getSize() {

        return strlen($this->objectData['calendardata']);

    }

    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner 
     * 
     * @return string|null
     */
    public function getOwner() {

        return $this->calendarInfo['principaluri'];

    }

    /**
     * Returns a group principal
     *
     * This must be a url to a principal, or null if there's no owner
     * 
     * @return string|null 
     */
    public function getGroup() {

        return null;

    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are 
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to 
     *      be updated. 
     * 
     * @return array 
     */
    public function getACL() {

        return array(
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->calendarInfo['principaluri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-read',
                'protected' => true,
            ),

        );

    }

    /**
     * Updates the ACL
     *
     * This method will receive a list of new ACE's. 
     * 
     * @param array $acl 
     * @return void
     */
    public function setACL(array $acl) {

        throw new Sabre_DAV_Exception_MethodNotAllowed('Changing ACL is not yet supported');

    }


}

