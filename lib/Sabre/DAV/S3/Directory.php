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
class Sabre_DAV_S3_Directory extends Sabre_DAV_S3_Node implements Sabre_DAV_ICollection
{
	/**
	 * The node's child nodes
	 *
	 * @var Sabre_DAV_S3_Node[]
	 */
	protected $children = null;

	/**
	 * Sets up the virtual directory, expects a full object name
	 * If $parentnode is not given, a bucket name and a S3 instance or Amazon credentials have to be given
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_Directory $parentnode
	 * @param string $bucket
	 * @param string $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($object = null, $parentnode = null, $bucket = null, $s3 = null, $key = null, $secret_key = null, $use_ssl = true)
	{
		if (isset($object))
			$object = rtrim($object, '/') . '/';

		parent::__construct($object, $parentnode, $bucket, $s3, $key, $secret_key, $use_ssl);

		$this->setLastModified(0);
		$this->setSize(0);
		$this->setContentType('');
		$this->setETag('');
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
	 * Creates a new object in the virtual folder
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
		$name = rtrim($name, '/');
		$newObject = $this->object . $name;

		$node = new Sabre_DAV_S3_File($newObject, $this);
		$node->setStorageClass($this->getStorageClass());
		$node->setACL($this->getACL());

		$node->put($data, $size, $type);

		$this->children[$node->getName()] = $node;
	}

	/**
	 * Creates a new subfolder
	 *
	 * @param string $name Name of the virtual folder within this virtual folder
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function createDirectory($name)
	{
		$name = rtrim($name, '/');
		$newObject = $this->object . $name . '/';

		$response = $this->s3->create_object
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

		$this->children[$node->getName()] = $node;
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
		$name = rtrim($name, '/');

		if (!$this->childExists($name))
			throw new Sabre_DAV_Exception_FileNotFound('S3 Object not found');

		return $this->children[$name];
	}

	/**
	 * Returns an array with all the child nodes
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_INode[]
	 */
	public function getChildren()
	{
		if (isset($this->children))
			return $this->children;

		$nodes = array();

		$response = $this->s3->list_objects
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
		return $this->children;
	}

	/**
	 * Checks if a child exists.
	 *
	 * @param string $name
	 * @return bool
	 */
	public function childExists($name)
	{
		$name = rtrim($name, '/');

		return array_key_exists($name, $this->getChildren());
	}

	/**
	 * Deletes all objects in this virtual folder and itself
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->s3->delete_all_objects
		(
			$this->bucket,
			'/^' . preg_quote($this->object, '/') . '.*$/'
		);
		if (!$response)
			throw new Sabre_DAV_S3_Exception('S3 DELETE Objects failed');

		$this->children = null;
	}
}
