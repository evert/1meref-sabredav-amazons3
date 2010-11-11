<?php

/**
 * This INode interface extends the base INode interface with common methods for all S3 classes
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_S3_INode extends Sabre_DAV_INode
{

	/**
	 * Returns the node's ID
	 *
	 * @return string
	 */
	public function getID();

	/**
	 * Returns the node's parent
	 *
	 * @return Sabre_DAV_S3_ICollection
	 */
	public function getParent();

	/**
	 * Sets this node's parent
	 * 
	 * @param Sabre_DAV_S3_ICollection $node
	 * @return void
	 */
	public function setParent(Sabre_DAV_S3_ICollection $node);

	/**
	 * Returns the node's S3 instance
	 *
	 * @return AmazonS3
	 */
	public function getS3();

	/**
	 * Returns the node's S3 endpoint Region or it's default setting for child nodes
	 * 
	 * @return string
	 */
	public function getRegion();

	/**
	 * Sets the node's S3 endpoint Region or it's default setting for child nodes
	 * 
	 * @param string $region Valid values are [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1] 
	 * @return void
	 */
	public function setRegion($region);

	/**
	 * Sets the node's last modification time
	 *
	 * @param int $lastmodified Unix timestamp
	 * @return void
	 */
	public function setLastModified($lastmodified);

	/**
	 * Returns the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @return string
	 */
	public function getStorageClass();

	/**
	 * Sets the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @param string $storageclass
	 * @return void
	 */
	public function setStorageClass($storageclass);

	/**
	 * Returns the node's Owner
	 *
	 * @return array Associative array with 'ID' and 'DisplayName'
	 */
	public function getOwner();

	/**
	 * Sets the node's Owner
	 *
	 * @param array $owner
	 * @return void
	 */
	public function setOwner($owner);

	/**
	 * Returns the node's Canned ACL
	 *
	 * @return string [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL]
	 */
	public function getACL();

	/**
	 * Sets the node's Canned ACL
	 *
	 * @param string|array $acl Allowed values: [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL] or an array of associative arrays with keys 'id' and 'permission'
	 * @return void
	 */
	public function setACL($acl);
}
