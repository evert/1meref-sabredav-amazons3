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
	 * Sets up the node as a bucket, wich is a special case of Directory
	 * Either an existing S3 instance $s3, or $key and $secret_key have to be given
	 *
	 * @param string $bucket
	 * @param string $storageclass [AmazonS3::STORAGE_STANDARD, AmazonS3::STORAGE_REDUCED] The default storage class for new objects. 
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($bucket, $storageclass = AmazonS3::STORAGE_STANDARD, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = AmazonS3::REGION_US_E1, $use_ssl = true)
	{
		parent::__construct(null, null, $bucket, $s3, $key, $secret_key, $region, $use_ssl);

		if (!$storageclass)
			$storageclass = AmazonS3::STORAGE_STANDARD;
		$this->setStorageClass($storageclass); //buckets unfortunately cannot have a default storage class set in S3
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

		$response = $this->s3->get_bucket_acl($this->bucket);
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
	}

	/**
	 * Returns the bucket's name
	 *
	 * @return string
	 */
	public function getName()
	{
		return $this->bucket;
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
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->s3->delete_bucket
		(
			$this->bucket,
			true
		);
		if ($response === false || !$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 DELETE Bucket failed', $response);

		$this->children = null;
	}
}
