<?php

/**
 * Directory class for virtual directories within a S3 bucket
 * Directories are simulated by "/" in object names
 * Directories preferably have an empty object for inheritance purposes
 *
 * @package Sabre
 * @subpackage DAV
 * @copyright Copyright (C) 2010 Paul Voegler. All rights reserved.
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
	 * Creates a new object in the virtual directory
	 *
	 * @param string $name Name of the object within this virtual directory
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
		$node->put($data, $size, $type);
		$this->children[$node->getName()] = $node;
	}

	/**
	 * Creates a new subdirectory
	 *
	 * @param string $name Name of the virtual directory within this virtual directory
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function createDirectory($name)
	{
		$name = rtrim($name, '/');
		$newObject = $this->object . $name . '/';
		$response = $this->s3->create_object($this->bucket, $newObject, array('body' => '', 'storage' => $this->getStorageClass()));
		if (!$response || !$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object failed', $response);
		$node = new Sabre_DAV_S3_Directory($newObject, $this);
		$node->setStorageClass($this->getStorageClass());
		$this->children[$node->getName()] = $node;
	}

	/**
	 * Returns a specific child node, referenced by its name
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
			return array_values($this->children);

		$nodes = array();
		$response = $this->s3->list_objects($this->bucket, array('prefix' => $this->object, 'delimiter' => '/'));
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 GET Bucket failed', $response);

		if ($response && $response->isOK() && $response->body)
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
				$lastmodified = 0;
				foreach ($response->body->Contents as $file)
				{
					$ts = 0;
					if ($file->LastModified)
					{
						$dt = new DateTime((string)$file->LastModified);
						$ts = $dt->getTimestamp();
						if ($ts > $lastmodified)
							$lastmodified = $ts;
					}
					if (substr($file->Key, -1, 1) == '/') //exclude the "directory" itself (usually an empty file, if present). But it could also hold data like any other file... not accessable here!
					{
						if ($file->StorageClass)
							$this->setStorageClass((string)$file->StorageClass);
					}
					else
					{
						$node = new Sabre_DAV_S3_File((string)$file->Key, $this);
						if (!array_key_exists($node->getName(), $nodes)) //just skip files with the same name as a directory (file "somefilefolder" and directory "somefilefolder/")... not accessable here!
						{
							$node->setLastModified($ts);
							if ($file->ContentType) //not (yet?) sent by Amazon
								$node->setContentType((string)$file->ContentType);
							if ($file->Size)
								$node->setSize((int)$file->Size);
							if ($file->ETag)
								$node->setETag((string)$file->ETag);
							$nodes[$node->getName()] = $node;
						}
					}
				}
				$this->setLastModified($lastmodified); //set own last modified time to the latest date we found
			}
		}
		
		$this->children = $nodes;
		return array_values($this->children);
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
		if (!isset($this->children))
			$this->getChildren();
		
		return array_key_exists($name, $this->children);
	}

	/**
	 * Deletes all objects in this virtual directory, and then itself
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		if ($this->object === '')
			$response = $this->s3->delete_bucket($this->bucket, true); //delete the whole bucket including versioning of files. Be careful, this cannot be undone!!! However, not possible to invoke when buckets are root nodes.
		else
			$response = $this->s3->delete_all_objects($this->bucket, '/^' . preg_quote($this->object, '/') . '.*$/');
		
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Object/Bucket failed', $response);
		
		$this->children = null;
	}
}
