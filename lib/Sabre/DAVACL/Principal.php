<?php

/**
 * Principal class
 *
 * This class is a representation of a simple principal
 * 
 * Many WebDAV specs require a user to show up in the directory 
 * structure. 
 *
 * This principal also has basic ACL settings, only allowing the principal
 * access it's own principal. 
 * 
 * @package Sabre
 * @subpackage DAVACL
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAVACL_Principal extends Sabre_DAV_Node implements Sabre_DAVACL_IPrincipal, Sabre_DAV_IProperties, Sabre_DAVACL_IACL {

    /**
     * Struct with principal information.
     *
     * @var array 
     */
    protected $principalProperties;

    /**
     * Principal backend 
     * 
     * @var Sabre_DAVACL_IPrincipalBackend 
     */
    protected $principalBackend;

    /**
     * Creates the principal object 
     *
     * @param array $principalProperties
     */
    public function __construct(Sabre_DAVACL_IPrincipalBackend $principalBackend, array $principalProperties = array()) {

        if (!isset($principalProperties['uri'])) {
            throw new Sabre_DAV_Exception('The principal properties must at least contain the \'uri\' key');
        }
        $this->principalBackend = $principalBackend;
        $this->principalProperties = $principalProperties;

    }

    /**
     * Returns the full principal url 
     * 
     * @return string 
     */
    public function getPrincipalUrl() {

        return $this->principalProperties['uri'];

    } 

    /**
     * Returns a list of altenative urls for a principal
     * 
     * This can for example be an email address, or ldap url.
     * 
     * @return array 
     */
    public function getAlternateUriSet() {

        if (isset($this->principalProperties['{http://sabredav.org/ns}email-address'])) {
            return array('mailto:' . $this->principalProperties['{http://sabredav.org/ns}email-address']);
        } else {
            return array();
        }

    }

    /**
     * Returns the list of group members
     * 
     * If this principal is a group, this function should return
     * all member principal uri's for the group. 
     * 
     * @return array
     */
    public function getGroupMemberSet() {

        return $this->principalBackend->getGroupMemberSet($this->principalProperties['uri']);

    }

    /**
     * Returns the list of groups this principal is member of
     * 
     * If this principal is a member of a (list of) groups, this function
     * should return a list of principal uri's for it's members. 
     * 
     * @return array 
     */
    public function getGroupMembership() {

        return $this->principalBackend->getGroupMemberShip($this->principalProperties['uri']);

    }


    /**
     * Sets a list of group members
     *
     * If this principal is a group, this method sets all the group members.
     * The list of members is always overwritten, never appended to.
     * 
     * This method should throw an exception if the members could not be set. 
     * 
     * @param array $principals 
     * @return void 
     */
    public function setGroupMemberSet(array $groupMembers) {

        throw new Sabre_DAV_Exception_Forbidden('This principal does not allow setting group members');

    }


    /**
     * Returns this principals name.
     * 
     * @return string 
     */
    public function getName() {

        $uri = $this->principalProperties['uri'];
        list(, $name) = Sabre_DAV_URLUtil::splitPath($uri);

        return $name;

    }

    /**
     * Returns the name of the user 
     * 
     * @return void
     */
    public function getDisplayName() {

        if (isset($this->principalProperties['{DAV:}displayname'])) {
            return $this->principalProperties['{DAV:}displayname'];
        } else {
            return $this->getName();
        }

    }

    /**
     * Returns a list of properties 
     * 
     * @param array $requestedProperties 
     * @return void
     */
    public function getProperties($requestedProperties) {

        $newProperties = array();
        foreach($requestedProperties as $propName) {
            
            if (isset($this->principalProperties[$propName])) {
                $newProperties[$propName] = $this->principalProperties[$propName];
            }

        }

        return $newProperties;
        
    }

    /**
     * Updates this principals properties.
     *
     * Currently this is not supported
     * 
     * @param array $properties
     * @see Sabre_DAV_IProperties::updateProperties
     * @return bool|array 
     */
    public function updateProperties($properties) {

        return false;

    }

    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner 
     * 
     * @return string|null
     */
    public function getOwner() {

        return $this->principalProperties['uri'];


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
                'principal' => $this->principalProperties['uri'],
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

        throw new Sabre_DAV_Exception_MethodNotAllowed('Updating ACLs is not allowed here');

    }

}
