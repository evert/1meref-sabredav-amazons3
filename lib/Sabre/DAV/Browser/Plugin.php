<?php

/**
 * Browser Plugin
 *
 * This plugin provides a html representation, so that a WebDAV server may be accessed
 * using a browser.
 *
 * The class intercepts GET requests to collection resources and generates a simple 
 * html index. It's not really pretty though, extend to skin this listing.
 * 
 * @package Sabre
 * @subpackage DAV
 * @version $Id$
 * @copyright Copyright (C) 2007-2009 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_Browser_Plugin extends Sabre_DAV_ServerPlugin {

    /**
     * reference to server class 
     * 
     * @var Sabre_DAV_Server 
     */
    protected $server;

    /**
     * Initializes the plugin and subscribes to events 
     * 
     * @param Sabre_DAV_Server $server 
     * @return void
     */
    public function initialize(Sabre_DAV_Server $server) {

        $this->server = $server;
        $this->server->subscribeEvent('beforeMethod',array($this,'httpGetInterceptor'));

    }

    /**
     * This method intercepts GET requests to collections and returns the html 
     * 
     * @param string $method 
     * @return bool 
     */
    public function httpGetInterceptor($method) {

        if ($method!='GET') return true;
        
        $nodeInfo = $this->server->getPropertiesForPath($this->server->getRequestUri(),array('{DAV:}resourcetype'));
        if ($nodeInfo[0]['{DAV:}resourcetype']->getValue()!='{DAV:}collection') return true;

        $this->server->httpResponse->sendStatus(200);
        $this->server->httpResponse->setHeader('Content-Type','text/html; charset=utf-8');

        $this->server->httpResponse->sendBody(
            $this->generateDirectoryIndex($this->server->getRequestUri())
        );

        return false;
        
    }

    /**
     * Handles POST requests for tree operations
     * 
     * This method is not yet used.
     * 
     * @param string $method 
     * @return bool
     */
    public function httpPOSTHandler($method) {

        if ($method!='POST') return true;


    }

    /**
     * Escapes a string for html. 
     * 
     * @param string $value 
     * @return void
     */
    public function escapeHTML($value) {

        return htmlspecialchars($value,ENT_QUOTES,'UTF-8');

    }

    /**
     * Generates the html directory index for a given url 
     *
     * @param string $path 
     * @return string 
     */
    public function generateDirectoryIndex($path) {

        ob_start();
        echo "<html>
<head>
  <title>Index for " . $this->escapeHTML($path) . "/ - SabreDAV " . Sabre_DAV_Version::VERSION . "</title>
</head>
<body>
  <h1>Index for " . $this->escapeHTML($path) . "/</h1>
  <table>
    <tr><th>Name</th><th>Type</th><th>Size</th><th>Last modified</th></tr>
    <tr><td colspan=\"4\"><hr /></td></tr>";
    
    $files = $this->server->getPropertiesForPath($path,array(
        '{DAV:}resourcetype',
        '{DAV:}getcontenttype',
        '{DAV:}getcontentlength',
        '{DAV:}getlastmodified',
    ),1);

    foreach($files as $k=>$file) {

        $name = $this->escapeHTML($file['href']);

        if ($name=="") continue;

        if (isset($file['{DAV:}resourcetype'])) {
            $type = $file['{DAV:}resourcetype']->getValue();
            if ($type=='{DAV:}collection') {
                $type = 'Directory';
            } elseif ($type=='') {
                if (isset($file['{DAV:}getcontenttype'])) {
                    $type = $file['{DAV:}getcontenttype'];
                } else {
                    $type = 'Unknown';
                }
            }
        }
        $type = $this->escapeHTML($type);
        $size = isset($file['{DAV:}getcontentlength'])?(int)$file['{DAV:}getcontentlength']:'';
        $lastmodified = isset($file['{DAV:}getlastmodified'])?date(DATE_ATOM,$file['{DAV:}getlastmodified']->getTime()):'';

        $fullPath = '/' . trim($this->server->getBaseUri() . $this->escapeHTML($path) . '/' . $name,'/');

        echo "<tr>
<td><a href=\"{$fullPath}\">{$name}</a></td>
<td>{$type}</td>
<td>{$size}</td>
<td>{$lastmodified}</td>
</tr>";

    }

  echo "<tr><td colspan=\"4\"><hr /></td></tr>
  </table>
  <address>Generated by SabreDAV " . Sabre_DAV_Version::VERSION ."-". Sabre_DAV_Version::STABILITY . " (c)2007-2009 <a href=\"http://code.google.com/p/sabredav/\">http://code.google.com/p/sabredav/</a></address>
</body>
</html>";

        return ob_get_clean();

    }

}
