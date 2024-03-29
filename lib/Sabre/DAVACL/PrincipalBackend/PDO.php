<?php

/**
 * PDO principal backend
 *
 * This is a simple principal backend that maps exactly to the users table, as 
 * used by Sabre_DAV_Auth_Backend_PDO.
 *
 * It assumes all principals are in a single collection. The default collection 
 * is 'principals/', but this can be overriden.
 *
 * @package Sabre
 * @subpackage DAVACL
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAVACL_PrincipalBackend_PDO implements Sabre_DAVACL_IPrincipalBackend {

    /**
     * pdo 
     * 
     * @var PDO 
     */
    protected $pdo;

    /**
     * Sets up the backend 
     * 
     * @param PDO $pdo 
     */
    public function __construct(PDO $pdo) {

        $this->pdo = $pdo;

    } 


    /**
     * Returns a list of principals based on a prefix.
     *
     * This prefix will often contain something like 'principals'. You are only 
     * expected to return principals that are in this base path.
     *
     * You are expected to return at least a 'uri' for every user, you can 
     * return any additional properties if you wish so. Common properties are:
     *   {DAV:}displayname 
     *   {http://sabredav.org/ns}email-address - This is a custom SabreDAV 
     *     field that's actualy injected in a number of other properties. If
     *     you have an email address, use this property.
     * 
     * @param string $prefixPath 
     * @return array 
     */
    public function getPrincipalsByPrefix($prefixPath) {
        $result = $this->pdo->query('SELECT uri, email, displayname FROM principals');

        $principals = array();

        while($row = $result->fetch(PDO::FETCH_ASSOC)) {

            // Checking if the principal is in the prefix
            list($rowPrefix) = Sabre_DAV_URLUtil::splitPath($row['uri']);
            if ($rowPrefix !== $prefixPath) continue;

            $principals[] = array(
                'uri' => $row['uri'],
                '{DAV:}displayname' => $row['displayname']?$row['displayname']:basename($row['uri']),
                '{http://sabredav.org/ns}email-address' => $row['email'],
            );

        }

        return $principals;

    }

    /**
     * Returns a specific principal, specified by it's path.
     * The returned structure should be the exact same as from 
     * getPrincipalsByPrefix. 
     * 
     * @param string $path 
     * @return array 
     */
    public function getPrincipalByPath($path) {

        $stmt = $this->pdo->prepare('SELECT id, uri, email, displayname FROM principals WHERE uri = ?');
        $stmt->execute(array($path));

        $users = array();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        return array(
            'id'  => $row['id'],
            'uri' => $row['uri'],
            '{DAV:}displayname' => $row['displayname']?$row['displayname']:basename($row['uri']),
            '{http://sabredav.org/ns}email-address' => $row['email'],
        );

    }

    /**
     * Returns the list of members for a group-principal 
     * 
     * @param string $principal 
     * @return array 
     */
    public function getGroupMemberSet($principal) {

        $principal = $this->getPrincipalByPath($principal);
        if (!$principal) throw new Sabre_DAV_Exception('Principal not found');

        $stmt = $this->pdo->prepare('SELECT principals.uri as uri FROM groupmembers LEFT JOIN principals ON groupmembers.member_id = principals.id WHERE groupmembers.principal_id = ?');
        $stmt->execute(array($principal['id']));

        $result = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = $row['uri'];
        }
        return $result;
    
    }

    /**
     * Returns the list of groups a principal is a member of 
     * 
     * @param string $principal 
     * @return array 
     */
    public function getGroupMembership($principal) {

        $principal = $this->getPrincipalByPath($principal);
        if (!$principal) throw new Sabre_DAV_Exception('Principal not found');

        $stmt = $this->pdo->prepare('SELECT principals.uri as uri FROM groupmembers LEFT JOIN principals ON groupmembers.principal_id = principals.id WHERE groupmembers.member_id = ?');
        $stmt->execute(array($principal['id']));

        $result = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[] = $row['uri'];
        }
        return $result;

    }

}
