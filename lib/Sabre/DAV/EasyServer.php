<?php

class Sabre_DAV_EasyServer extends Sabre_DAV_Server {

    public $settingsFile='sabredav.ini'; 
    
    protected $caldavBackend = null;
    protected $authBackend = null;
    protected $locksBackend = null;
    protected $pdo = null;

    function exec() {

        if (!file_exists($this->settingsFile)) {

            throw new Sabre_DAV_Exception('Could not find settings file: ' . $this->settingsFile); 

        }

        $result = parse_ini_file($this->settingsFile,true,true);
       
        set_error_handler(array($this,"exception_error_handler"));

        if (isset($result['global'])) {
            if (isset($result['global']['base_uri'])) {
                $this->setBaseUri($result['global']['base_uri']);
            }

            if (isset($result['global']['browser_enable']) && $result['global']['browser_enable']) {
                $this->addPlugin(new Sabre_DAV_Browser_Plugin());
            }
            if (isset($result['global']['default_timezone'])) {
                date_default_timezone_set($result['global']['default_timezone']);
            }
            
            if (isset($result['global']['pdo_dsn'])) {
                $user = isset($result['global']['pdo_user']);
                $pass = isset($result['global']['pdo_pass']);
                $this->pdo = new PDO($result['global']['pdo_dsn'], $user, $pass);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            }

            if (isset($result['global']['caldav_backend'])) {
                switch($result['global']['caldav_backend']) {
                    case 'pdo' :
                        if (is_null($this->pdo)) {
                            throw new Sabre_DAV_Exception('Caldav backend-type PDO was given, but no PDO database was setup. Please specify the pdo_dsn setting');
                        }
                        $this->caldavBackend = new Sabre_CalDAV_Backend_PDO($this->pdo);
                        break;

                    default:
                        throw new Sabre_DAV_Exception('Unknown caldav backend-type: ' . $result['global']['caldav_backend']);
                        break;
                        

                }
            }
            if (isset($result['global']['auth_backend'])) {
                switch($result['global']['auth_backend']) {
                    case 'pdo' :
                        if (is_null($this->pdo)) {
                            throw new Sabre_DAV_Exception('Auth backend-type PDO was given, but no PDO database was setup. Please specify the pdo_dsn setting');
                        }
                        $this->authBackend = new Sabre_DAV_Auth_Backend_PDO($this->pdo);
                        break;
                    case 'file' :
                        if (!isset($result['global']['auth_file'])) {
                            throw new Sabre_DAV_Exception('Auth backend-type "file" was given, but auth_file is not specified.');
                        }
                        if (!is_file($result['global']['auth_file'])) {
                            throw new Sabre_DAV_Exception('Could not find file: ' . $result['global']['auth_file']);
                        }
                        $this->authBackend = new Sabre_DAV_Auth_Backend_File($result['global']['auth_file']);
                        break;
                            
                    default:
                        throw new Sabre_DAV_Exception('Unknown auth backend-type: ' . $result['global']['auth_backend']);
                        break;
                        

                }
            }
            if (isset($result['global']['locks_backend'])) {
                switch($result['global']['locks_backend']) {
                    case 'pdo' :
                        if (is_null($this->pdo)) {
                            throw new Sabre_DAV_Exception('Locks backend-type PDO was given, but no PDO database was setup. Please specify the pdo_dsn setting');
                        }
                        $this->locksBackend = new Sabre_DAV_Locks_Backend_PDO($this->pdo);
                        break;
                    case 'fs' :
                        if (!isset($result['global']['locks_path'])) {
                            throw new Sabre_DAV_Exception('Locks backend-type "fs" was given, but locks_path not specified.');
                        }
                        if (!is_dir($result['global']['locks_path'])) {
                            throw new Sabre_DAV_Exception('Could not find path: ' . $result['global']['locks_path']);
                        }
                        $this->locksBackend = new Sabre_DAV_Locks_Backend_FS($result['global']['locks_path']);
                        break;
                            
                    default:
                        throw new Sabre_DAV_Exception('Unknown locks backend-type: ' . $result['global']['locks_backend']);
                        break;
                        

                }
            }

            if (isset($result['global']['auth_enabled']) && $result['global']['auth_enabled']) {
                if (is_null($this->authBackend)) {
                    throw new Sabre_DAV_Exception('No Auth backend was setup.');
                }
                $realm = isset($result['global']['auth_realm'])?$result['global']['auth_realm']:'SabreDAV';
                $this->addPlugin(new Sabre_DAV_Auth_Plugin($this->authBackend, $realm));
            }
            if (isset($result['global']['caldav_enabled']) && $result['global']['caldav_enabled']) {
                $this->addPlugin(new Sabre_CalDAV_Plugin());
            }
            if (isset($result['global']['locks_enabled']) && $result['global']['locks_enabled']) {
                if (is_null($this->locksBackend)) {
                    throw new Sabre_DAV_Exception('No Locks backend was setup.');
                }
                $this->addPlugin(new Sabre_DAV_Locks_Plugin($this->locksBackend));
            }

            unset($result['global']);
        }

        foreach($result as $sectionName => $settings) {

            $this->addShare($sectionName, $settings);

        }
        parent::exec();

    }

    function addShare($name, $settings) {

        if (!isset($settings['type'])) {
            throw new Sabre_DAV_Exception('type was not specified for section: ' . $name);    
        }

        switch($settings['type']) {

            case 'fs' :
                if (!isset($settings['path']))
                    throw new Sabre_DAV_Exception('path must be specified for section: ' . $name);
                
                if (!file_exists($settings['path']) || !is_dir($settings['path']))
                    throw new Sabre_DAV_Exception($settings['path'] . ' does not exist, or is not a directory');

                $node = new Sabre_DAV_FS_Directory($settings['path'], $name);
                break;
            case 'calendars' :
                if (is_null($this->caldavBackend)) {
                    throw new Sabre_DAV_Exception('No CalDAV backend was setup.');
                }
                if (is_null($this->authBackend)) {
                    throw new Sabre_DAV_Exception('No Auth backend was setup.');
                }
                $node = new Sabre_CalDAV_CalendarRootNode($this->authBackend, $this->caldavBackend);
                break;
            case 'principals' :
                if (is_null($this->authBackend)) {
                    throw new Sabre_DAV_Exception('No Auth backend was setup.');
                }
                $node = new Sabre_DAV_Auth_PrincipalCollection($this->authBackend);
                break;
            default :
                throw new Sabre_DAV_Exception('Unknown share type: ' . $settings['type']);
                break;

        }
        $root = $this->tree->getNodeForPath('');
        if (!($root instanceof Sabre_DAV_SimpleDirectory)) {
            throw new Sabre_DAV_Exception('Root object must be an instance of Sabre_DAV_SimpleDirectory.');
        }

        $root->addChild($node);

    }

    //Mapping PHP errors to exceptions
    function exception_error_handler($errno, $errstr, $errfile, $errline ) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
}
