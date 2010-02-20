<?php

/**
 * Sabre_CalDAV_Property_SupportedCollationSet
 *
 * @package Sabre
 * @subpackage CalDAV
 * @version $Id$
 * @copyright Copyright (C) 2007-2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */

/**
 * supported-collation-set property
 *
 * This property is a representation of the supported-collation-set property
 * in the CalDAV namespace. 
 */
class Sabre_CalDAV_Property_SupportedCollationSet extends Sabre_DAV_Property {

    function serialize(Sabre_DAV_Server $server,DOMElement $node) {

        $doc = $node->ownerDocument;
        
        $prefix = $node->lookupPrefix('urn:ietf:params:xml:ns:caldav');
        if (!$prefix) $prefix = 'cal';

        $node->appendChild(
            $doc->createElement($prefix . ':supported-collation','i;ascii-casemap')
        );
        $node->appendChild(
            $doc->createElement($prefix . ':supported-collation','i;octet')
        );


    }

}
