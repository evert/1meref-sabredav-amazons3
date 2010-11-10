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
	 * The account's buckets
	 *
	 * @var Sabre_DAV_S3_Bucket[]
	 */
	protected $children = array();

	/**
	 * Did we populate the list of Buckets from S3?
	 * 
	 * @var bool
	 */
	protected $children_requested = false;

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
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($buckets = null, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = null, $use_ssl = null)
	{
		parent::__construct('S3', null, $s3, $key, $secret_key, $region, $use_ssl);

		if (isset($buckets))
		{
			foreach ($buckets as $bucket)
			{
				if (is_string($bucket))
					$bucket = new Sabre_DAV_S3_Bucket($bucket, $this);
				$this->addChild($bucket);
			}
			$this->children_requested = true;
			$this->readonly = true;
		}
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

		$response = $this->getS3()->create_bucket
		(
			$name,
			$this->getRegion(),
			$this->getACL()
		);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Bucket failed', $response);

		$node = new Sabre_DAV_S3_Bucket($name, $this);
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
					{
						$dt = new DateTime((string)$bucket->CreationDate);
						$lastmodified = $dt->getTimestamp();
					}

					$node = new Sabre_DAV_S3_Bucket((string)$bucket->Name, $this);
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
	 * Resets the children collection
	 * 
	 * @return void
	 */
	public function clearChildren()
	{
		$this->children = array();
		$this->children_requested = false;
	}

	/**
	 * Returns an array with all the child nodes
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return Sabre_DAV_Bucket[]
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
?>
