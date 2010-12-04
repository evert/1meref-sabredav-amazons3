<?php

/**
 * File class for S3 objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_File extends Sabre_DAV_S3_Object implements Sabre_DAV_IFile, Sabre_DAV_IFileStreamable
{

	/**
	 * Sets up the file, expects a full Object name
	 * If $parent is not given, a Bucket name and a S3 instance or Amazon credentials have to be given
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_ICollection $parent
	 * @param string $bucket
	 * @return void
	 */
	public function __construct($object, Sabre_DAV_S3_ICollection $parent = null, $bucket = null)
	{
		parent::__construct($object, $parent, $bucket);

		$this->contenttype = ''; //so that we do not have to query the Content-Type for every PROPFIND request
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
	 * Returns the data
	 *
	 * @param bool $streamout output stream directly for GET requests?
	 * @param int $start Range request start byte - 0-based and inclusive
	 * @param int $end Range request end byte - 0-based and inclusive
	 * @throws Sabre_DAV_S3_Exception, Sabre_DAV_Exception
	 * @return resource
	 */
	public function get($streamout = false, $start = null, $end = null)
	{
		$opts = array();
		$opts['curlopts'] = array(CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false);
		if (isset($start) && isset($end) && $start >= 0 && $start <= $end)
			$opts['range'] = $start . '-' . $end;

		if (!$streamout)
		{
			$filehandle = fopen('php://temp', 'w+');
			$opts['curlopts'][CURLOPT_FILE] = $filehandle;

			$response = $this->getS3()->get_object($this->bucket, $this->object, $opts);
			if (!$response->isOK(array(200, 206)))
				throw new Sabre_DAV_S3_Exception('S3 GET Object failed', $response);

			rewind($filehandle);
			return $filehandle;
		}
		else
		{
			if (headers_sent())
				throw new Sabre_DAV_Exception('Content integrity cannot be ensured! Unexpected script output');

			ob_end_clean();

			$filehandle = fopen('php://output', 'w');
			$callback = new Sabre_DAV_S3_CurlStream($filehandle);
			$opts['curlopts'][CURLOPT_HEADERFUNCTION] = array($callback, 'header');
			$opts['curlopts'][CURLOPT_WRITEFUNCTION] = array($callback, 'write');

			$response = $this->getS3()->get_object($this->bucket, $this->object, $opts);
			if ($response->isOK(404))
				throw new Sabre_DAV_Exception_FileNotFound('S3 Object not found');
			elseif (!$response->isOK(array(200, 206)))
				throw new Sabre_DAV_S3_Exception('S3 GET Object failed', $response);

			return null;
		}
		/*		else
		{
			ob_end_clean();
			header('Location: ' . $this->getS3()->get_object_url($this->bucket, $this->object, '3 minutes'), true, 302);
			exit;
		}*/
	}

	/**
	 * Updates the data
	 * In order for direct stream pass through to Amazon S3 to work, we have to know the stream size in advance
	 *
	 * @param resource $data
	 * @param int $size Stream size of $data
	 * @param string $type MIME-Type
	 * @throws Sabre_DAV_Exception_BadRequest, Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function put($data, $size = null, $type = null)
	{
		$opts = array();
		$headers = array();

		if (!isset($size) && is_resource($data) && ($stats = @fstat($data)) && $stats['size'] >= 0)
			$size = $stats['size'];

		if (!isset($type) || strtolower($type) === 'application/x-www-form-urlencoded')
			$type = $this->getContentType();
		if (empty($type))
			$type = $this->guessContentType();

		if (is_resource($data))
		{
			$uploadData = $data;
			if (!isset($size) || $size < 0) //fall back: download stream, check size and then upload
			{
				$uploadData = fopen('php://temp', 'w+');
				stream_copy_to_stream($data, $uploadData);
				rewind($uploadData);
				$stats = @fstat($uploadData);
				$size = $stats['size'];
				if (!isset($size) || $size < 0)
					throw new Sabre_DAV_Exception_BadRequest('Content-Length for PUT cannot be determined');
			}
			$callback = new Sabre_DAV_S3_CurlStream($uploadData, $size);
			$opts['curlopts'] = array(CURLOPT_UPLOAD => true, CURLOPT_INFILESIZE => $size, CURLOPT_READFUNCTION => array($callback, 'read'));
		}
		else //should we even allow this? The directory createFile allows $data to be null so we have to handle this here
		{
			$opts['body'] = '';
			$size = 0;
		}

		$headers['Content-Length'] = $size;
		$opts['headers'] = $headers;
		$opts['contentType'] = $type;

		$storage = $this->getStorageClass();
		if (isset($storage))
			$opts['storage'] = $storage;

		$acl = $this->getACL();
		if (isset($acl))
			$opts['acl'] = $acl;

		$response = $this->getS3()->create_object($this->bucket, $this->object, $opts);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object failed', $response);

		$this->setLastModified($response->header['date'] ? strtotime($response->header['date']) : null);
		$this->setSize($size);
		$this->setContentType($type);
		$this->setETag($response->header['etag'] ? $response->header['etag'] : null);
		//$this->metadata_requested = false;
	//$this->markDirty();
	}

	/**
	 * Guesses the MIME-Type for an object by it's extension
	 * Returns application/octet-stream if unknown
	 *
	 * @return string
	 */
	public function guessContentType()
	{
		$extension = explode('.', $this->object);
		$extension = array_pop($extension);
		$mime_type = CFMimeTypes::get_mimetype($extension);

		return $mime_type;
	}

	/**
	 * Delete this Object
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->getS3()->delete_object($this->bucket, $this->object);
		if (!$response->isOK())
			throw new Sabre_DAV_Exception('S3 DELETE Object failed', $response);

		$this->getParent()->removeChild($this->name);
		$this->remove();
	}
}
