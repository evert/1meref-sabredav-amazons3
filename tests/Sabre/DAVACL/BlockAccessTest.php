<?php

class Sabre_DAVACL_BlockAccessTest extends PHPUnit_Framework_TestCase {

    protected $server;

    function setUp() {

        $nodes = array(
            new Sabre_DAV_SimpleDirectory('testdir'),
        );

        $this->server = new Sabre_DAV_Server($nodes);
        $aclPlugin = new Sabre_DAVACL_Plugin();
        $this->server->addPlugin($aclPlugin);

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testGet() {

        $this->server->broadcastEvent('beforeMethod',array('GET','testdir'));

    }

    function testGetDoesntExist() {

        $r = $this->server->broadcastEvent('beforeMethod',array('GET','foo'));
        $this->assertTrue($r);

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testHEAD() {

        $this->server->broadcastEvent('beforeMethod',array('HEAD','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testOPTIONS() {

        $this->server->broadcastEvent('beforeMethod',array('OPTIONS','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testPUT() {

        $this->server->broadcastEvent('beforeMethod',array('PUT','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testPROPPATCH() {

        $this->server->broadcastEvent('beforeMethod',array('PROPPATCH','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testCOPY() {

        $this->server->broadcastEvent('beforeMethod',array('COPY','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testMOVE() {

        $this->server->broadcastEvent('beforeMethod',array('MOVE','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testLOCK() {

        $this->server->broadcastEvent('beforeMethod',array('LOCK','testdir'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testBeforeBind() {

        $this->server->broadcastEvent('beforeBind',array('testdir/file'));

    }

    /**
     * @expectedException Sabre_DAVACL_Exception_NeedPrivileges
     */
    function testBeforeUnbind() {

        $this->server->broadcastEvent('beforeUnbind',array('testdir'));

    }

    function testAfterGetProperties() {

        $properties = array(
            'href' => 'foo',
            '200' => array(
                '{DAV:}displayname' => 'foo',
                '{DAV:}getcontentlength' => 500,
            ),
            '404' => array(
                '{DAV:}bar' => null,
            ),
            '403' => array(
                '{DAV:}owner' => null,
            ),
        );

        $expected = array(
            'href' => 'foo',
            '403' => array(
                '{DAV:}displayname' => null,
                '{DAV:}getcontentlength' => null,
                '{DAV:}bar' => null,
                '{DAV:}owner' => null,
            ),
        );

        $r = $this->server->broadcastEvent('afterGetProperties',array('testdir',&$properties));
        $this->assertTrue($r);

        $this->assertEquals($expected, $properties);

    }

}
