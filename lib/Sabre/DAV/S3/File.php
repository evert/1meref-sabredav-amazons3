<?php

/**
 * File class for S3 objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_File extends Sabre_DAV_S3_Node implements Sabre_DAV_IFile, Sabre_DAV_IFileStreamable
{

	/**
	 * Sets up the file, expects a full object name
	 * If $parent is not given, a bucket name and a S3 instance or Amazon credentials have to be given
	 *
	 * @param string $object
	 * @param Sabre_DAV_S3_Directory $parent
	 * @param string $bucket
	 * @param AmazonS3 $s3
	 * @param string $key
	 * @param string $secret_key
	 * @param string $region [AmazonS3::REGION_US_E1, AmazonS3::REGION_US_W1, AmazonS3::REGION_EU_W1, AmazonS3::REGION_APAC_SE1]
	 * @param bool $use_ssl
	 * @return void
	 */
	public function __construct($object, Sabre_DAV_S3_Directory $parent = null, $bucket = null, AmazonS3 $s3 = null, $key = null, $secret_key = null, $region = AmazonS3::REGION_US_E1, $use_ssl = true)
	{
		$object = rtrim($object, '/');

		parent::__construct($object, $parent, $bucket, $s3, $key, $secret_key, $region, $use_ssl);

		$this->setContentType('');	//so that we do not have to query the Content-Type for every PROPFIND request
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
		$opts['curlopts'] = array
		(
			CURLOPT_HEADER => false,
			CURLOPT_RETURNTRANSFER => false
		);
		if (isset($start) && isset($end) && $start >= 0 && $start <= $end)
			$opts['range'] = $start . '-' . $end;

		if (!$streamout)
		{
			$filehandle = fopen('php://temp', 'w+');
			$opts['curlopts'][CURLOPT_FILE] = $filehandle;

			$response = $this->s3->get_object
			(
				$this->bucket,
				$this->object,
				$opts
			);
			if (!$response->isOK(array(200, 206)))
				throw new Sabre_DAV_S3_Exception('S3 GET Object failed', $response);

			rewind($filehandle);
			return $filehandle;
		}
		else
		{
			ob_end_clean();
			if (headers_sent())
				throw new Sabre_DAV_Exception('Content integrity cannot be ensured. Unexpected script output.');

			$filehandle = fopen('php://output', 'w');
			$callback = new Sabre_DAV_S3_CurlStream($filehandle);
			$opts['curlopts'][CURLOPT_HEADERFUNCTION] = array($callback, 'header');
			$opts['curlopts'][CURLOPT_WRITEFUNCTION] = array($callback, 'write');

			$response = $this->s3->get_object($this->bucket, $this->object, $opts);
			if (!$response->isOK(array(200, 206)))
				throw new Sabre_DAV_S3_Exception('S3 GET Object failed', $response);

			return null;
		}
		/*		else
		{
			ob_end_clean();
			header('Location: ' . $this->s3->get_object_url($this->bucket, $this->object, '3 minutes'), true, 302);
			exit;
		}*/
	}

	/**
	 * Updates the data
	 * In order for direct stream pass through to Amazon S3 to work, we have to know the stream size in advance.
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
			if (!isset($size) || $size < 0) //fall back: download stream, check size and then upload
			{
				$newData = fopen('php://temp', 'w+');
				stream_copy_to_stream($data, $newData);
				rewind($newData);
				$stats = @fstat($newData);
				$size = $stats['size'];
				if (!isset($size) || $size < 0)
					throw new Sabre_DAV_Exception_BadRequest('Content-Length for PUT cannot be determined');
			}
			$callback = new Sabre_DAV_S3_CurlStream($data, $size);
			$opts['curlopts'] = array
			(
				CURLOPT_UPLOAD => true,
				CURLOPT_INFILESIZE => $size,
				CURLOPT_READFUNCTION => array($callback, 'read')
			);
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

		$response = $this->s3->create_object
		(
			$this->bucket,
			$this->object,
			$opts
		);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object failed', $response);

		$this->setLastModified(time());
		$this->setSize($size);
		$this->setContentType($type);
		$this->setETag(null);
		$this->metadata_requested = false;
	}

	/**
	 * Delete this object
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->s3->delete_object
		(
			$this->bucket,
			$this->object
		);
		if (!$response->isOK())
			throw new Sabre_DAV_Exception('S3 DELETE Object failed', $response);

		parent::delete();
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
}
