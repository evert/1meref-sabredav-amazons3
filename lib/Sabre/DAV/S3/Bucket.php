<?php

/**
 * Bucket class for S3 buckets. Special case of Directory
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Bucket extends Sabre_DAV_S3_Directory
{
	/**
	 * The "." Object that holds the bucket's default metadata (Storage Class)
	 *
	 * @var Sabre_DAV_S3_File
	 */
	protected $metafile = null;

	/**
	 * The ID of the "." Object that holds the bucket's default metadata (Storage Class)
	 *
	 * @var string
	 */
	protected $metafile_oid = null;

	/**
	 * Sets up the node as a bucket, wich is a special case of Directory
	 * Either an existing S3 instance $s3, or $key and $secret_key have to be given
	 *
	 * @param string $bucket
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @return void
	 */
	public function __construct($bucket, $parent = null)
	{
		parent::__construct(null, $parent, $bucket);
	}

	/**
	 * Find the Object by Key or create a new Instance
	 * If $parent is not given a bucket name must be supplied
	 *
	 * @param array $key
	 * @param string $bucket
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @return Sabre_DAV_S3_INode
	 */
	public static function getInstanceByKey($key, $bucket, Sabre_DAV_S3_ICollection $parent = null)
	{
		$object = Sabre_DAV_S3_Persistable::getInstanceByKey(__CLASS__, $key, $bucket, $parent);

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
		return array_merge(parent::getPersistentProperties(), array(__CLASS__ => array('metafile_oid')));
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
		$this->metafile = null;

		return true;
	}

	/**
	 * Returns or creates the metafile
	 *
	 * @return Sabre_DAV_S3_File
	 */
	public function getMetaFile()
	{
		$metafile = null;

		if (isset($this->metafile))
			$metafile = $this->metafile;

		if (!isset($metafile) && $this->getEntityManager() && isset($this->metafile_oid))
		{
			$metafile = $this->getEntityManager()->find($this->metafile_oid);
			if ($metafile)
				$this->addChild($metafile);
			else
			{
				$metafile = null;
				$this->metafile_oid = null;
				$this->markDirty();
			}
		}

		if (!isset($metafile) && !$this->children_requested)
			$this->getChildren();

		if (isset($this->metafile))
			$metafile = $this->metafile;

		if (!isset($metafile))
		{
			if (!isset($this->storageclass))
			{
				if (isset($this->parent))
					$this->setStorageClass($this->parent->getStorageClass());
				if (!isset($this->storageclass))
					$this->setStorageClass(AmazonS3::STORAGE_STANDARD);
			}

			$this->createFile('.', fopen('php://memory', 'r'), 0, '');
		}

		if (isset($this->metafile))
			$metafile = $this->metafile;

		return $metafile;
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

		$response = $this->getS3()->get_bucket_acl($this->bucket);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 Bucket metadata retrieve failed');

		if ($response->body)
		{
			if (isset($response->body->Owner))
			{
				$owner = array();
				$owner['ID'] = (string)$response->body->Owner->ID;
				$owner['DisplayName'] = (string)$response->body->Owner->DisplayName;
				$this->setOwner($owner);
			}

			if (isset($response->body->AccessControlList))
			{
				$acl = array();
				foreach ($response->body->AccessControlList->Grant as $grant)
				{
					$e = array();
					if ($grant->Grantee->ID)
						$e['id'] = (string)$grant->Grantee->ID;
					elseif ($grant->Grantee->URI)
						$e['id'] = (string)$grant->Grantee->URI;
					$e['permission'] = (string)$grant->Permission;
					array_push($acl, $e);
				}
				$this->setACL($acl);
			}
		}

		$metafile = $this->getMetaFile();

		if (isset($metafile))
			$this->setStorageClass($metafile->getStorageClass());

		$this->metadata_requested = true;
	}

	/**
	 * Returns the node's Key
	 *
	 * @return string
	 */
	public function getKey()
	{
		return array('bucket' => $this->bucket);
	}

	/**
	 * Add a child to the children collection
	 *
	 * @param Sabre_DAV_S3_INode $node
	 * @return void
	 */
	public function addChild(Sabre_DAV_S3_INode $node)
	{
		if ($node->getName() === '.')
		{
			$this->metafile = $node;

			$id = $node->getOID();
			if ($this->metafile_oid !== $id)
			{
				$this->metafile_oid = $id;
				$this->markDirty();
			}
		}
		else
			parent::addChild($node);
	}

	/**
	 * Renames the bucket
	 *
	 * @param string $name The new name
	 * @throws Sabre_DAV_Exception_MethodNotAllowed
	 * @return void
	 */
	public function setName($name)
	{
		throw new Sabre_DAV_Exception_MethodNotAllowed('S3 Buckets cannot be renamed');
	}

	/**
	 * Returns the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @return string
	 */
	public function getStorageClass()
	{
		$metafile = $this->getMetaFile();

		if (isset($metafile))
			parent::setStorageClass($metafile->getStorageClass());

		return parent::getStorageClass();
	}

	/**
	 * Sets the node's Storage Redundancy setting or it's default for child nodes
	 *
	 * @param string $storageclass
	 * @return void
	 */
	public function setStorageClass($storageclass)
	{
		$metafile = $this->getMetaFile();

		if (isset($metafile))
			$metafile->setStorageClass($storageclass);

		parent::setStorageClass($storageclass);
	}

	/**
	 * Deletes the entire bucket including versioning of files.
	 * Be careful, this cannot be undone!!! However, not possible to invoke when buckets are root nodes.
	 * @todo Check what happens when you request a move (copy and delete) of the root. Possible security issue!
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$parent = $this->getParent();
		if ($parent && $parent instanceof Sabre_DAV_S3_Account && $parent->isReadonly())
			throw new Sabre_DAV_Exception_MethodNotAllowed('S3 Account is read only');

		$response = $this->getS3()->delete_bucket($this->bucket, true);
		if ($response === false || !$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Bucket failed', $response);

		$parent->removeChild($this->name);
		$this->remove();
	}
}
