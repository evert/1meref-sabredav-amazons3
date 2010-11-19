<?php

/**
 * Base class for S3 objects
 * The Object class implements the methods used by both the File and the Directory class for S3 Objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_DAV_S3_Object extends Sabre_DAV_S3_Node
{
	/**
	 * The Amazon S3 Bucket holding objects
	 *
	 * @var string
	 */
	protected $bucket = null;

	/**
	 * The Object name to the current node as stored in S3
	 * Includes the trailing "/" at the end of directories
	 *
	 * @var string
	 */
	protected $object = null;

	/**
	 * Size of the Object
	 *
	 * @var int
	 */
	protected $size = null;

	/**
	 * The Object's ETag. As to RFC 2616 with surrounding double-quotes (")
	 *
	 * @var string
	 */
	protected $etag = null;

	/**
	 * The Object's MIME-Type
	 *
	 * @var string
	 */
	protected $contenttype = null;

	/**
	 * Sets up the node, expects a full Object name or null in case of the Bucket itself
	 * If $parent is not given a bucket name must be supplied
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param string $bucket
	 * @return void
	 */
	public function __construct($object, Sabre_DAV_S3_ICollection $parent = null, $bucket = null)
	{
		$this->object = $object !== '' ? $object : null;
		;

		$name = null;
		if (isset($this->object))
			list(, $name) = Sabre_DAV_URLUtil::splitPath(rtrim($this->object, '/'));
		else
			$name = $bucket;

		parent::__construct($name, $parent);

		if (isset($bucket))
			$this->bucket = $bucket;
	}

	/**
	 * Retrieve the node's metadata from all possible sources
	 * Use the specific getter method to read individual results (lastmodified, owner, acl, ...)
	 *
	 * @param bool $force
	 * @return void
	 */
	public function requestMetaData($force = false)
	{
		if (!$force && $this->metadata_requested)
			return;

		$data = $this->getS3()->get_object_metadata($this->bucket, $this->object);
		if (!$data)
			throw new Sabre_DAV_S3_Exception('S3 Object metadata retrieve failed', 404);

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
	 * Returns the node's Key
	 *
	 * @return string
	 */
	public function getKey()
	{
		return array('bucket' => $this->bucket, 'object' => $this->object);
	}

	/**
	 * Returns the property names to persist in a two dimensional array with the first array key being __CLASS__ and the second array a list of property names for that class.
	 * Every subclass with new properties to persist has to overwrite this function and return the merged array with it's parent class
	 *
	 * @return array
	 */
	public function getPersistentProperties()
	{
		return array_merge(parent::getPersistentProperties(), array(__CLASS__ => array('bucket', 'object', 'size', 'etag', 'contenttype')));
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
		list($parentPath, ) = Sabre_DAV_URLUtil::splitPath(rtrim($this->object, '/'));
		list(, $newName) = Sabre_DAV_URLUtil::splitPath($name);
		$parentPath = isset($parentPath) ? $parentPath : '';
		$newName = isset($newName) ? $newName : '';

		$newObject = ($parentPath !== '' ? ($parentPath . '/') : '') . $newName;
		if ($this instanceof Sabre_DAV_S3_ICollection)
			$newObject .= '/';

		//Request Storage Redundancy and ACL before Content-Type to provoke a requestMetaData() if nessecary. Content-Type is set to an empty string, not null, in File constructor
		$storage = $this->getStorageClass();
		$acl = $this->getACL();
		$contenttype = $this->getContentType();

		$response = $this->getS3()->copy_object(array('bucket' => $this->bucket, 'filename' => $this->object), array('bucket' => $this->bucket, 'filename' => $newObject), array('headers' => array('Content-Type' => $contenttype), 'storage' => $storage, 'acl' => $acl));
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object (Copy) failed', $response);

		$response = $this->getS3()->delete_object($this->bucket, $this->object);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Object (Copy) failed', $response);

		$this->remove();
		$this->detach();

		$this->getParent()->removeChild($this->name);
		$this->object = $newObject;

		$this->name = $name;
		$this->oid = null;

		$this->markDirty();
		$this->persist();

		$this->getParent()->addChild($this);
	}

	/**
	 * Sets this node's parent
	 *
	 * @param Sabre_DAV_S3_ICollection $node
	 * @return void
	 */
	public function setParent(Sabre_DAV_S3_ICollection $node)
	{
		parent::setParent($node);

		if (!isset($this->bucket) && isset($node) && $node instanceof Sabre_DAV_S3_Object)
		{
			$this->bucket = $node->getBucket();
			$this->markDirty();
		}
	}

	/**
	 * Returns the node's Bucket name
	 *
	 * @return string
	 */
	public function getBucket()
	{
		return $this->bucket;
	}

	/**
	 * Returns the node's Object name within the Amazon Bucket
	 *
	 * @return string
	 */
	public function getObject()
	{
		return $this->object;
	}

	/**
	 * Returns the Object's size
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
	 * Sets the Object's size
	 *
	 * @param int $size
	 * @return void
	 */
	public function setSize($size)
	{
		if ($this->size !== $size)
		{
			$this->size = $size;
			$this->markDirty();
		}
	}

	/**
	 * Returns the Object's ETag
	 * An ETag is a unique identifier representing the current version of the file. If the file changes, the ETag MUST change.
	 * As to RFC 2616 with surrounding double-quotes (")
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
	 * Sets the Object's ETag
	 *
	 * @param $etag
	 * @return void
	 */
	public function setETag($etag)
	{
		if ($this->etag !== $etag)
		{
			$this->etag = $etag;
			$this->markDirty();
		}
	}

	/**
	 * Returns the Object's MIME-Type
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
	 * Sets the Object's MIME-Type
	 *
	 * @param $contenttype
	 * @return void
	 */
	public function setContentType($contenttype)
	{
		if ($this->contenttype !== $contenttype)
		{
			$this->contenttype = $contenttype;
			$this->markDirty();
		}
	}
}
