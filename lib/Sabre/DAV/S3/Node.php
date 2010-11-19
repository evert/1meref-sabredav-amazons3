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
abstract class Sabre_DAV_S3_Node extends Sabre_DAV_S3_Persistable implements Sabre_DAV_S3_INode
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
	 * @var Sabre_DAV_S3_ICollection
	 */
	protected $parent = null;

	/**
	 * The node's parent node's ID
	 *
	 * @var string
	 */
	protected $parent_oid = null;

	/**
	 * This node's Amazon S3 SDK instance for API calls
	 *
	 * @var AmazonS3
	 */
	private $s3 = null;

	/**
	 * The default Amazon S3 SDK instance for API calls
	 *
	 * @var AmazonS3
	 */
	private static $default_s3 = null;

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
	 *
	 * @param string $name The node's display name returned by getName()
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @return void
	 */
	public function __construct($name, Sabre_DAV_S3_ICollection $parent = null)
	{
		$this->name = $name;

		if (isset($parent))
			$this->setParent($parent);
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
	public function requestMetaData($force = false)
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
	 * Returns the node's parent
	 *
	 * @return Sabre_DAV_S3_ICollection
	 */
	public function getParent()
	{
		if (!isset($this->parent) && isset($this->parent_oid) && $this->getEntityManager())
		{
			$node = $this->getEntityManager()->find($this->parent_oid);
			if ($node)
				$this->setParent($node);
			else
			{
				$this->parent_oid = null;
				$this->markDirty();
			}
		}

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

		$oid = $node->getOID();
		if ($this->parent_oid !== $oid)
		{
			$this->parent_oid = $node->getOID();
			$this->markDirty();
		}
	}

	/**
	 * Returns the node's S3 instance
	 *
	 * @return AmazonS3
	 */
	public final function getS3()
	{
		if (isset($this->s3))
			return $this->s3;

		if (isset($this->parent))
			return $this->parent->getS3();

		$class = __CLASS__;
		return $class::$default_s3;
	}

	/**
	 * Sets the node's S3 instance
	 *
	 * @param AmazonS3 $s3
	 * @return void
	 */
	public final function setS3(AmazonS3 $s3 = null)
	{
		$this->s3 = $s3;

		$class = __CLASS__;
		if (isset($s3) && !isset($class::$default_s3))
			$class::$default_s3 = $s3;
	}

	/**
	 * Sets the default S3 instance
	 *
	 * @param AmazonS3 $s3
	 * @return void
	 */
	public static final function setDefaultS3(AmazonS3 $s3)
	{
		$class = __CLASS__;
		if (isset($s3))
			$class::$default_s3 = $s3;
	}

	/**
	 * Returns the node's Key
	 *
	 * @return string
	 */
	public function getKey()
	{
		return array('name' => $this->name);
	}

	/**
	 * Returns the property names to persist in a two dimensional array with the first array key being __CLASS__ and the second array a list of property names for that class.
	 * Every subclass with new properties to persist has to overwrite this function and return the merged array with it's parent class
	 *
	 * @return array
	 */
	public function getPersistentProperties()
	{
		return array_merge(parent::getPersistentProperties(), array(__CLASS__ => array('name', 'parent_oid', 'metadata_requested', 'lastmodified', 'storageclass', 'owner', 'acl')));
	}

	/**
	 * Gets called just after the Object was refreshed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterRefresh(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		if (isset($this->parent) && $this->parent->getOID() !== $this->parent_oid)
		{
			$this->parent_oid = $this->parent->getOID();
			$this->markDirty();
		}

		return true;
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
		if ($this->lastmodified !== $lastmodified)
		{
			$this->lastmodified = $lastmodified;
			$this->markDirty();
		}
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
		if ($this->storageclass !== $storageclass)
		{
			$this->storageclass = $storageclass;
			$this->markDirty();
		}
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
		if ($this->owner !== $owner)
		{
			$this->owner = $owner;
			$this->markDirty();
		}
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
			$acl = $this->cannedACL($acl);

		if ($this->acl !== $acl)
		{
			$this->acl = $acl;
			$this->markDirty();
		}
	}

	/**
	 * Deletes the node and removes it from it's parent's children collection
	 *
	 * @return void
	 */
	public function delete()
	{
		$this->getParent()->removeChild($this->name);
		$this->remove();
	}
}
