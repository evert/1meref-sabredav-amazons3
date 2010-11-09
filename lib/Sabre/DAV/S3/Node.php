<?php

/**
 * Base node-class for S3 buckets
 * The node class implements the methods used by both the File and the Directory class
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_DAV_S3_Node implements Sabre_DAV_S3_INode
{
	/**
	 * The node's name
	 * 
	 * @var string
	 */
	protected $name = null;

	/**
	 * This node's parent node
	 *
	 * @var Sabre_DAV_S3_Directory
	 */
	protected $parent = null;

	/**
	 * The Amazon S3 SDK instance for API calls
	 *
	 * @var AmazonS3
	 */
	protected $s3 = null;

	/**
	 * The node's S3 endpoint Region
	 * Valid values are [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * 
	 * @var string 
	 */
	protected $region = null;

	/**
	 * Indicates if the full set of metadata including ACL has been requested
	 *
	 * @var bool
	 */
	protected $metadata_requested = false;

	/**
	 * Last modification time, if available
	 *
	 * @var int
	 */
	protected $lastmodified = null;

	/**
	 * S3 Storage Redundancy setting
	 *
	 * @var int
	 */
	protected $storageclass = null;

	/**
	 * The node's Owner
	 * Associative array with 'ID' and 'DisplayName'
	 *
	 * @var array
	 */
	protected $owner = null;

	/**
	 * The node's Access Control List (ACL)
	 * String allowed values: [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL]
	 *
	 * @var string
	 */
	protected $acl = null;

	/**
	 * Sets up the node
	 * If $parent is not given, a S3 instance or Amazon credentials ($key, $secret_key) have to be given
	 *
	 * @param string $name The node's display name returned by getName()
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($name, Sabre_DAV_S3_ICollection $parent = null, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = AmazonS3::REGION_US_E1, $use_ssl = true)
	{
		$this->name = $name;

		//default values
		$this->region = AmazonS3::REGION_US_E1;
		if (!isset($use_ssl))
			$use_ssl = true;

		if (isset($parent))
		{
			$this->parent = $parent;
			$this->s3 = $parent->getS3();
			$this->region = $parent->getRegion();
		}

		if (isset($s3))
			$this->s3 = $s3;

		if (isset($region))
			$this->region = $region;

		if (isset($key) && isset($secret_key))
		{
			$this->s3 = new AmazonS3($key, $secret_key);
			$this->s3->set_region($this->region);
			if (!$use_ssl)
				$this->s3->disable_ssl();
		}
	}

	/**
	 * Converts permission values to bitfields
	 *
	 * @param string $permission
	 */
	protected function getPermissionValue($permission)
	{
		switch ($permission)
		{
			case AmazonS3::GRANT_READ:
				return 1;
			case AmazonS3::GRANT_WRITE:
				return 2;
			case AmazonS3::GRANT_READ_ACP:
				return 4;
			case AmazonS3::GRANT_WRITE_ACP:
				return 8;
			case AmazonS3::GRANT_FULL_CONTROL:
				return 15;
			default:
				return 0;
		}
	}

	/**
	 * Converts the ACL from an associative array to one of Amazon's predefined canned ACLs
	 * Only limited sets of policies can be reflected. Owner always has full control.
	 *
	 * @param array $acl
	 */
	protected function cannedACL($acl)
	{
		if (!is_array($acl))
			return null;

		$all = 0;
		$auth = 0;

		foreach ($acl as $grant)
		{
			switch ($grant['id'])
			{
				case AmazonS3::USERS_ALL:
					$all = $all | $this->getPermissionValue($grant['permission']);
					break;
				case AmazonS3::USERS_AUTH:
					$auth = $auth | $this->getPermissionValue($grant['permission']);
					break;
				default:
					break;
			}
		}

		if (($all & 3) == 3)
			return AmazonS3::ACL_OPEN;
		elseif (($all & 1) == 1)
			return AmazonS3::ACL_PUBLIC;
		elseif (($auth & 1) == 1)
			return AmazonS3::ACL_AUTH_READ;
		else
			return AmazonS3::ACL_PRIVATE;
	}

	/**
	 * Retrieve the node's metadata
	 *
	 * @param bool $force
	 * @return void
	 */
	protected function requestMetaData($force = false)
	{
		if (!$force && $this->metadata_requested)
			return;
			
		if (!isset($this->lastmodified))
			$this->setLastModified(0);
/*		if (!isset($this->size))
			$this->setSize(0);
		if (!isset($this->etag))
			$this->setETag('');
		if (!isset($this->contenttype))
			$this->setContentType('');*/
		if (!isset($this->storageclass))
			$this->setStorageClass(AmazonS3::STORAGE_STANDARD);
		if (!isset($this->owner))
			$this->setOwner(array());
		if (!isset($this->acl))
			$this->setACL(AmazonS3::ACL_PRIVATE);

		$this->metadata_requested = true;
	}

	/**
	 * Returns the node's name
	 *
	 * @return string
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Sets the node's name
	 *
	 * @param string $name
	 * @return void
	 */
	public function setName($name)
	{
		$oldName = $this->name;
		$this->name = $name;
		if ($this->parent)
		{
			$this->parent->removeChild($oldName);
			$this->parent->addChild($this);
		}
	}

	/**
	 * Returns the node's parent
	 *
	 * @return Sabre_DAV_S3_ICollection
	 */
	public function getParent()
	{
		return $this->parent;
	}

	/**
	 * Sets this node's parent
	 * 
	 * @param Sabre_DAV_S3_ICollection $node
	 * @return void
	 */
	public function setParent(Sabre_DAV_S3_ICollection $node)
	{
		$this->parent = $node;

		if (isset($node))
		{
			if (!isset($this->s3))
				$this->s3 = $node->getS3();
			if (!isset($this->region))
				$this->region = $node->getRegion();
		}
	}

	/**
	 * Returns the node's S3 instance
	 *
	 * @return AmazonS3
	 */
	public function getS3()
	{
		if (isset($this->s3) && isset($this->region))
			$this->s3->set_region($this->region);

		return $this->s3;
	}

	/**
	 * Returns the node's S3 endpoint Region or it's default setting for child nodes
	 * 
	 * @return string
	 */
	public function getRegion()
	{
		return $this->region;
	}

	/**
	 * Sets the node's S3 endpoint Region or it's default setting for child nodes
	 * 
	 * @param string $region Valid values are [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1] 
	 * @return void
	 */
	public function setRegion($region)
	{
		$this->region = $region;
		
		if ($this->s3)
			$this->s3->set_region($region);
	}

	/**
	 * Returns the node's last modification time
	 *
	 * @return int Unix timestamp
	 */
	public function getLastModified()
	{
		if (!isset($this->lastmodified))
			$this->requestMetaData();

		return $this->lastmodified;
	}

	/**
	 * Sets the node's last modification time
	 *
	 * @param int $lastmodified Unix timestamp
	 * @return void
	 */
	public function setLastModified($lastmodified)
	{
		$this->lastmodified = $lastmodified;
	}

	/**
	 * Returns the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @return string
	 */
	public function getStorageClass()
	{
		if (!isset($this->storageclass))
			$this->requestMetaData();

		return $this->storageclass;
	}

	/**
	 * Sets the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @param string $storageclass
	 * @return void
	 */
	public function setStorageClass($storageclass)
	{
		$this->storageclass = $storageclass;
	}

	/**
	 * Returns the node's Owner
	 *
	 * @return array Associative array with 'ID' and 'DisplayName'
	 */
	public function getOwner()
	{
		if (!isset($this->owner))
			$this->requestMetaData();

		return $this->owner;
	}

	/**
	 * Sets the node's Owner
	 *
	 * @param array $owner
	 * @return void
	 */
	public function setOwner($owner)
	{
		$this->owner = $owner;
	}

	/**
	 * Returns the node's Canned ACL
	 *
	 * @return string [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL]
	 */
	public function getACL()
	{
		if (!isset($this->acl))
			$this->requestMetaData();

		return $this->acl;
	}

	/**
	 * Sets the node's Canned ACL
	 *
	 * @param string|array $acl Allowed values: [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL] or an array of associative arrays with keys 'id' and 'permission'
	 * @return void
	 */
	public function setACL($acl)
	{
		if (is_array($acl))
			$this->acl = $this->cannedACL($acl);
		else
			$this->acl = $acl;
	}

	/**
	 * Deletes the node and removes it from it's parent's children collection
	 *
	 * @return void
	 */
	public function delete()
	{
		if ($this->parent)
			$this->parent->removeChild($this->name);
	}
}
