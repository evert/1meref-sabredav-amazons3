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
abstract class Sabre_DAV_S3_Node implements Sabre_DAV_INode
{
	/**
	 * The Amazon S3 bucket holding objects
	 *
	 * @var string
	 */
	protected $bucket = null;

	/**
	 * The object name to the current node as stored in S3
	 * Includes the trailing "/" at the end of directories
	 *
	 * @var string
	 */
	protected $object = null;

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
	 * Indicates if the full set of metadata including ACL has been requested
	 *
	 * @var bool
	 */
	protected $metadata_requested = false;

	/**
	 * Size of the object
	 *
	 * @var int
	 */
	protected $size = null;

	/**
	 * ETag according to RFC 2616 with surrounding double quotes (")
	 *
	 * @var string
	 */
	protected $etag = null;

	/**
	 * The object's MIME-Type
	 *
	 * @var string
	 */
	protected $contenttype = null;

	/**
	 * Last modification time, if available
	 *
	 * @var int
	 */
	protected $lastmodified = null;

	/**
	 * S3 redundancy StorageClass used
	 *
	 * @var int
	 */
	protected $storageclass = null;

	/**
	 * The object's owner
	 * Associative array with 'ID' and 'DisplayName'
	 *
	 * @var array
	 */
	protected $owner;

	/**
	 * The object's Access Control List (ACL)
	 * String allowed values: [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL]
	 *
	 * @var string
	 */
	protected $acl = null;

	/**
	 * Sets up the node, expects a full object name or null in case of the bucket itself
	 * If $parent is not given, a bucket name and a S3 instance or Amazon credentials have to be given
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_Directory $parent
	 * @param string $bucket
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($object = null, Sabre_DAV_S3_Directory $parent = null, $bucket = null, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = AmazonS3::REGION_US_E1, $use_ssl = true)
	{
		if ($object === '')
			$object = null;
		$this->object = $object;

		if (isset($parent))
		{
			$this->parent = $parent;
			$this->bucket = $parent->getBucket();
			$this->s3 = $parent->getS3();
		}

		if (isset($bucket))
			$this->bucket = $bucket;

		if (isset($s3))
			$this->s3 = $s3;

		if (isset($key) && isset($secret_key))
		{
			$this->s3 = new AmazonS3($key, $secret_key);
			$this->s3->set_region($region);
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
			return AmazonS3::ACL_PRIVATE;

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
	 * Retrieve the object's metadata from all possible sources (list, head, acl)
	 *
	 * @param bool $force
	 * @return void
	 */
	public function requestMetaData($force = false)
	{
		if (!$force && $this->metadata_requested)
			return;

		$data = $this->s3->get_object_metadata
		(
			$this->bucket,
			$this->object
		);
		if (!$data)
			throw new Sabre_DAV_S3_Exception('S3 object metadata retrieve failed');
		
		if (isset($data['LastModified']))
		{
			$dt = new DateTime($data['LastModified']);
			$this->setLastModified($dt->getTimestamp());
		}
		if (isset($data['ContentLength']))
			$this->setSize((int)$data['ContentLength']);
		if (isset($data['ETag']))
			$this->setETag($data['ETag']);
		if (isset($data['ContentType']))
			$this->setContentType($data['ContentType']);
		if (isset($data['StorageClass']))
			$this->setStorageClass($data['StorageClass']);
		if (!empty($data['Owner']))
			$this->setOwner($data['Owner']);
		if (!empty($data['ACL']))
			$this->setACL($data['ACL']);

		$this->metadata_requested = true;
	}

	/**
	 * Returns the node's name
	 *
	 * @return string
	 */
	public function getName()
	{
		list(, $name) = Sabre_DAV_URLUtil::splitPath(rtrim($this->object, '/'));
		return $name;
	}

