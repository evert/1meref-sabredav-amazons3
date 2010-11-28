<?php

/**
 * Base class for all elements
 * 
 * @package Sabre
 * @subpackage VObject
 * @copyright Copyright (C) 2007-2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_VObject_Element extends Sabre_VObject_Node implements IteratorAggregate, ArrayAccess, Countable {

    /**
     * Iterator override 
     * 
     * @var Sabre_VObject_ElementList 
     */
    protected $iterator = null;

    /* {{{ IteratorAggregator interface */

    /**
     * Returns the iterator for this object 
     * 
     * @return Sabre_VObject_ElementList 
     */
    public function getIterator() {

        if (!is_null($this->iterator)) 
            return $this->iterator;

        return new Sabre_VObject_ElementList(array($this));

    }

    /**
     * Sets the overridden iterator
     *
     * Note that this is not actually part of the iterator interface
     * 
     * @param Sabre_VObject_ElementList $iterator 
     * @return void
     */
    public function setIterator(Sabre_VObject_ElementList $iterator) {

        $this->iterator = $iterator;

    }

    /* }}} */

    /* {{{ Countable interface */

    /**
     * Returns the number of elements 
     * 
     * @return int 
     */
    public function count() {

        $it = $this->getIterator();
        return $it->count();

    }

    /* }}} */

    /* {{{ ArrayAccess Interface */

    
    /**
     * Checks if an item exists through ArrayAccess.
     *
     * This method just forwards the request to the inner iterator
     * 
     * @param int $offset 
     * @return bool 
     */
    public function offsetExists($offset) {

        $iterator = $this->getIterator();
        return $iterator->offsetExists($offset);

    }

    /**
     * Gets an item through ArrayAccess.
     *
     * This method just forwards the request to the inner iterator
     *
     * @param int $offset 
     * @return mixed 
     */
    public function offsetGet($offset) {

        $iterator = $this->getIterator();
        return $iterator->offsetGet($offset);

    }

    /**
     * Sets an item through ArrayAccess.
     *
     * This method just forwards the request to the inner iterator
     *
     * @param int $offset 
     * @param mixed $value 
     * @return void
     */
    public function offsetSet($offset,$value) {

        $iterator = $this->getIterator();
        return $iterator->offsetSet($offset,$value);

    }

    /**
     * Sets an item through ArrayAccess.
     *
     * This method just forwards the request to the inner iterator
     *
     * @param int $offset 
     * @return void
     */
    public function offsetUnset($offset) {

        $iterator = $this->getIterator();
        return $iterator->offsetUnset($offset);

    }

    /* }}} */

}
