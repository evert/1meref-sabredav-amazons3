<?php

/**
 * Href property
 *
 * The href property represpents a url within a {DAV:}href element.
 * This is used by many WebDAV extensions, but not really within the WebDAV core spec
 * 
 * @package Sabre
 * @subpackage DAV
 * @copyright Copyright (C) 2007-2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_Property_Href extends Sabre_DAV_Property  {

    /**
     * href 
     * 
     * @var string 
     */
    private $href;

    /**
     * Automatically prefix the url with the server base directory 
     * 
     * @var bool 
     */
    private $autoPrefix = true;

    /**
     * __construct 
     * 
     * @param string $href 
     * @return void
     */
    public function __construct($href, $autoPrefix = true) {

        $this->href = $href;
        $this->autoPrefix = $autoPrefix;

    }

    /**
     * Returns the uri 
     * 
     * @return string 
     */
    public function getHref() {

        return $this->href;

    }

    /**
     * Serializes this property.
     *
     * It will additionally prepend the href property with the server's base uri.
     * 
     * @param Sabre_DAV_Server $server 
     * @param DOMElement $dom 
     * @return void
     */
    public function serialize(Sabre_DAV_Server $server,DOMElement $dom) {

        $elem = $dom->ownerDocument->createElementNS('DAV:','d:href');
        $elem->nodeValue = ($this->autoPrefix?$server->getBaseUri():'') . $this->href;
        $dom->appendChild($elem);

    }

}
