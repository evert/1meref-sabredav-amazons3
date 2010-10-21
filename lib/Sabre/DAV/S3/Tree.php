<?php

/**
 * Tree class for S3 accounts.
 *
 * @package Sabre
 * @subpackage DAV
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Tree extends Sabre_DAV_ObjectTree
{
	/**
	 * The Amazon S3 SDK instance for API calls
	 *
	 * @var AmazonS3
	 */
	protected $s3 = null;

	/**
	 * Sets up the tree
	 * A S3 instance or Amazon credentials have to be given
	 *
	 * @param Sabre_DAV_S3_Directory $rootnode
	 * @param string $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct(Sabre_DAV_S3_Directory $rootnode, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = AmazonS3::REGION_US_E1, $use_ssl = true)
	{
		parent::__construct($rootnode);

		if (isset($rootnode))
			$this->s3 = $rootnode->getS3();

		if (isset($s3))
			$this->s3 = $s3;

		if (isset($key) && isset($secret_key))
		{
			$this->s3 = new AmazonS3($key, $secret_key);
			$this->s3->set_region($region);
			if (!$use_ssl)
				$this->s3->disable_ssl();
		}
	}

	/**
	 * Returns the S3 node object for the requested path  
	 * 
	 * @param string $path 
	 * @return Sabre_DAV_S3_Node 
	 */
	public function getNodeForPath($path)
	{
		$path = trim($path, '/');
		if (isset($this->cache[$path]))
			return $this->cache[$path];

		list($parent, $child) = Sabre_DAV_URLUtil::splitPath($path);
		if (isset($parent) && isset($child))
		{
			if ($parent === '')
				$parentNode = $this->rootNode;
			else
			{
				if (isset($this->cache[$parent]))
					$parentNode = $this->cache[$parent];
				else
					$parentNode = new Sabre_DAV_S3_Directory($this->rootNode->getObject() . $parent, null, $this->rootNode->getBucket(), $this->s3);
			}

			try
			{
				$childNode = $parentNode->getChild($child);
			}
			catch (Sabre_DAV_Exception_FileNotFound $e)
			{
				throw new Sabre_DAV_Exception_FileNotFound('Could not find node at path: ' . $path);
			}
		}
		else
			$childNode = $this->rootNode;


		if (isset($parent) && $parent !== '')
		{
			$this->cache[$parent] = $parentNode;
			$this->cache[$parent . '/' . $child] = $childNode;
		}
		else
			$this->cache[$path] = $childNode;

		return $childNode;
	}

	/**
	 * Returns the tree's S3 instance
	 *
	 * @return AmazonS3
	 */
	public function getS3()
	{
		return $this->s3;
	}
}
