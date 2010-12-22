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
	 * The node's child nodes' ID
	 *
	 * @var string[]
	 */
	protected $children_oid = array();

	/**
	 * Did we populate the list of children from S3?
	 *
	 * @var bool
	 */
	protected $children_requested = false;

	/**
	 * Sets up the node as a virtual directory, expects a full Object name
	 * If $parent is not given a bucket name must be supplied
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param string $bucket
	 * @return void
	 */
	public function __construct($object, Sabre_DAV_S3_ICollection $parent = null, $bucket = null)
	{
		if (isset($object) && $object !== '')
			$object = rtrim($object, '/') . '/';

		parent::__construct($object, $parent, $bucket);

		$this->setLastModified(0);
		$this->setSize(0);
		$this->setETag('');
		$this->setContentType('');
	}

	/**
	 * Find the Object by Key or create a new Instance
	 * If $parent is not given a bucket name must be supplied
	 *
	 * @param array $key
	 * @param string $object
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param string $bucket
	 * @return Sabre_DAV_S3_INode
	 */
	public static function getInstanceByKey($key, $object, Sabre_DAV_S3_ICollection $parent = null, $bucket = null)
	{
		$object = Sabre_DAV_S3_Persistable::getInstanceByKey(__CLASS__, $key, $object, $parent, $bucket);

		if (isset($parent))
			$object->setParent($parent);

		return $object;
	}

	/**
	 * Returns the property names to persist in a two dimensional array with the first array key being __CLASS__ and the second array a list of property names for that class.
	 * Every subclass with new properties to persist has to overwrite this function and return the merged array with it's parent class
	 *
	 * @return array
	 */
	public function getPersistentProperties()
	{
		return array_merge(parent::getPersistentProperties(), array(__CLASS__ => array('children_oid', 'children_requested')));
	}

	/**
	 * Gets called just after the Object was refreshed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterRefresh(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		parent::_afterRefresh($entitymanager);
		$this->children = array();

		return true;
	}

	/**
	 * Retrieve the node's metadata from all possible sources
	 * Use the specific getter method to read individual results (lastmodified, owner, acl, ...)
	 *
	 * @param bool $force
	 * @return bool true if data was requested
	 */
	public function requestMetaData($force = false)
	{
		try
		{
			if (parent::requestMetaData($force))
			{
				if ($this->children_requested)
					$this->setLastUpdated();

				return true;
			}
			else
				return false;
		}
		catch (Sabre_DAV_S3_Exception $e)
		{
			if ($e->getHTTPCode() == 404)	// Ignore non existent folder objects (virtual folder)
			{
				if ($this->children_requested)
					$this->setLastUpdated();

				return true;
			}
			else
				throw $e;
		}
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
	 * Returns the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @return string
	 */
	public function getStorageClass()
	{
		$storageclass = parent::getStorageClass();
		if (!isset($storageclass))
		{
			$parent = $this->getParent();
			if (isset($parent))
				$storageclass = $parent->getStorageClass();
		}

		return $storageclass;
	}

	/**
	 * Returns the node's Canned ACL
	 *
	 * @return string [AmazonS3::ACL_PRIVATE, AmazonS3::ACL_PUBLIC, AmazonS3::ACL_OPEN, AmazonS3::ACL_AUTH_READ, AmazonS3::ACL_OWNER_READ, AmazonS3::ACL_OWNER_FULL_CONTROL]
	 */
	public function getACL()
	{
		$acl = parent::getACL();
		if (!isset($acl))
		{
			$parent = $this->getParent();
			if (isset($parent))
				$acl = $parent->getACL();
		}

		return $acl;
	}

	/**
	 * Creates a new File/Object in the virtual folder
	 *
	 * @param string $name Name of the object within this virtual folder
	 * @param resource $data Initial payload
	 * @param int $size Stream size of $data
	 * @param string $type MIME-Type (application/octet-stream by default)
	 * @throws Sabre_DAV_Exception_BadRequest, Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_S3_File
	 */
	public function createFile($name, $data = null, $size = null, $type = null)
	{
		$newObject = $this->object . $name;

		$node = new Sabre_DAV_S3_File($newObject, $this);
		$node->persist();

		$node->setStorageClass($this->getStorageClass());
		$node->setACL($this->getACL());

		$node->put($data, $size, $type);

		$this->addChild($node);

		return $node;
	}

	/**
	 * Creates a new virtual subfolder
	 *
	 * @param string $name Name of the virtual folder within this virtual folder
	 * @throws Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_S3_Directory
	 */
	public function createDirectory($name)
	{
		$newObject = $this->object . $name . '/';

		$response = $this->getS3()->create_object($this->bucket, $newObject, array('body' => '', 'storage' => $this->getStorageClass(), 'acl' => $this->getACL()));
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object failed', $response);

		$node = new Sabre_DAV_S3_Directory($newObject, $this);
		$node->persist();

		$node->setStorageClass($this->getStorageClass());
		$node->setACL($this->getACL());

		$this->addChild($node);

		return $node;
	}

	/**
	 * Updates the children collection from S3
	 *
	 * @param bool $fulltree If true, all subdirectories will also be parsed, only the current path otherwise
	 * @return bool true if data was requested
	 */
	public function requestChildren($fulltree = false)
	{
		$nodes = array();

		$response = $this->getS3()->list_objects($this->bucket, array('prefix' => $this->object, 'delimiter' => ($fulltree ? '' : '/')));
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 GET Bucket failed', $response);

		$dirtystate = $this->isDirty();
		$children_old = $this->children_oid;
		sort($children_old);
		$this->children = array();
		$this->children_oid = array();

		if ($response->body)
		{
			if ($response->body->CommonPrefixes)
			{
				foreach ($response->body->CommonPrefixes as $folder)
				{
					$prefix = (string)$folder->Prefix;
					$node = Sabre_DAV_S3_Directory::getInstanceByKey(array('bucket' => $this->bucket, 'object' => $prefix), $prefix, $this);
					$this->addChild($node);
					$nodes[$prefix] = $node;
				}
			}

			if ($response->body->Contents)
			{
				foreach ($response->body->Contents as $object)
				{
					$lastmodified = null;
					if (isset($object->LastModified))
						$lastmodified = strtotime((string)$object->LastModified);

					$size = null;
					if (isset($object->Size))
						$size = (int)$object->Size;

					$etag = null;
					if (isset($object->ETag))
						$etag = (string)$object->ETag;

					$owner = null;
					if (isset($object->Owner))
					{
						$owner = array();
						$owner['ID'] = (string)$object->Owner->ID;
						$owner['DisplayName'] = (string)$object->Owner->DisplayName;
					}

					if ((string)$object->Key == $this->object) //This virtual folder. Usually an empty object, if present. But it could also hold data like any other object... not accessible here!
					{
						$this->setLastModified($lastmodified);
						$this->setSize($size);
						$this->setETag($etag);
						$this->setOwner($owner);
						$object_self = true;
					}
					else
					{
						$subkey = substr((string)$object->Key, strlen($this->object)); //strip common path prefix
						$subkeyparts = null;
						preg_match_all('/.*?(?:\/|$)/', $subkey, $subkeyparts);
						$subkeyparts = $subkeyparts[0];
						array_pop($subkeyparts); //remove last empty element so we can use .*? instead of .+? in pattern


						$subkey = '';
						$parent = null;
						foreach ($subkeyparts as $keypart) //heavyly relies on the list being sorted!
						{
							if ($parent === null)
								$parent = $this;
							else
							{
								if (array_key_exists($subkey, $nodes))
									$parent = $nodes[$subkey];
								else	// virtual folder without folder object, so create one
								{
									$node = Sabre_DAV_S3_Directory::getInstanceByKey(array('bucket' => $parent->getBucket(), 'object' => $this->object . $subkey), $this->object . $subkey, $parent);
									$parent->addChild($node);
									$nodes[$subkey] = $node;
									$parent = $node;
								}
							}

							$subkey .= $keypart;
							if (substr($subkey, -1, 1) === '/')
								$node = Sabre_DAV_S3_Directory::getInstanceByKey(array('bucket' => $parent->getBucket(), 'object' => $this->object . $subkey), $this->object . $subkey, $parent);
							elseif (!array_key_exists($subkey . '/', $nodes)) //just skip files with the same name as a folder (file "somefilefolder" and folder "somefilefolder/")... not accessible here!
								$node = Sabre_DAV_S3_File::getInstanceByKey(array('bucket' => $parent->getBucket(), 'object' => $this->object . $subkey), $this->object . $subkey, $parent);
							else
								$node = null;

							if (isset($node))
							{
								$node->setLastModified($lastmodified);
								$node->setSize($size);
								$node->setETag($etag);
								$node->setOwner($owner);
								$this->addChild($node);
								$nodes[$subkey] = $node;
							}
						}
					}
				}
			}
		}

		$children_new = $this->children_oid;
		sort($children_new);
		$this->markDirty($dirtystate || $children_new !== $children_old);

		$this->children_requested = true;
		if ($this->metadata_requested)
			$this->setLastUpdated();

		return true;
	}

	/**
	 * Returns an array with all the child nodes
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_INode[]
	 */
	public function getChildren()
	{
		if (empty($this->children) && $this->getEntityManager() && !empty($this->children_oid))
		{
			$dirtystate = $this->isDirty();

			foreach ($this->children_oid as $child_oid)
			{
				$node = $this->getEntityManager()->find($child_oid);
				if ($node)
					$this->addChild($node);
				else
				{
					$this->children = array();
					$this->children_oid = array();
					$this->children_requested = false;
					$dirtystate = true;
					break;
				}
			}

			$this->markDirty($dirtystate);
		}

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
	 * @return Sabre_DAV_S3_INode
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
		$oid = $node->getOID();
		if (!in_array($oid, $this->children_oid))
		{
			array_push($this->children_oid, $oid);
			$this->markDirty();
		}
	}

	/**
	 * Removes the child specified by it's name from the children collection
	 *
	 * @param string $name
	 * @return void
	 */
	public function removeChild($name)
	{
		$node = $this->getChild($name);
		if ($node)
		{
			$oid = $node->getOID();
			$offset = array_search($oid, $this->children_oid);
			if ($offset !== false)
				array_splice($this->children_oid, $offset, 1);
			unset($this->children[$name]);
			$this->markDirty();
		}
	}

	/**
	 * Deletes all objects in this virtual folder and itself
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->getS3()->delete_all_objects($this->bucket, '/^' . preg_quote($this->object, '/') . '.*$/');
		if (!$response)
			throw new Sabre_DAV_S3_Exception('S3 DELETE Objects failed');

		$this->getParent()->removeChild($this->name);
		$this->remove();
	}
}
