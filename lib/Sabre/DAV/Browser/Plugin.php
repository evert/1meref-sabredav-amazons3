<?php

class Sabre_DAV_Browser_Plugin extends Sabre_DAV_ServerPlugin {

    protected $server;

    function initialize(Sabre_DAV_Server $server) {

        $this->server = $server;
        $this->server->subscribeEvent('beforeMethod',array($this,'httpGetInterceptor'));

    }

    public function httpGetInterceptor($method) {

        if ($method!='GET') return true;
        
        $nodeInfo = $this->server->getPropertiesForPath($this->server->getRequestUri(),array('{DAV:}resourcetype'));
        if ($nodeInfo[0]['{DAV:}resourcetype']->getValue()!='{DAV:}collection') return true;

        $this->generateDirectoryIndex($this->server->getRequestUri());

        return false;
        
    }

    public function httpPOSTHandler($method) {

        if ($method!='POST') return true;


    }

    public function escapeHTML($value) {

        return htmlspecialchars($value,ENT_QUOTES,'UTF-8');

    }

    public function generateDirectoryIndex($path) {

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

    }

}
