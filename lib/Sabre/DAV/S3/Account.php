<?php

/**
 * Account class for S3 accounts
 * Either lists all buckets in an account or just predefined buckets
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Account extends Sabre_DAV_S3_Node implements Sabre_DAV_S3_ICollection
{
	/**
	 * The Account's Buckets collection
	 *
	 * @var Sabre_DAV_S3_Bucket[]
	 */
	protected $children = array();

	/**
	 * The Account's Buckets' Object ID
	 *
	 * @var string[]
	 */
	protected $children_oid = array();

	/**
	 * Did we populate the list of Buckets from S3?
	 *
	 * @var bool
	 */
	protected $children_requested = false;

	/**
	 * Did we get a list of buckets in the constructor?
	 *
	 * @var bool
	 */
	protected $children_supplied = false;

	/**
	 * Can we create or delete buckets? Defaults to true if a list of Buckets is given rather than requested from Amazon
	 *
	 * @var bool
	 */
	protected $readonly = false;

	/**
	 * Sets up the account
	 * A S3 instance or Amazon credentials ($key, $secret_key) have to be given
	 *
	 * @param Sabre_DAV_S3_Bucket[]|string[] $buckets Associative array of Class Bucket with Bucket names as the key or an array of Bucket names within the Account. Set to null to query all Buckets within the S3 Account
	 * @return void
	 */
	public function __construct($buckets = null)
	{
		parent::__construct('AmazonS3', null);

		if (isset($buckets))
		{
			foreach ($buckets as $bucket)
			{
				if (is_string($bucket))
					$bucket = Sabre_DAV_S3_Bucket::getInstanceByKey(array('bucket' => $bucket), $bucket, $this);
				else
				{
					$bucket->modernize();
					$bucket->persist(true);
				}

				$this->addChild($bucket);
			}

			$this->children_supplied = true;
			$this->children_requested = true;
			$this->readonly = true;
		}
	}

	/**
	 * Returns the node's Key
	 *
	 * @return string
	 */
	public function getKey()
	{
		return array('s3key' => $this->getS3()->key);
	}

	/**
	 * Returns the property names to persist in a two dimensional array with the first array key being __CLASS__ and the second array a list of property names for that class.
	 * Every subclass with new properties to persist has to overwrite this function and return the merged array with it's parent class
	 *
	 * @return array
	 */
	public function getPersistentProperties()
	{
		return array_merge(parent::getPersistentProperties(), array(__CLASS__ => array('children_oid', 'children_requested', 'readonly')));
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

		if ($this->children_supplied)
		{
			$oldchildren = $this->children_oid;
			$this->children_oid = array();

			foreach ($this->children as $child)
				array_push($this->children_oid, $child->getOID());

			if ($this->children_oid !== $oldchildren)
				$this->markDirty();
		}
		else
			$this->children = array();

		return true;
	}

	/**
	 * Returns true is the Account is read only or false if buckets can be created or deleted
	 *
	 * @return bool
	 */
	public function isReadonly()
	{
		return $this->readonly;
	}

	/**
	 * Set to false if buckets can be created or deleted
	 *
	 * @param bool $readonly
	 * @return void
	 */
	public function setReadonly($readonly = true)
	{
		$this->readonly = $readonly;
	}

	/**
	 * Renames the Account
	 *
	 * @param string $name The new name
	 * @throws Sabre_DAV_Exception_NotImplemented
	 * @return void
	 */
	public function setName($name)
	{
		throw new Sabre_DAV_Exception_NotImplemented('S3 Account Collection cannot be renamed');
	}

	/**
	 * Creates a new file
	 *
	 * @param string $name
	 * @param resource $data
	 * @throws Sabre_DAV_Exception_NotImplemented
	 * @return void
	 */
	public function createFile($name, $data = null)
	{
		throw new Sabre_DAV_Exception_NotImplemented('S3 Accounts can only hold buckets');
	}

	/**
	 * Creates a new Bucket
	 *
	 * @param string $name Name of the bucket
	 * @throws Sabre_DAV_Exception_MethodNotAllowed, Sabre_DAV_S3_Exception, S3_Exception
	 * @return void
	 */
	public function createDirectory($name)
	{
		if ($this->readonly)
			throw new Sabre_DAV_Exception_MethodNotAllowed('S3 Account is read only');

		$response = $this->getS3()->create_bucket($name, $this->getRegion(), $this->getACL());
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Bucket failed', $response);

		$node = new Sabre_DAV_S3_Bucket($name, $this);
		$node->persist();

		$node->setStorageClass($this->getStorageClass());
		$node->setACL($this->getACL());

		$this->addChild($node);
	}

	/**
	 * Updates the Buckets collection from S3
	 *
	 * @param bool $fulltree If true, all subdirectories within buckets will also be parsed, buckets only otherwise
	 * @return void
	 */
	public function requestChildren($fulltree = false)
	{
		$response = $this->getS3()->list_buckets();
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 GET Bucket list failed', $response);

		if ($response->body)
		{
			if (isset($response->body->Owner))
			{
				$owner = array();
				$owner['ID'] = (string)$response->body->Owner->ID;
				$owner['DisplayName'] = (string)$response->body->Owner->DisplayName;
				$this->setOwner($owner);
			}

			if ($response->body->Buckets && $response->body->Buckets->Bucket)
			{
				foreach ($response->body->Buckets->Bucket as $bucket)
				{
					$lastmodified = null;
					if (isset($bucket->CreationDate))
						$lastmodified = strtotime((string)$bucket->CreationDate);

					$node = Sabre_DAV_S3_Bucket::getInstanceByKey(array('bucket' => (string)$bucket->Name), (string)$bucket->Name, $this);
					$node->setLastModified($lastmodified);
					$this->addChild($node);
				}
			}
		}

		if ($fulltree)
			foreach ($this->children as $bucket)
				$bucket->requestChildren(true);

		$this->children_requested = true;
	}

	/**
	 * Returns an array with all the child nodes
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_S3_Bucket[]
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
	 * Deletes the Account
	 *
	 * @throws Sabre_DAV_Exception_MethodNotAllowed
	 * @return void
	 */
	public function delete()
	{
		throw new Sabre_DAV_Exception_MethodNotAllowed('S3 Accounts cannot be deleted');
	}
}
