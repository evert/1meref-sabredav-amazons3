<?php

/**
 * Main DAV server class
 * 
 * @package Sabre
 * @subpackage DAV
 * @version $Id: Server.php 913 2010-02-24 03:14:17Z evertpot@gmail.com $
 * @copyright Copyright (C) 2007-2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_Server {

    /**
     * Inifinity is used for some request supporting the HTTP Depth header and indicates that the operation should traverse the entire tree
     */
    const DEPTH_INFINITY = -1;

    /**
     * Nodes that are files, should have this as the type property
     */
    const NODE_FILE = 1;

    /**
     * Nodes that are directories, should use this value as the type property
     */
    const NODE_DIRECTORY = 2;

    const PROP_SET = 1;
    const PROP_REMOVE = 2;


    /**
     * The tree object
     * 
     * @var Sabre_DAV_Tree 
     */
    public $tree;

    /**
     * The base uri 
     * 
     * @var string 
     */
    protected $baseUri = '/';

    /**
     * httpResponse 
     * 
     * @var Sabre_HTTP_Response 
     */
    public $httpResponse;

    /**
     * httpRequest
     * 
     * @var Sabre_HTTP_Request 
     */
    public $httpRequest;

    /**
     * The list of plugins 
     * 
     * @var array 
     */
    protected $plugins = array();

    /**
     * This array contains a list of callbacks we should call when certain events are triggered 
     * 
     * @var array
     */
    protected $eventSubscriptions = array();

    /**
     * This is a default list of namespaces.
     *
     * If you are defining your own custom namespace, add it here to reduce
     * bandwidth and improve legibility of xml bodies.
     * 
     * @var array
     */
    public $xmlNamespaces = array(
        'DAV:' => 'd',
        'http://www.rooftopsolutions.nl/NS/sabredav' => 's',
    );

    /**
     * The propertymap can be used to map properties from 
     * requests to property classes.
     * 
     * @var array
     */
    public $propertyMap = array(
    );

    /**
     * Class constructor 
     * 
     * @param Sabre_DAV_Tree $tree The tree object 
     * @return void
     */
    public function __construct(Sabre_DAV_Tree $tree) {

        $this->tree = $tree;
        $this->httpResponse = new Sabre_HTTP_Response();
        $this->httpRequest = new Sabre_HTTP_Request();

    }

    /**
     * Starts the DAV Server 
     *
     * @return void
     */
    public function exec() {

        try {

            $this->invoke();

        } catch (Exception $e) {

            $DOM = new DOMDocument('1.0','utf-8');
            $DOM->formatOutput = true;

            $error = $DOM->createElementNS('DAV:','d:error');
            $error->setAttribute('xmlns:s','http://www.rooftopsolutions.nl/NS/sabredav');
            $DOM->appendChild($error);

            $error->appendChild($DOM->createElement('s:exception',get_class($e)));
            $error->appendChild($DOM->createElement('s:message',$e->getMessage()));
            $error->appendChild($DOM->createElement('s:file',$e->getFile()));
            $error->appendChild($DOM->createElement('s:line',$e->getLine()));
            $error->appendChild($DOM->createElement('s:code',$e->getCode()));
            $error->appendChild($DOM->createElement('s:stacktrace',$e->getTraceAsString()));

            if($e instanceof Sabre_DAV_Exception) {
                $httpCode = $e->getHTTPCode();
                $e->serialize($this,$error);
            } else {
                $httpCode = 500;
            }
            
            $this->httpResponse->sendStatus($httpCode);
            $this->httpResponse->setHeader('Content-Type','application/xml; charset=utf-8');
            $this->httpResponse->sendBody($DOM->saveXML());

        }

    }

    /**
     * Sets the base server uri
     * 
     * @param string $uri
     * @return void
     */
    public function setBaseUri($uri) {

        $this->baseUri = $uri;    

    }

    /**
     * Returns the base responding uri
     * 
     * @return string 
     */
    public function getBaseUri() {

        return $this->baseUri;

    }

    /**
     * Adds a plugin to the server
     * 
     * For more information, console the documentation of Sabre_DAV_ServerPlugin
     *
     * @param Sabre_DAV_ServerPlugin $plugin 
     * @return void
     */
    public function addPlugin(Sabre_DAV_ServerPlugin $plugin) {

        $this->plugins[get_class($plugin)] = $plugin;
        $plugin->initialize($this);

    }

    /**
     * Returns an initialized plugin by it's classname. 
     *
     * This function returns null if the plugin was not found.
     *
     * @param string $className
     * @return Sabre_DAV_ServerPlugin 
     */
    public function getPlugin($className) {

        if (isset($this->plugins[$className])) return $this->plugins[$className];
        return null;

    }

    /**
     * Subscribe to an event.
     *
     * When the event is triggered, we'll call all the specified callbacks.
     * It is possible to control the order of the callbacks through the
     * priority argument.
     *
     * This is for example used to make sure that the authentication plugin
     * is triggered before anything else. If it's not needed to change this
     * number, it is recommended to ommit.  
     * 
     * @param string $event 
     * @param callback $callback
     * @param int $priority
     * @return void
     */
    public function subscribeEvent($event, $callback, $priority = 100) {

        if (!isset($this->eventSubscriptions[$event])) {
            $this->eventSubscriptions[$event] = array();
        }
        while(isset($this->eventSubscriptions[$event][$priority])) $priority++;
        $this->eventSubscriptions[$event][$priority] = $callback;
        ksort($this->eventSubscriptions[$event]);

    }

    /**
     * Broadcasts an event
     *
     * This method will call all subscribers. If one of the subscribers returns false, the process stops.
     *
     * The arguments parameter will be sent to all subscribers
     *
     * @param string $eventName
     * @param array $arguments
     * @return bool 
     */
    public function broadcastEvent($eventName,$arguments = array()) {
        
        if (isset($this->eventSubscriptions[$eventName])) {

            foreach($this->eventSubscriptions[$eventName] as $subscriber) {

                $result = call_user_func_array($subscriber,$arguments);
                if ($result===false) return false;

            }

        }

        return true;

    }

    // {{{ HTTP Method implementations
    
    /**
     * HTTP OPTIONS 
     * 
     * @return void
     */
    protected function httpOptions() {

        $methods = $this->getAllowedMethods();

        // We're also checking if any of the plugins register any new methods
        foreach($this->plugins as $plugin) $methods = array_merge($methods,$plugin->getHTTPMethods());
        array_unique($methods);

        $this->httpResponse->setHeader('Allow',strtoupper(implode(', ',$methods)));
        $features = array('1','3', 'extended-mkcol');

        foreach($this->plugins as $plugin) $features = array_merge($features,$plugin->getFeatures());
        
        $this->httpResponse->setHeader('DAV',implode(', ',$features));
        $this->httpResponse->setHeader('MS-Author-Via','DAV');
        $this->httpResponse->setHeader('Accept-Ranges','bytes');
        $this->httpResponse->setHeader('X-Sabre-Version',Sabre_DAV_Version::VERSION . '-' . Sabre_DAV_VERSION::STABILITY);
        $this->httpResponse->setHeader('Content-Length',0);
        $this->httpResponse->sendStatus(200);

    }

    /**
     * HTTP GET
     *
     * This method simply fetches the contents of a uri, like normal
     * 
     * @return void
     */
    protected function httpGet() {

        $uri = $this->getRequestUri();
        $node = $this->tree->getNodeForPath($uri,0);

        if (!($node instanceof Sabre_DAV_IFile)) throw new Sabre_DAV_Exception_NotImplemented('GET is only implemented on File objects');
        $body = $node->get();

        // Converting string into stream, if needed.
        if (is_string($body)) {
            $stream = fopen('php://temp','r+');
            fwrite($stream,$body);
            rewind($stream);
            $body = $stream;
        }

        /*
         * TODO: getetag, getlastmodified, getsize should also be used using
         * this method
         */
        $httpHeaders = $this->getHTTPHeaders($uri);

        /* ContentType needs to get a default, because many webservers will otherwise
         * default to text/html, and we don't want this
         */
        if (!isset($httpHeaders['Content-Type'])) {
            $httpHeaders['Content-Type'] = 'application/octet-stream';
        }


        if (isset($httpHeaders['Content-Length'])) {

            $nodeSize = $httpHeaders['Content-Length'];

            // Need to unset Content-Length, because we'll handle that during figuring out the range
            unset($httpHeaders['Content-Length']);

        } else {
            $nodeSize = null;
        }
        


        $this->httpResponse->setHeaders($httpHeaders);

        // We're only going to support HTTP ranges if the backend provided a filesize
        if ($nodeSize && $range = $this->getHTTPRange()) {

            // Determining the exact byte offsets
            if (!is_null($range[0])) {

                $start = $range[0];
                $end = $range[1]?$range[1]:$nodeSize-1;
                if($start > $nodeSize) 
                    throw new Sabre_DAV_Exception_RequestedRangeNotSatisfiable('The start offset (' . $range[0] . ') exceeded the size of the entity (' . $nodeSize . ')');

                if($end < $start) throw new Sabre_DAV_Exception_RequestedRangeNotSatisfiable('The end offset (' . $range[1] . ') is lower than the start offset (' . $range[0] . ')');
                if($end > $nodeSize) $end = $nodeSize-1;

            } else {

                $start = $nodeSize-$range[1];
                $end  = $nodeSize-1;

                if ($start<0) $start = 0;

            }

            // New read/write stream
            $newStream = fopen('php://temp','r+');

            stream_copy_to_stream($body, $newStream, $end-$start+1, $start);
            rewind($newStream);

            $this->httpResponse->setHeader('Content-Length', $end-$start+1);
            $this->httpResponse->setHeader('Content-Range','bytes ' . $start . '-' . $end . '/' . $nodeSize);
            $this->httpResponse->sendStatus(206);
            $this->httpResponse->sendBody($newStream);


        } else {

            if ($nodeSize) $this->httpResponse->setHeader('Content-Length',$nodeSize);
            $this->httpResponse->sendStatus(200);
            $this->httpResponse->sendBody($body);

        }

    }

    /**
     * HTTP HEAD
     *
     * This method is normally used to take a peak at a url, and only get the HTTP response headers, without the body
     * This is used by clients to determine if a remote file was changed, so they can use a local cached version, instead of downloading it again
     *
     * @return void
     */
    protected function httpHead() {

        $node = $this->tree->getNodeForPath($this->getRequestUri());

        /* This information is only collection for File objects.
         * Ideally we want to throw 405 Method Not Allowed for every 
         * non-file, but MS Office does not like this
         */
        if ($node instanceof Sabre_DAV_IFile) {
            $headers = $this->getHTTPHeaders($this->getRequestUri());
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/octet-stream';
            }
            $this->httpResponse->setHeaders($headers);
        }
        $this->httpResponse->sendStatus(200);

    }

    /**
     * HTTP Delete 
     *
     * The HTTP delete method, deletes a given uri
     *
     * @return void
     */
    protected function httpDelete() {

        $uri = $this->getRequestUri();
        $node = $this->tree->getNodeForPath($uri);
        if (!$this->broadcastEvent('beforeUnbind',array($uri))) return;
        $node->delete();

        $this->httpResponse->sendStatus(204);
        $this->httpResponse->setHeader('Content-Length','0');

    }


    /**
     * WebDAV PROPFIND 
     *
     * This WebDAV method requests information about an uri resource, or a list of resources
     * If a client wants to receive the properties for a single resource it will add an HTTP Depth: header with a 0 value
     * If the value is 1, it means that it also expects a list of sub-resources (e.g.: files in a directory)
     *
     * The request body contains an XML data structure that has a list of properties the client understands 
     * The response body is also an xml document, containing information about every uri resource and the requested properties
     *
     * It has to return a HTTP 207 Multi-status status code
     *
     * @return void
     */
    public function httpPropfind() {

        // $xml = new Sabre_DAV_XMLReader(file_get_contents('php://input'));
        $requestedProperties = $this->parsePropfindRequest($this->httpRequest->getBody(true));

        $depth = $this->getHTTPDepth(1);
        // The only two options for the depth of a propfind is 0 or 1 
        if ($depth!=0) $depth = 1;

        // The requested path
        $path = $this->getRequestUri();
        
        $newProperties = $this->getPropertiesForPath($path,$requestedProperties,$depth);

        // This is a multi-status response
        $this->httpResponse->sendStatus(207);
        $this->httpResponse->setHeader('Content-Type','application/xml; charset=utf-8');
        $data = $this->generateMultiStatus($newProperties);
        $this->httpResponse->sendBody($data);

    }

    /**
     * WebDAV PROPPATCH
     *
     * This method is called to update properties on a Node. The request is an XML body with all the mutations.
     * In this XML body it is specified which properties should be set/updated and/or deleted
     *
     * @return void
     */
    protected function httpPropPatch() {

        $mutations = $this->parsePropPatchRequest($this->httpRequest->getBody(true));

        $node = $this->tree->getNodeForPath($this->getRequestUri());
        
        if ($node instanceof Sabre_DAV_IProperties) {

            $result = $node->updateProperties($mutations);

        } else {

            $result = array();
            foreach($mutations as $mutations) {
                $result[] = array($mutations[1],403);
            }

        }

        $this->httpResponse->sendStatus(207);
        $this->httpResponse->setHeader('Content-Type','application/xml; charset=utf-8');
       
        // Re-arranging result for generateMultiStatus
        $multiStatusResult = array();

        foreach($result as $row) {
            if (!isset($multiStatusResult[$row[1]])) {
                $multiStatusResult[$row[1]] = array();
            }
            $multiStatusResult[$row[1]][$row[0]] = null;
        }
        $multiStatusResult['href'] = $this->getRequestUri();
        $multiStatusResult = array($multiStatusResult);
        $this->httpResponse->sendBody(
            $this->generateMultiStatus($multiStatusResult)
        );

    }

    /**
     * HTTP PUT method 
     * 
     * This HTTP method updates a file, or creates a new one.
     *
     * If a new resource was created, a 201 Created status code should be returned. If an existing resource is updated, it's a 200 Ok
     *
     * @return void
     */
    protected function httpPut() {

        // First we'll do a check to see if the resource already exists
        try {

            $node = $this->tree->getNodeForPath($this->getRequestUri());
            
            // We got this far, this means the node already exists.
            // This also means we should check for the If-None-Match header
            if ($this->httpRequest->getHeader('If-None-Match')) {

                throw new Sabre_DAV_Exception_PreconditionFailed('The resource already exists, and an If-None-Match header was supplied');

            }
            
            // If the node is a collection, we'll deny it
            if (!($node instanceof Sabre_DAV_IFile)) throw new Sabre_DAV_Exception_Conflict('PUT is not allowed on non-files.');
            if (!$this->broadcastEvent('beforeWriteContent',array($this->getRequestUri()))) return false;

            $node->put($this->httpRequest->getBody());
            $this->httpResponse->setHeader('Content-Length','0');
            $this->httpResponse->sendStatus(200);

        } catch (Sabre_DAV_Exception_FileNotFound $e) {

            // If we got here, the resource didn't exist yet.
            $this->createFile($this->getRequestUri(),$this->httpRequest->getBody());
            $this->httpResponse->setHeader('Content-Length','0');
            $this->httpResponse->sendStatus(201);

        }

    }


    /**
     * WebDAV MKCOL
     *
     * The MKCOL method is used to create a new collection (directory) on the server
     *
     * @return void
     */
    protected function httpMkcol() {

        $requestBody = $this->httpRequest->getBody(true);

        if ($requestBody) {

            $contentType = $this->httpRequest->getHeader('Content-Type');
            if (strpos($contentType,'application/xml')!==0 && strpos($contentType,'text/xml')!==0) {

                // We must throw 415 for unsupport mkcol bodies
                throw new Sabre_DAV_Exception_UnsupportedMediaType('The request body for the MKCOL request must have an xml Content-Type');

            }

            $dom = Sabre_DAV_XMLUtil::loadDOMDocument($requestBody);
            if (Sabre_DAV_XMLUtil::toClarkNotation($dom->firstChild)!=='{DAV:}mkcol') {

                // We must throw 415 for unsupport mkcol bodies
                throw new Sabre_DAV_Exception_UnsupportedMediaType('The request body for the MKCOL request must be a {DAV:}mkcol request construct.');

            }

            $properties = array();
            foreach($dom->firstChild->childNodes as $childNode) {

                if (Sabre_DAV_XMLUtil::toClarkNotation($childNode)!=='{DAV:}set') continue;
                $properties = array_merge($properties, Sabre_DAV_XMLUtil::parseProperties($childNode, $this->propertyMap));

            }
            if (!isset($properties['{DAV:}resourcetype'])) 
                throw new Sabre_DAV_Exception_BadRequest('The mkcol request must include a {DAV:}resourcetype property');

            unset($properties['{DAV:}resourcetype']);

            $resourceType = array();
            // Need to parse out all the resourcetypes
            $rtNode = $dom->firstChild->getElementsByTagNameNS('urn:DAV','resourcetype');
            $rtNode = $rtNode->item(0);
            foreach($rtNode->childNodes as $childNode) {;
                $resourceType[] = Sabre_DAV_XMLUtil::toClarkNotation($childNode);
            }

        } else {

            $properties = array();
            $resourceType = array('{DAV:}collection');

        }

        $this->createCollection($this->getRequestUri(), $resourceType, $properties);
        $this->httpResponse->setHeader('Content-Length','0');
        $this->httpResponse->sendStatus(201);

    }

    /**
     * WebDAV HTTP MOVE method
     *
     * This method moves one uri to a different uri. A lot of the actual request processing is done in getCopyMoveInfo
     * 
     * @return void
     */
    protected function httpMove() {

        $moveInfo = $this->getCopyAndMoveInfo();
        if ($moveInfo['destinationExists']) {

            if (!$this->broadcastEvent('beforeUnbind',array($moveInfo['destination']))) return false;
            $moveInfo['destinationNode']->delete();

        }

        if (!$this->broadcastEvent('beforeUnbind',array($moveInfo['source']))) return false;
        if (!$this->broadcastEvent('beforeBind',array($moveInfo['destination']))) return false;
        $this->tree->move($moveInfo['source'],$moveInfo['destination']);
        $this->broadcastEvent('afterBind',array($moveInfo['destination']));

        // If a resource was overwritten we should send a 204, otherwise a 201
        $this->httpResponse->setHeader('Content-Length','0');
        $this->httpResponse->sendStatus($moveInfo['destinationExists']?204:201);

    }

    /**
     * WebDAV HTTP COPY method
     *
     * This method copies one uri to a different uri, and works much like the MOVE request
     * A lot of the actual request processing is done in getCopyMoveInfo
     * 
     * @return void
     */
    protected function httpCopy() {

        $copyInfo = $this->getCopyAndMoveInfo();
        if ($copyInfo['destinationExists']) {

            if (!$this->broadcastEvent('beforeUnbind',array($copyInfo['destination']))) return false;
            $copyInfo['destinationNode']->delete();

        }
        if (!$this->broadcastEvent('beforeBind',array($copyInfo['destination']))) return false;
        $this->tree->copy($copyInfo['source'],$copyInfo['destination']);
        $this->broadcastEvent('afterBind',array($copyInfo['destination']));

        // If a resource was overwritten we should send a 204, otherwise a 201
        $this->httpResponse->setHeader('Content-Length','0');
        $this->httpResponse->sendStatus($copyInfo['destinationExists']?204:201);

    }



    /**
     * HTTP REPORT method implementation
     *
     * Although the REPORT method is not part of the standard WebDAV spec (it's from rfc3253)
     * It's used in a lot of extensions, so it made sense to implement it into the core.
     * 
     * @return void
     */
    protected function httpReport() {

        $body = $this->httpRequest->getBody(true);
        $dom = Sabre_DAV_XMLUtil::loadDOMDocument($body);

        $reportName = Sabre_DAV_XMLUtil::toClarkNotation($dom->firstChild);

        if ($this->broadcastEvent('report',array($reportName,$dom))) {

            // If broadcastEvent returned true, it means the report was not supported
            throw new Sabre_DAV_Exception_ReportNotImplemented();

        }

    }

    // }}}
    // {{{ HTTP/WebDAV protocol helpers 

    /**
     * Handles a http request, and execute a method based on its name 
     * 
     * @return void
     */
    protected function invoke() {

        $method = strtolower($this->httpRequest->getMethod()); 

        if (!$this->broadcastEvent('beforeMethod',array(strtoupper($method)))) return;

        // Make sure this is a HTTP method we support
        if (in_array($method,$this->getAllowedMethods())) {

            call_user_func(array($this,'http' . $method));

        } else {

            if ($this->broadcastEvent('unknownMethod',array(strtoupper($method)))) {
                // Unsupported method
                throw new Sabre_DAV_Exception_NotImplemented();
            }

        }

    }

    /**
     * Returns an array with all the supported HTTP methods 
     * 
     * @return array 
     */
    protected function getAllowedMethods() {

        $methods = array('options','get','head','delete','trace','propfind','mkcol','put','proppatch','copy','move','report');
        return $methods;

    }

    /**
     * Gets the uri for the request, keeping the base uri into consideration 
     * 
     * @return string
     */
    public function getRequestUri() {

        return $this->calculateUri($this->httpRequest->getUri());

    }

    /**
     * Calculates the uri for a request, making sure that the base uri is stripped out 
     * 
     * @param string $uri 
     * @throws Sabre_DAV_Exception_Forbidden A permission denied exception is thrown whenever there was an attempt to supply a uri outside of the base uri
     * @return string
     */
    public function calculateUri($uri) {

        if ($uri[0]!='/' && strpos($uri,'://')) {

            $uri = parse_url($uri,PHP_URL_PATH);

        }

        $uri = str_replace('//','/',$uri);

        if (strpos($uri,$this->baseUri)===0) {

            return trim(urldecode(substr($uri,strlen($this->baseUri))),'/');

        } else {

            throw new Sabre_DAV_Exception_Forbidden('Requested uri (' . $uri . ') is out of base uri (' . $this->baseUri . ')');

        }

    }

    /**
     * Returns the HTTP depth header
     *
     * This method returns the contents of the HTTP depth request header. If the depth header was 'infinity' it will return the Sabre_DAV_Server::DEPTH_INFINITY object
     * It is possible to supply a default depth value, which is used when the depth header has invalid content, or is completely non-existant
     * 
     * @param mixed $default 
     * @return int 
     */
    public function getHTTPDepth($default = self::DEPTH_INFINITY) {

        // If its not set, we'll grab the default
        $depth = $this->httpRequest->getHeader('Depth');
        if (is_null($depth)) $depth = $default;

        // Infinity
        if ($depth == 'infinity') $depth = self::DEPTH_INFINITY;
        else {
            // If its an unknown value. we'll grab the default
            if ($depth!=="0" && (int)$depth==0) $depth == $default;
        }

        return $depth;

    }

    /**
     * Returns the HTTP range header
     *
     * This method returns null if there is no well-formed HTTP range request
     * header or array($start, $end).
     *
     * The first number is the offset of the first byte in the range.
     * The second number is the offset of the last byte in the range.
     *
     * If the second offset is null, it should be treated as the offset of the last byte of the entity
     * If the first offset is null, the second offset should be used to retrieve the last x bytes of the entity 
     *
     * return $mixed
     */
    public function getHTTPRange() {

        $range = $this->httpRequest->getHeader('range');
        if (is_null($range)) return null; 

        // Matching "Range: bytes=1234-5678: both numbers are optional

        if (!preg_match('/^bytes=([0-9]*)-([0-9]*)$/i',$range,$matches)) return null;

        if ($matches[1]==='' && $matches[2]==='') return null;

        return array(
            $matches[1]!==''?$matches[1]:null,
            $matches[2]!==''?$matches[2]:null,
        );

    }




    /**
     * Returns information about Copy and Move requests
     * 
     * This function is created to help getting information about the source and the destination for the 
     * WebDAV MOVE and COPY HTTP request. It also validates a lot of information and throws proper exceptions 
     * 
     * The returned value is an array with the following keys:
     *   * source - Source path
     *   * destination - Destination path
     *   * destinationExists - Wether or not the destination is an existing url (and should therefore be overwritten)
     *
     * @return array 
     */
    protected function getCopyAndMoveInfo() {

        $source = $this->getRequestUri();

        // Collecting the relevant HTTP headers
        if (!$this->httpRequest->getHeader('Destination')) throw new Sabre_DAV_Exception_BadRequest('The destination header was not supplied');
        $destination = $this->calculateUri($this->httpRequest->getHeader('Destination'));
        $overwrite = $this->httpRequest->getHeader('Overwrite');
        if (!$overwrite) $overwrite = 'T';
        if (strtoupper($overwrite)=='T') $overwrite = true;
        elseif (strtoupper($overwrite)=='F') $overwrite = false;
        // We need to throw a bad request exception, if the header was invalid
        else throw new Sabre_DAV_Exception_BadRequest('The HTTP Overwrite header should be either T or F');

        $destinationUri = dirname($destination);
        if ($destinationUri=='.') $destinationUri='';

        // Collection information on relevant existing nodes
        $sourceNode = $this->tree->getNodeForPath($source);

        try {
            $destinationParent = $this->tree->getNodeForPath($destinationUri);
            if (!($destinationParent instanceof Sabre_DAV_ICollection)) throw new Sabre_DAV_Exception_UnsupportedMediaType('The destination node is not a collection');
        } catch (Sabre_DAV_Exception_FileNotFound $e) {

            // If the destination parent node is not found, we throw a 409
            throw new Sabre_DAV_Exception_Conflict('The destination node is not found');
        }

        try {

            $destinationNode = $this->tree->getNodeForPath($destination);
            
            // If this succeeded, it means the destination already exists
            // we'll need to throw precondition failed in case overwrite is false
            if (!$overwrite) throw new Sabre_DAV_Exception_PreconditionFailed('The destination node already exists, and the overwrite header is set to false');

        } catch (Sabre_DAV_Exception_FileNotFound $e) {

            // Destination didn't exist, we're all good
            $destinationNode = false;



        }

        // These are the three relevant properties we need to return
        return array(
            'source'            => $source,
            'destination'       => $destination,
            'destinationExists' => $destinationNode==true,
            'destinationNode'   => $destinationNode,
        );

    }

    /**
     * Returns a list of properties for a path
     *
     * This is a simplified version getPropertiesForPath.
     * if you aren't interested in status codes, but you just
     * want to have a flat list of properties. Use this method.
     *
     * @param string $path
     * @param array $propertyNames
     */
    public function getProperties($path, $propertyNames) {

        $result = $this->getPropertiesForPath($path,$propertyNames,0);
        return $result[0][200];

    }

    /**
     * Returns a list of HTTP headers for a particular resource
     *
     * The generated http headers are based on properties provided by the 
     * resource. The method basically provides a simple mapping between
     * DAV property and HTTP header.
     *
     * The headers are intended to be used for HEAD and GET requests.
     * 
     * @param string $path
     */
    public function getHTTPHeaders($path) {

        $propertyMap = array(
            '{DAV:}getcontenttype'   => 'Content-Type',
            '{DAV:}getcontentlength' => 'Content-Length',
            '{DAV:}getlastmodified'  => 'Last-Modified', 
            '{DAV:}getetag'          => 'ETag',
        );

        $properties = $this->getProperties($path,array_keys($propertyMap));

        $headers = array();
        foreach($propertyMap as $property=>$header) {
            if (isset($properties[$property])) {
                if (is_scalar($properties[$property])) { 
                    $headers[$header] = $properties[$property];

                // GetLastModified gets special cased 
                } elseif ($properties[$property] instanceof Sabre_DAV_Property_GetLastModified) {
                    $headers[$header] = $properties[$property]->getTime()->format(DateTime::RFC1123);
                }

            }
        }

        return $headers;
        
    }

    /**
     * Returns a list of properties for a given path
     * 
     * The path that should be supplied should have the baseUrl stripped out
     * The list of properties should be supplied in Clark notation. If the list is empty
     * 'allprops' is assumed.
     *
     * If a depth of 1 is requested child elements will also be returned.
     *
     * @param string $path 
     * @param array $propertyNames
     * @param int $depth 
     * @return array
     */
    public function getPropertiesForPath($path,$propertyNames = array(),$depth = 0) {

        if ($depth!=0) $depth = 1;

        $returnPropertyList = array();
        
        $parentNode = $this->tree->getNodeForPath($path);
        $nodes = array(
            $path => $parentNode
        );
        if ($depth==1 && $parentNode instanceof Sabre_DAV_ICollection) {
            foreach($parentNode->getChildren() as $childNode)
                $nodes[$path . '/' . $childNode->getName()] = $childNode;
        }            
       
        // If the propertyNames array is empty, it means all properties are requested.
        // We shouldn't actually return everything we know though, and only return a
        // sensible list. 
        $allProperties = count($propertyNames)==0;

        foreach($nodes as $myPath=>$node) {

            $newProperties = array(
                '200' => array(),
                '404' => array(),
            );
            if ($node instanceof Sabre_DAV_IProperties) 
                $newProperties['200'] = $node->getProperties($propertyNames);

            if ($allProperties) {

                // Default list of propertyNames.
                // note that the list might be bigger due to plugins or Node objects
                // returning a bigger list.
                $propertyNames = array(
                    '{DAV:}getlastmodified',
                    '{DAV:}getcontentlength',
                    '{DAV:}resourcetype',
                    '{DAV:}quota-used-bytes',
                    '{DAV:}quota-available-bytes',
                    '{DAV:}getetag',
                    '{DAV:}getcontenttype',
                );
            }

            // If the resourceType was not part of the list, we manually add it 
            // and mark it for removal. We need to know the resourcetype in order 
            // to make certain decisions about the entry.
            // WebDAV dictates we should add a / and the end of href's for collections
            $removeRT = false;
            if (!in_array('{DAV:}resourcetype',$propertyNames)) {
                $propertyNames[] = '{DAV:}resourcetype';
                $removeRT = true;
            }

            foreach($propertyNames as $prop) {
                
                if (isset($newProperties[200][$prop])) continue;

                switch($prop) {
                    case '{DAV:}getlastmodified'       : if ($node->getLastModified()) $newProperties[200][$prop] = new Sabre_DAV_Property_GetLastModified($node->getLastModified()); break;
                    case '{DAV:}getcontentlength'      : if ($node instanceof Sabre_DAV_IFile) $newProperties[200][$prop] = (int)$node->getSize(); break;
                    case '{DAV:}resourcetype'          : $newProperties[200][$prop] = new Sabre_DAV_Property_ResourceType($node instanceof Sabre_DAV_ICollection?self::NODE_DIRECTORY:self::NODE_FILE); break;
                    case '{DAV:}quota-used-bytes'      : 
                        if ($node instanceof Sabre_DAV_IQuota) {
                            $quotaInfo = $node->getQuotaInfo();
                            $newProperties[200][$prop] = $quotaInfo[0];
                        }
                        break;
                    case '{DAV:}quota-available-bytes' : 
                        if ($node instanceof Sabre_DAV_IQuota) {
                            $quotaInfo = $node->getQuotaInfo();
                            $newProperties[200][$prop] = $quotaInfo[1];
                        }
                        break;
                    case '{DAV:}getetag'               : if ($node instanceof Sabre_DAV_IFile && $etag = $node->getETag())  $newProperties[200][$prop] = $etag; break;
                    case '{DAV:}getcontenttype'        : if ($node instanceof Sabre_DAV_IFile && $ct = $node->getContentType())  $newProperties[200][$prop] = $ct; break;
                    case '{DAV:}supported-report-set'  : $newProperties[200][$prop] = new Sabre_DAV_Property_SupportedReportSet(); break;

                }

                // If we were unable to find the property, we will list it as 404.
                if (!$allProperties && !isset($newProperties[200][$prop])) $newProperties[404][$prop] = null;

            }
         
            $this->broadcastEvent('afterGetProperties',array(trim($myPath,'/'),&$newProperties));

            $newProperties['href'] = trim($myPath,'/'); 

            // Its is a WebDAV recommendation to add a trailing slash to collectionnames.
            // Apple's iCal also requires a trailing slash for principals (rfc 3744).
            // Therefore we add a trailing / for any non-file. This might need adjustments 
            // if we find there are other edge cases.
            if ($myPath!='' && isset($newProperties[200]['{DAV:}resourcetype']) && $newProperties[200]['{DAV:}resourcetype']->getValue()!==null) $newProperties['href'] .='/';

            // If the resourcetype property was manually added to the requested property list,
            // we will remove it again.
            if ($removeRT) unset($newProperties[200]['{DAV:}resourcetype']);

            $returnPropertyList[] = $newProperties;

        }
        
        return $returnPropertyList;

    }

    /**
     * This method is invoked by sub-systems creating a new file.
     *
     * Currently this is done by HTTP PUT and HTTP LOCK (in the Locks_Plugin).
     * It was important to get this done through a centralized function, 
     * allowing plugins to intercept this using the beforeCreateFile event.
     * 
     * @param string $uri 
     * @param resource $data 
     * @return void
     */
    public function createFile($uri,$data) {

        $parentUri = dirname($uri);
        if ($parentUri=='.') $parentUri = '';
        if (!$this->broadcastEvent('beforeBind',array($uri))) return;
        if (!$this->broadcastEvent('beforeCreateFile',array($uri,$data))) return;

        $parent = $this->tree->getNodeForPath($parentUri);
        $parent->createFile(basename($uri),$data);

        $this->broadcastEvent('afterBind',array($uri));
    }

    /**
     * This method is invoked by sub-systems creating a new directory.
     *
     * @param string $uri 
     * @return void
     */
    public function createDirectory($uri) {

        $this->createCollection($uri,array('{DAV:}collection'),array());

    }

    public function createCollection($uri, array $resourceType, array $properties) {

        $parentUri = dirname($uri);
        if ($parentUri=='.') $parentUri = '';

        // Making sure {DAV:}collection was specified as resourceType
        if (!in_array('{DAV:}collection', $resourceType)) {
            throw new Sabre_DAV_Exception_InvalidResourceType('The resourceType for this collection must at least include {DAV:}collection');
        }


        // Making sure the parent exists
        try {

            $parent = $this->tree->getNodeForPath($parentUri);

        } catch (Sabre_DAV_Exception_FileNotFound $e) {

            throw new Sabre_DAV_Exception_Conflict('Parent node does not exist');

        }

        // Making sure the parent is a collection
        if (!$parent instanceof Sabre_DAV_ICollection) {
            throw new Sabre_DAV_Exception_Conflict('Parent node is not a collection');
        }



        // Making sure the child does not already exist
        try {
            $parent->getChild(basename($uri));

            // If we got here.. it means there's already a node on that url, and we need to throw a 405
            throw new Sabre_DAV_Exception_MethodNotAllowed('The resource you tried to create already exists');

        } catch (Sabre_DAV_Exception_FileNotFound $e) {
            // This is correct
        }

        
        if (!$this->broadcastEvent('beforeBind',array($uri))) return;

        // There are 2 modes of operation. The standard collection 
        // creates the directory, and then updates properties
        // the extended collection can create it directly.
        if ($parent instanceof Sabre_DAV_IExtendedCollection) {

            $parent->createExtendedCollection(basename($uri), $resourceType, $properties);

        } else {

            // No special resourcetypes are supported
            if (count($resourceType)>1) {
                throw new Sabre_DAV_Exception_InvalidResourceType('The {DAV:}resourcetype you specified is not supported here.');
            }

            $parent->createDirectory(basename($uri));
            
            if (count($properties)>0) {

                $newNode = $parent->getChild(basename($uri));
                // TODO: need to rollback if newnode is not a Sabre_DAV_Properties
                // TODO: need to rollback is updateProperties fails
                if ($newNode instanceof Sabre_DAV_IProperties) {
                    $mutations = array();
                    foreach($properties as $property) {
                        $mutations[] = array(self::PROP_SET, $propertyName, $propertyValue);
                    }
                    $newNode->updateProperties($mutations);
                }
            } 
        }
        $this->broadcastEvent('afterBind',array($uri));

    }

    // }}} 
    // {{{ XML Readers & Writers  
    
    
    /**
     * Generates a WebDAV propfind response body based on a list of nodes 
     * 
     * @param array $fileProperties The list with nodes
     * @param array $requestedProperties The properties that should be returned
     * @return string 
     */
    public function generateMultiStatus(array $fileProperties) {

        $dom = new DOMDocument('1.0','utf-8');
        //$dom->formatOutput = true;
        $multiStatus = $dom->createElementNS('DAV:','d:multistatus');
        $dom->appendChild($multiStatus);

        // Adding in default namespaces
        foreach($this->xmlNamespaces as $namespace=>$prefix) {

            $multiStatus->setAttribute('xmlns:' . $prefix,$namespace);

        }

        foreach($fileProperties as $entry) {

            $href = $entry['href'];
            unset($entry['href']);
            
            $response = new Sabre_DAV_Property_Response($href,$entry);
            $response->serialize($this,$multiStatus);

        }

        return $dom->saveXML();

    }

    /**
     * This method parses a PropPatch request 
     * 
     * @param string $body xml body
     * @return array list of properties in need of updating or deletion
     */
    protected function parsePropPatchRequest($body) {

        //We'll need to change the DAV namespace declaration to something else in order to make it parsable
        $dom = Sabre_DAV_XMLUtil::loadDOMDocument($body);
        
        $operations = array();

        foreach($dom->firstChild->childNodes as $child) {

            if ($child->nodeType !== XML_ELEMENT_NODE) continue; 

            $operation = Sabre_DAV_XMLUtil::toClarkNotation($child);
            switch($operation) {
                case '{DAV:}set' :
                    $propList = Sabre_DAV_XMLUtil::parseProperties($child, $this->propertyMap);
                    foreach($propList as $k=>$propItem) {
                        $operations[] = array(self::PROP_SET, $k, $propItem);
                    }
                    break;

                case '{DAV:}remove' :
                    $propList = Sabre_DAV_XMLUtil::parseProperties($child);
                    foreach($propList as $k=>$propItem) {

                        $operations[] = array(self::PROP_REMOVE,$k);

                    }
                    break;
            }

        }

        return $operations;

    }

    /**
     * This method parses the PROPFIND request and returns its information
     *
     * This will either be a list of properties, or an empty array; in which case
     * an {DAV:}allprop was requested.
     * 
     * @param string $body 
     * @return array 
     */
    public function parsePropFindRequest($body) {

        // If the propfind body was empty, it means IE is requesting 'all' properties
        if (!$body) return array();

        $dom = Sabre_DAV_XMLUtil::loadDOMDocument($body);
        $elem = $dom->getElementsByTagNameNS('urn:DAV','propfind')->item(0);
        return array_keys(Sabre_DAV_XMLUtil::parseProperties($elem)); 

    }

    // }}}

}

