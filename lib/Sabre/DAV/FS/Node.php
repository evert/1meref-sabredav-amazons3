<?php

/**
 * Base node-class 
 *
 * The node class implements the method used by both the File and the Directory classes 
 * 
 * @package Sabre
 * @subpackage DAV
 * @copyright Copyright (C) 2007-2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_DAV_FS_Node implements Sabre_DAV_INode {

    /**
     * The path to the current node
     * 
     * @var string 
     */
    protected $path; 

    /**
     * nameOverride can contain an alternative name for this node
     * if it is specified, methods that can alter it's location are disabled.
     * 
     * @var null|string 
     */
    protected $nameOverride = null;

    /**
     * Sets up the node, expects a full path name 
     * 
     * If nameOverride is specified that name will be used instead. If it's
     * specified, any methods that alter this node's name will result in a 
     * exception. (setName, delete).
     * 
     *
     * @param string $path 
     * @return void
     */
    public function __construct($path, $nameOverride = null) {

        $this->path = $path;
        $this->nameOverride = $nameOverride;

    }



    /**
     * Returns the name of the node 
     * 
     * @return string 
     */
    public function getName() {

        if (is_string($this->nameOverride)) {
            return $this->nameOverride;
        }
        list(, $name)  = Sabre_DAV_URLUtil::splitPath($this->path);
        return $name;

    }

    /**
     * Renames the node
     *
     * @param string $name The new name
     * @return void
     */
    public function setName($name) {

        // If nameOverride is on, renaming can result in
        // unexpected behaviour. Therefore we disable it to be safe.
        if (!is_null($this->nameOverride)) {
            throw new Sabre_DAV_Exception_Forbidden('Renaming this node is not allowed');
        }
        list($parentPath, ) = Sabre_DAV_URLUtil::splitPath($this->path);
        list(, $newName) = Sabre_DAV_URLUtil::splitPath($name);

        $newPath = $parentPath . '/' . $newName;
        rename($this->path,$newPath);
        
        $this->path = $newPath;

    }


    /**
     * Returns the last modification time, as a unix timestamp 
     * 
     * @return int 
     */
    public function getLastModified() {

        return filemtime($this->path);

    }

}

