<?php

require_once 'Sabre/DAV/INode.php';

/**
 * The IDirectory Interface
 *
 * This interface should be implemented by each class that represents a directory (or branch in the tree)
 * 
 * @package Sabre
 * @subpackage DAV
 * @version $Id$
 * @copyright Copyright (C) 2007-2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_IDirectory extends Sabre_DAV_INode {

    /**
     * Creates a new file in the directory 
     * 
     * @param string $name Name of the file 
     * @param string $data Initial payload 
     * @return void
     */
    function createFile($name, $data = null);

    /**
     * Creates a new subdirectory 
     * 
     * @param string $name 
     * @return void
     */
    function createDirectory($name);

    /**
     * Returns a specific child node, referenced by its name 
     * 
     * @param string $name 
     * @return Sabre_DAV_INode 
     */
    function getChild($name);

    /**
     * Returns an array with all the child nodes 
     * 
     * @return Sabre_DAV_INode[] 
     */
    function getChildren();

}

