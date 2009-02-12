<?php

/**
 * HTTP Basic Authentication handler
 *
 * Use this class for easy http authentication setup
 * 
 * @package Sabre
 * @subpackage HTTP 
 * @version $Id$
 * @copyright Copyright (C) 2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_HTTP_BasicAuth extends Sabre_HTTP_AbstractAuth {

    /**
     * Returns the supplied username and password.
     *
     * The returned array has two values:
     *   * 0 - username
     *   * 1 - password
     *
     * If nothing was supplied, 'false' will be returned
     *
     * @return mixed 
     */
    public function getUserPass() {

        // Apache and mod_php
        if (($user = $this->httpRequest->getRawServerValue('PHP_AUTH_USER')) && ($pass = $this->httpRequest->getRawServerValue('PHP_AUTH_PW'))) {

            return array($user,$pass);

        }

        // Most other webservers 
        if ($auth = $this->httpRequest->getHeader('Authorization')) {

            if (strpos(strtolower($auth),'basic')===0) {
                return explode(':', base64_decode(substr($auth, 6)));
            }

        }

        return false;

    }

    /**
     * Returns an HTTP 401 header, forcing login
     *
     * This should be called when username and password are incorrect, or not supplied at all
     *
     * @return void
     */
    public function requireLogin() {

        $this->httpResponse->setHeader('WWW-Authenticate','Basic realm="' . $this->realm . '"');
        $this->httpResponse->sendStatus(401);

    }

}
