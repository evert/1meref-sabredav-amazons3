<?php

/**
 * Tree class for S3 accounts.
 *
 * @package Sabre
 * @subpackage DAV
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
	 * The default S3 endpoint Region
	 * Valid values are [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * 
	 * @var string 
	 */
	protected $region = null;

	/**
	 * Sets up the tree
	 * S3 instance or Amazon credentials have to be given if $rootnode has no S3 instance 
	 *
	 * @param Sabre_DAV_S3_ICollection $rootnode
	 * @param string $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct(Sabre_DAV_S3_ICollection $rootnode, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = AmazonS3::REGION_US_E1, $use_ssl = true)
	{
		parent::__construct($rootnode);

		//default values
		$this->region = AmazonS3::REGION_US_E1;
		if (!isset($use_ssl))
			$use_ssl = true;

		if (isset($rootnode))
		{
			$this->cache[''] = $rootnode;
			$this->s3 = $rootnode->getS3();
			$this->region = $rootnode->getRegion();
		}

		if (isset($s3))
			$this->s3 = $s3;
			
		if (isset($region))
			$this->region = $region;

		if (isset($key) && isset($secret_key))
		{
			$this->s3 = new AmazonS3($key, $secret_key);
			$this->s3->set_region($this->region);
			if (!$use_ssl)
				$this->s3->disable_ssl();
		}
	}

	/**
	 * Returns the S3 Object node for the requested path  
	 * 
	 * @param string $path 
	 * @return Sabre_DAV_S3_Node 
	 */
	public function getNodeForPath($path)
	{
		$path = trim($path, '/');
		if (isset($this->cache[$path]))
			return $this->cache[$path];

		$bucket = null;
		$pathprefix = '';
		$objectpath = $path;
		if ($this->rootNode instanceof Sabre_DAV_S3_Account)
		{
			$p = strpos($path, '/');
			if ($p !== false)
			{
				$bucket = substr($path, 0, $p);
				$pathprefix = $bucket . '/';
				$objectpath = substr($path, $p + 1);
			}
			else
			{
				$bucket = $path;
				$pathprefix = $bucket . '/';
				$objectpath = '';
			}
		}
		else
			$bucket = $this->rootNode->getBucket();

		list($parent, $child) = Sabre_DAV_URLUtil::splitPath($objectpath);
		$parent = isset($parent) ? $parent : '';
		$child = isset($child) ? $child : '';
		$parentNode = null;
		$childNode = null;

		if ($parent === '')
		{ 
			if ($pathprefix === '')
			{
				if ($child === '')	//case "/"
					$childNode = $this->rootNode;
				else	//case "/object"
					$parentNode = $this->rootNode;
			}
			else
			{
				if ($child === '')	//case "/bucket"
				{
					$parentNode = $this->rootNode;
					//easiest way to get the child node and set the cache correctly
					$child = $bucket;
					$pathprefix = '';
				}
				else	//case "/bucket/object"
				{
					if (isset($this->cache[$bucket]))
						$parentNode = $this->cache[$bucket];
					else
					{
						$parentNode = new Sabre_DAV_S3_Bucket($bucket, $this->rootNode);
						$this->rootNode->addChild($parentNode);
					}
				}
			}
		}
		else	//case "/folder[/subfolder]/object" and "/bucket/folder[/subfolder]/object"
		{
			list($grandparent, $parentName) = Sabre_DAV_URLUtil::splitPath($parent);
			$grandparent = isset($grandparent) ? $grandparent : '';
			$parentName = isset($parentName) ? $parentName : '';
			$grandparentNode = null;
			if ($grandparent === '')
			{
				if ($pathprefix === '')	//case "/folder/object"
					$grandparentNode = $this->rootNode;
				elseif (isset($this->cache[$bucket]))	//case "/bucket/folder/object"
					$grandparentNode = $this->cache[$bucket];
			}
			elseif (isset($this->cache[$pathprefix . $grandparent]))	//case "/folder/subfolder/object" and "/bucket/folder/subfolder/object"
				$grandparentNode = $this->cache[$pathprefix . $grandparent];

			if (isset($grandparentNode))
			{
				$objectprefix = $grandparentNode instanceof Sabre_DAV_S3_Object ? $grandparentNode->getObject() : '';
				$parentNode = new Sabre_DAV_S3_Directory($objectprefix . $parentName, $grandparentNode);
				$grandparentNode->addChild($parentNode);
			}
			else
			{
				$objectprefix = $this->rootNode instanceof Sabre_DAV_S3_Object ? $this->rootNode->getObject() : '';
				$parentNode = new Sabre_DAV_S3_Directory($objectprefix . $parent, null, $bucket, $this->s3, null, null, $this->region, null);
			}
		}

		if (!isset($childNode) && isset($parentNode))
		{
			try
			{
				$childNode = $parentNode->getChild($child);
			}
			catch (Sabre_DAV_Exception_FileNotFound $e)
			{
				throw new Sabre_DAV_Exception_FileNotFound('Could not find node at path: ' . $path);
			}
		}

		if (isset($parentNode))
			$this->cache[$pathprefix . $parent] = $parentNode;
		$this->cache[$pathprefix . ($parent !== '' ? $parent . '/' : '') . $child] = $childNode;

		return $childNode;
	}

	/**
	 * Checks if a the node to a given path exists
	 * 
	 * @param string $path
	 */
	public function nodeExists($path)
	{
		try
		{
			$this->getNodeForPath($path);
			return true;
		}
		catch (Sabre_DAV_Exception_FileNotFound $e) {}

		return false;
	}

	/**
	 * Copies a node with native S3 requests
	 *
	 * @param Sabre_DAV_S3_INode $source
	 * @param Sabre_DAV_S3_ICollection $destination
	 * @param string $destinationName
	 * @return void
	 */
	protected function copyNode(Sabre_DAV_S3_INode $source, Sabre_DAV_S3_ICollection $destinationParent, $destinationName = null)
	{
		if (!isset($destinationName) || $destinationName === '')
			$destinationName = $source->getName();
		
		if ($source instanceof Sabre_DAV_S3_File)
		{
			//request storage and acl before content-type to provoke a requestMetaData if nessecary. Content-Type is set to an empty string, not null, in File constructor
			$destination = new Sabre_DAV_S3_File($destinationParent->getObject() . $destinationName, $destinationParent);
			$destination->setLastModified(time());
			$destination->setSize($source->getSize());
			$destination->setETag($source->getETag());
			$destination->setStorageClass($source->getStorageClass());
			$destination->setACL($source->getACL());
			$destination->setContentType($source->getContentType());
			
			$s3 = $destinationParent->getS3();
			if (!$s3)
				$s3 = $this->s3;
			$response = $s3->copy_object
			(
				array
				(
					'bucket' => $source->getBucket(),
					'filename' => $source->getObject()
				),
				array
				(
					'bucket' => $destination->getBucket(),
					'filename' => $destination->getObject()
				),
				array
				(
					'headers' => array
					(
						'Content-Type' => $destination->getContentType()
					),
					'storage' => $destination->getStorageClass(),
					'acl' => $destination->getACL()
				)
			);
			if (!$response->isOK())
				throw new Sabre_DAV_S3_Exception('S3 PUT Object (Copy) failed', $response);
			
			$destinationParent->addChild($destination);
		}
		elseif ($source instanceof Sabre_DAV_S3_ICollection)	//recurse into subdirectories
		{
			$destinationParent->createDirectory($destinationName);
			$destination = $destinationParent->getChild($destinationName);
			foreach ($source->getChildren() as $child)
				$this->copyNode($child, $destination);	//recursion
		}
		if ($source instanceof Sabre_DAV_IProperties && $destination instanceof Sabre_DAV_IProperties)
		{
			$props = $source->getProperties(array());
			$destination->updateProperties($props);
		}
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
	
	protected function buildTree($path)
	{
		$tree = $this->getS3()->list_objects($this->rootNode->getBucket());
	}
}
