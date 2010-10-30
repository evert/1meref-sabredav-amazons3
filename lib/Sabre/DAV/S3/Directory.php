<?php

/**
 * Directory class for virtual directories within a S3 bucket
 * Directories are simulated by "/" in object names
 * Directories preferably have an empty object for inheritance purposes
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Directory extends Sabre_DAV_S3_Object implements Sabre_DAV_S3_ICollection
{
	/**
	 * The node's child nodes
	 *
	 * @var Sabre_DAV_S3_INode[]
	 */
	protected $children = array();

	/**
	 * Did we populate the list of children from S3?
	 * 
	 * @var bool
	 */
	protected $children_requested = false;

	/**
	 * Sets up the node as a virtual directory, expects a full Object name
	 * If $parent is not given, a Bucket name and a S3 instance or Amazon credentials have to be given
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param string $bucket
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($object, Sabre_DAV_S3_ICollection $parent = null, $bucket = null, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = null, $use_ssl = null)
	{
		if (isset($object) && $object !== '')
			$object = rtrim($object, '/') . '/';

		parent::__construct($object, $parent, $bucket, $s3, $key, $secret_key, $region, $use_ssl);

		$this->setLastModified(0);
		$this->setSize(0);
		$this->setETag('');
		$this->setContentType('');
	}

	/**
	 * Renames the virtual folder
	 * Virtual folders can only be renamed while empty.
	 * We would have to rename (copy & delete) every object in the bucket with the same prefix separately
	 *
	 * @param string $name The new name
	 * @throws Sabre_DAV_Exception_NotImplemented
	 * @return void
	 * @todo Allow renaming of folders with children... could take a while though, even with batch processing!
	 */
	public function setName($name)
	{
		if ($this->getChildren())
			throw new Sabre_DAV_Exception_NotImplemented('S3 virtual folders can not be renamed unless empty');

		parent::setName($name);
	}

	/**
	 * Creates a new File/Object in the virtual folder
	 *
	 * @param string $name Name of the object within this virtual folder
	 * @param resource $data Initial payload
	 * @param int $size Stream size of $data
	 * @param string $type MIME-Type (application/octet-stream by default)
	 * @throws Sabre_DAV_Exception_BadRequest, Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function createFile($name, $data = null, $size = null, $type = null)
	{
		$newObject = $this->object . $name;

		$node = new Sabre_DAV_S3_File($newObject, $this);
		$node->setStorageClass($this->getStorageClass());
		$node->setACL($this->getACL());

		$node->put($data, $size, $type);

		$this->addChild($node);
	}

	/**
	 * Creates a new virtual subfolder
	 *
	 * @param string $name Name of the virtual folder within this virtual folder
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function createDirectory($name)
	{
		$newObject = $this->object . $name . '/';

		$response = $this->getS3()->create_object
		(
			$this->bucket,
			$newObject,
			array
			(
				'body' => '',
				'storage' => $this->getStorageClass(),
				'acl' => $this->getACL()
			)
		);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object failed', $response);

		$node = new Sabre_DAV_S3_Directory($newObject, $this);
		$node->setStorageClass($this->getStorageClass());
		$node->setACL($this->getACL());

		$this->addChild($node);
	}

	/**
	 * Updates the children collection from S3
	 * 
	 * @return void
	 */
	protected function requestChildren()
	{
		$nodes = array();

		$response = $this->getS3()->list_objects
		(
			$this->bucket,
			array
			(
				'prefix' => $this->object,
				'delimiter' => '/'
			)
		);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 GET Bucket failed', $response);

		if ($response->body)
		{
			if ($response->body->CommonPrefixes)
			{
				foreach ($response->body->CommonPrefixes as $folder)
				{
					$node = new Sabre_DAV_S3_Directory((string)$folder->Prefix, $this);
					$nodes[$node->getName()] = $node;
				}
			}

			if ($response->body->Contents)
			{
				foreach ($response->body->Contents as $file)
				{
					$lastmodified = null;
					if (isset($file->LastModified))
					{
						$dt = new DateTime((string)$file->LastModified);
						$lastmodified = $dt->getTimestamp();
					}

					$size = null;
					if (isset($file->Size))
						$size = (int)$file->Size;

					$etag = null;
					if (isset($file->ETag))
						$etag = (string)$file->ETag;

					$owner = null;
					if (isset($file->Owner))
					{
						$owner = array();
						$owner['ID'] = (string)$file->Owner->ID;
						$owner['DisplayName'] = (string)$file->Owner->DisplayName;
					}

					if (substr($file->Key, -1, 1) === '/') //This virtual folder. Usually an empty object, if present. But it could also hold data like any other object... not accessible here!
					{
						$this->setLastModified($lastmodified);
						$this->setSize($size);
						$this->setETag($etag);
						$this->setOwner($owner);
					}
					else
					{
						$node = new Sabre_DAV_S3_File((string)$file->Key, $this);
						if (!array_key_exists($node->getName(), $nodes)) //just skip files with the same name as a folder (file "somefilefolder" and folder "somefilefolder/")... not accessible here!
						{
							$node->setLastModified($lastmodified);
							$node->setSize($size);
							$node->setETag($etag);
							$node->setOwner($owner);
							$nodes[$node->getName()] = $node;
						}
					}
				}
			}
		}

		$this->children = $nodes;
		$this->children_requested = true;
	}

	/**
	 * Returns an array with all the child nodes
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_INode[]
	 */
	public function getChildren()
	{
		if (!$this->children_requested)
			$this->requestChildren();

		return $this->children;
	}

	/**
	 * Checks if a child exists
	 *
	 * @param string $name
	 * @return bool
	 */
	public function childExists($name)
	{
		return array_key_exists($name, $this->getChildren());
	}

	/**
	 * Returns a specific child node by it's name
	 *
	 * @param string $name
	 * @throws Sabre_DAV_Exception_FileNotFound
	 * @return Sabre_DAV_INode
	 */
	public function getChild($name)
	{
		if (!$this->childExists($name))
			throw new Sabre_DAV_Exception_FileNotFound('S3 Object not found');

		return $this->children[$name];
	}

	/**
	 * Add a child to the children collection
	 * 
	 * @param Sabre_DAV_S3_INode $node
	 * @return void
	 */
	public function addChild(Sabre_DAV_S3_INode $node)
	{
		$this->children[$node->getName()] = $node;
	}

	/**
	 * Removes the child specified by it's name from the children collection
	 * 
	 * @param string $name
	 * @return void
	 */
	public function removeChild($name)
	{
		unset($this->children[$name]);
	}

	/**
	 * Deletes all objects in this virtual folder and itself
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->getS3()->delete_all_objects
		(
			$this->bucket,
			'/^' . preg_quote($this->object, '/') . '.*$/'
		);
		if (!$response)
			throw new Sabre_DAV_S3_Exception('S3 DELETE Objects failed');

		$this->children = array();
		parent::delete();
	}
}
