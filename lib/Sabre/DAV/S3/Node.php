<?php

/**
 * Base node-class for S3 buckets
 * The node class implements the methods used by both the File and the Directory class
 *
 * @package Sabre
 * @subpackage DAV
 * @copyright Copyright (C) 2010 Paul Voegler. All rights reserved.
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 * @todo Handle ACL to inherit like StorageClass. Currently ACL is set to private by default. But a separate request has to be made to get the current ACL...
 */
abstract class Sabre_DAV_S3_Node implements Sabre_DAV_INode
{
	/**
	 * The Amazon S3 bucket for objects
	 *
	 * @var string
	 */
	protected $bucket = '';
	
	/**
	 * The object name to the current node as stored in S3
	 * Includes the trailing "/" at the end of directories
	 *
	 * @var string
	 */
	protected $object = '';
	
	/**
	 * This node's parent node
	 *
	 * @var Sabre_DAV_S3_Directory
	 */
	protected $parentnode = null;
	
	/**
	 * The Amazon S3 SDK instance for API calls
	 *
	 * @var AmazonS3
	 */
	protected $s3 = null;
	
	/**
	 * Last modification time, if available
	 *
	 * @var int
	 */
	protected $lastmodified = 0;
	
	/**
	 * S3 redundancy StorageClass used
	 *
	 * @var int
	 */
	protected $storageclass = '';

	/**
	 * Sets up the node, expects a full object name
	 * If parent is not given, a bucket name and Amazon credentials have to be given
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_Directory $parent
	 * @param string $bucket
	 * @param string $key
	 * @param string $secret_key
	 * @return void
	 */
	public function __construct($object = '', $parentnode = null, $bucket = null, $key = null, $secret_key = null, $use_ssl = true)
	{
		$object = rtrim($object, '/');
		if ($this instanceof Sabre_DAV_ICollection && $object !== '')
			$object .= '/';
		
		$this->object = $object;
		if ($parentnode)
			$this->parentnode = $parentnode;
		
		if ($this->parentnode && $this->parentnode instanceof Sabre_DAV_S3_Directory)
		{
			$this->bucket = $parentnode->getBucket();
			$this->s3 = $parentnode->getS3();
		}
		else
		{
			$this->bucket = $bucket;
			$this->s3 = new AmazonS3($key, $secret_key);
			if (!$use_ssl)
				$this->s3->disable_ssl();
		}
	}

	/**
	 * Returns the node's parent
	 *
	 * @return Sabre_DAV_S3_Directory
	 */
	public function getParent()
	{
		return $this->parentnode;
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
	 * Returns the node's S3 instance
	 *
	 * @return AmazonS3
	 */
	public function getS3()
	{
		return $this->s3;
	}

	/**
	 * Returns the node's name
	 *
	 * @return string
	 */
	public function getName()
	{
		if ($this->object === '') //this is a bucket (root) and not an object
		{
			return $this->bucket;
		}
		else
		{
			list(, $name) = Sabre_DAV_URLUtil::splitPath(rtrim($this->object, '/'));
			return $name;
		}
	}

	/**
	 * Renames the node
	 * Directories can only be renamed when empty. We would have to rename (copy & delete) every object in the bucket with the same prefix separately
	 *
	 * @param string $name The new name
	 * @throws Sabre_DAV_Exception_MethodNotAllowed, Sabre_DAV_Exception_NotImplemented, Sabre_DAV_S3_Exception
	 * @return void
	 * @todo allow renaming of directories with children... could take a while though, even with batch processing!
	 * @todo update parents list of children if present, otherwise no need. But this is an atomic call so far, so no need yet
	 */
	public function setName($name)
	{
		$name = rtrim($name, '/');
		if ($this->object === '')
			throw new Sabre_DAV_Exception_MethodNotAllowed('S3 buckets cannot be renamed');
		if ($this instanceof Sabre_DAV_ICollection && $this->getChildren())
			throw new Sabre_DAV_Exception_NotImplemented('S3 virtual folders can not be renamed unless empty');
		
		list($parentPath, ) = Sabre_DAV_URLUtil::splitPath(rtrim($this->object, '/'));
		list(, $newName) = Sabre_DAV_URLUtil::splitPath($name);
		
		$newObject = $parentPath;
		if ($parentPath !== '')
			$newObject .= '/';
		$newObject .= $newName;
		if ($this instanceof Sabre_DAV_S3_Directory)
			$newObject .= '/';
		
		$response = $this->s3->copy_object(array('bucket' => $this->bucket, 'filename' => $this->object), array('bucket' => $this->bucket, 'filename' => $newObject));
		if (!$response || !$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object (Copy) failed', $response);
		
		$response = $this->s3->delete_object($this->bucket, $this->object);
		if (!$response || !$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Object failed', $response);
		
		$this->object = $newObject;
	}

	/**
	 * Returns the last modification time, as a unix timestamp
	 *
	 * @return int
	 */
	public function getLastModified()
	{
		return $this->lastmodified;
	}

	/**
	 * Sets the last modification time, as a unix timestamp
	 *
	 * @param int $lastmodified
	 */
	public function setLastModified($lastmodified)
	{
		$this->lastmodified = $lastmodified;
	}

	/**
	 * Gets the StorageClass
	 *
	 * @return int
	 */
	public function getStorageClass()
	{
		return $this->storageclass;
	}

	/**
	 * Sets the StorageClass
	 *
	 * @param string $storageclass
	 */
	public function setStorageClass($storageclass)
	{
		$this->storageclass = $storageclass;
	}
}
