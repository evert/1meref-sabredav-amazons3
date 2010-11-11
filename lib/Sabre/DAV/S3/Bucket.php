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
	 * The metafile's ID
	 *
	 * @var string
	 */
	protected $metafile_id = null;

	/**
	 * Sets up the node as a bucket, wich is a special case of Directory
	 * Either an existing S3 instance $s3, or $key and $secret_key have to be given
	 *
	 * @param string $bucket
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1] The buckets endpoint Region
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($bucket, $parent = null, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = null, $use_ssl = null)
	{
		parent::__construct(null, $parent, $bucket, $s3, $key, $secret_key, $region, $use_ssl);
	}

	/**
	 * Save the node
	 */
	public function __sleep()
	{
		$this->metafile_id = null;
		if (isset($this->metafile))
			$this->metafile_id = $this->metafile->getID();

		return array_merge
		(
			parent::__sleep(),
			array
			(
				'metafile_id'
			)
		);
	}

	/**
	 * Retrieve the object's metadata from all possible sources (list, head, acl)
	 *
	 * @param bool $force
	 * @return void
	 */
	protected function requestMetaData($force = false)
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

		$this->metadata_requested = true;

		if (!isset($this->metafile))
		{
			try
			{
				$this->metafile = $this->getChild('.');
			}
			catch (Exception $e) {}

			if (!isset($this->metafile))
			{
				if (isset($this->parent))
					$this->setStorageClass($this->parent->getStorageClass());
				if (!isset($this->storageclass))
					$this->setStorageClass(AmazonS3::STORAGE_STANDARD);

				$this->createFile('.');
				$this->metafile = $this->getChild('.');
			}
			$this->removeChild('.');
		}

		if (isset($this->metafile))
			$this->setStorageClass($this->metafile->getStorageClass());
	}

	/**
	 * Updates the children collection from S3
	 *
	 * @param bool $fulltree If true, all subdirectories will also be parsed, only the current path otherwise
	 * @return void
	 */
	public function requestChildren($fulltree = false)
	{
		parent::requestChildren($fulltree);

		try
		{
			$this->metafile = $this->getChild('.');
			$this->removeChild('.');
		}
		catch (Exception $e) {}
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
	 * Deletes the entire bucket including versioning of files.
	 * Be careful, this cannot be undone!!! However, not possible to invoke when buckets are root nodes.
	 * @todo Check what happens when you request a move (copy and delete) of the root. Possible security issue!
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		if ($this->parent && $this->parent instanceof Sabre_DAV_S3_Account && $this->parent->isReadonly())
			throw new Sabre_DAV_Exception_MethodNotAllowed('S3 Account is read only');

		$response = $this->getS3()->delete_bucket
		(
			$this->bucket,
			true
		);
		if ($response === false || !$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Bucket failed', $response);

		$this->children = array();
		parent::delete();
	}
}
