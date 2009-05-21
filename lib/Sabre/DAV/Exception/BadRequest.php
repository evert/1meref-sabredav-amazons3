<?php

/**
 * BadRequest 
 * 
 * @package Sabre
 * @subpackage DAV
 * @version $Id: Exception.php 348 2009-03-26 00:24:28Z evertpot $
 * @copyright Copyright (C) 2007-2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */


/**
 * BadRequest
 *
 * The BadRequest is thrown when the user submitted an invalid HTTP request
 */
class Sabre_DAV_Exception_BadRequest extends Sabre_DAV_Exception {

    function getHTTPCode() {

        return 400; 

    }

}