	/**
	 * Sets the node's name. Renames the object in S3
	 *
	 * @param string $name The new name
	 * @throws Sabre_DAV_Exception_MethodNotAllowed, Sabre_DAV_Exception_NotImplemented, Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function setName($name)
	{
		$name = rtrim($name, '/');

		list($parentPath, ) = Sabre_DAV_URLUtil::splitPath(rtrim($this->object, '/'));
		list(, $newName) = Sabre_DAV_URLUtil::splitPath($name);

		$newObject = ($parentPath !== '' ? $parentPath . '/' : '') . $newName;
		if ($this instanceof Sabre_DAV_S3_Directory)
			$newObject .= '/';

		//request storage and acl before content-type to provoke a requestMetaData if nessecary. Content-Type is set to an empty string, not null, in File constructor
		$storage = $this->getStorageClass();
		$acl = $this->getACL();
		$contenttype = $this->getContentType();

		$response = $this->s3->copy_object
		(
			array
			(
				'bucket' => $this->bucket,
				'filename' => $this->object
			),
			array
			(
				'bucket' => $this->bucket,
				'filename' => $newObject
			),
			array
			(
				'headers' => array
				(
					'Content-Type' => $contenttype
				),
				'storage' => $storage,
				'acl' => $acl
			)
		);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object (Copy) failed', $response);

		$response = $this->s3->delete_object
		(
			$this->bucket,
			$this->object
		);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Object (Copy) failed', $response);

		$oldName = $this->getName();
		$this->object = $newObject;
		if ($this->parent)
		{
			$this->parent->removeChild($oldName);
			$this->parent->addChild($this);
		}
	}

	/**
	 * Deletes the node and removes it from it's parent's children collection
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		if ($this->parent)
			$this->parent->removeChild($this->getName());
	}

	/**
	 * Returns the node's bucket name
	 *
	 * @return string
	 */
	public function getBucket()
	{
		return $this->bucket;
	}

	/**
	 * Returns the node's object name within the Amazon bucket
	 * 
	 * @return string
	 */
	public function getObject()
	{
		return $this->object;
	}

	/**
	 * Returns the node's S3 instance
	 *
	 * @return AmazonS3
	 */
	public function getS3()
	{
		return $this->s3;
	}

	/**
	 * Returns the node's parent
	 *
	 * @return Sabre_DAV_S3_Directory
	 */
	public function getParent()
	{
		return $this->parent;
	}

	/**
	 * Sets this node's parent
	 * 
	 * @param Sabre_DAV_S3_Directory $node
	 * @return void
	 */
	public function setParent(Sabre_DAV_S3_Directory $node)
	{
		$this->parent = $node;
	}

	/**
	 * Returns the object's last modification time
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
	 * Sets the object's last modification time
	 *
	 * @param int $lastmodified Unix timestamp
	 * @return void
	 */
	public function setLastModified($lastmodified)
	{
		$this->lastmodified = $lastmodified;
	}

	/**
	 * Returns the object's size
	 *
	 * @return int
	 */
	public function getSize()
	{
		if (!isset($this->size))
			$this->requestMetaData();

		return $this->size;
	}

	/**
	 * Sets the object's size
	 *
	 * @param int $size
	 * @return void
	 */
	public function setSize($size)
	{
		$this->size = $size;
	}

	/**
	 * Returns the object's MIME-Type
	 *
	 * @return string
	 */
	public function getContentType()
	{
		if (!isset($this->contenttype))
			$this->requestMetaData();

		return $this->contenttype;
	}

	/**
	 * Sets the object's MIME-Type
	 *
	 * @param $contenttype
	 * @return void
	 */
	public function setContentType($contenttype)
	{
		$this->contenttype = $contenttype;
	}

	/**
	 * Returns the object's ETag
	 * An ETag is a unique identifier representing the current version of the file. If the file changes, the ETag MUST change.
	 * ETags are according to RFC 2616 with surrounding double quotes (")
	 *
	 * @return string
	 */
	public function getETag()
	{
		if (!isset($this->etag))
			$this->requestMetaData();

		return $this->etag;
	}

	/**
	 * Sets the object's ETag
	 *
	 * @param $etag
	 * @return void
	 */
	public function setETag($etag)
	{
		$this->etag = $etag;
	}

	/**
	 * Gets the object's StorageClass
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
	 * Sets the object's StorageClass
	 *
	 * @param string $storageclass
	 * @return void
	 */
	public function setStorageClass($storageclass)
	{
		$this->storageclass = $storageclass;
	}

	/**
	 * Gets the object's owner
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
	 * Sets the object's owner
	 *
	 * @param array $owner
	 */
	public function setOwner($owner)
	{
		$this->owner = $owner;
	}

	/**
	 * Gets the object's canned ACL
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
	 * Sets the object's canned ACL
	 *
	 * @param string | array $acl Allowed values: [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL] or an array of associative arrays with keys 'id' and 'permission'
	 * @return void
	 */
	public function setACL($acl)
	{
		if (is_array($acl))
			$this->acl = $this->cannedACL($acl);
		else
			$this->acl = $acl;
	}
}
