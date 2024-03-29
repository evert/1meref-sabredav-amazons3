<?php

/**
 * Principals Collection
 *
 * This is a helper class that easily allows you to create a collection that 
 * has a childnode for every principal.
 * 
 * To use this class, simply implement the getChildForPrincipal method. 
 *
 * @package Sabre
 * @subpackage DAVACL
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_DAVACL_AbstractPrincipalCollection extends Sabre_DAV_Directory  {

    /**
     * Node or 'directory' name. 
     * 
     * @var string 
     */
    protected $path;

    /**
     * Principal backend 
     * 
     * @var Sabre_DAVACL_IPrincipalBackend 
     */
    protected $principalBackend;

    /**
     * Creates the object
     *
     * This object must be passed the principal backend. This object will 
     * filter all principals from a specfied prefix ($principalPrefix). The 
     * default is 'principals', if your principals are stored in a different 
     * collection, override $principalPrefix
     * 
     * 
     * @param Sabre_DAVACL_IPrincipalBackend $principalBackend 
     * @param string $principalPrefix
     * @param string $nodeName
     */
    public function __construct(Sabre_DAVACL_IPrincipalBackend $principalBackend, $principalPrefix = 'principals') {

        $this->principalPrefix = $principalPrefix;
        $this->principalBackend = $principalBackend;

    }

    /**
     * This method returns a node for a principal.
     *
     * The passed array contains principal information, and is guaranteed to
     * at least contain a uri item. Other properties may or may not be
     * supplied by the authentication backend.
     * 
     * @param array $principalInfo 
     * @return Sabre_DAVACL_IPrincipal
     */
    abstract function getChildForPrincipal(array $principalInfo);

    /**
     * Returns the name of this collection. 
     * 
     * @return string 
     */
    public function getName() {

        list(,$name) = Sabre_DAV_URLUtil::splitPath($this->principalPrefix);
        return $name; 

    }

    /**
     * Return the list of users 
     * 
     * @return void
     */
    public function getChildren() {

        $children = array();
        foreach($this->principalBackend->getPrincipalsByPrefix($this->principalPrefix) as $principalInfo) {

            $children[] = $this->getChildForPrincipal($principalInfo);


        }
        return $children; 

    }

    /**
     * Returns a child object, by its name.
     * 
     * @param string $name
     * @throws Sabre_DAV_Exception_FileNotFound
     * @return Sabre_DAV_IPrincipal
     */
    public function getChild($name) {

        $principalInfo = $this->principalBackend->getPrincipalByPath($this->principalPrefix . '/' . $name);
        if (!$principalInfo) throw new Sabre_DAV_Exception_FileNotFound('Principal with name ' . $name . ' not found');
        return $this->getChildForPrincipal($principalInfo);

    }

}
