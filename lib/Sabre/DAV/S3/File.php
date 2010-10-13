<?php

/**
 * File class for S3 objects
 *
 * @package Sabre
 * @subpackage DAV
 * @copyright Copyright (C) 2010 Paul Voegler. All rights reserved.
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_File extends Sabre_DAV_S3_Node implements Sabre_DAV_IFileStream
{
	/**
	 * Size of the object
	 *
	 * @var int
	 */
	protected $size = null;
	
	/**
	 * ETag according to RFC 2616 with surrounding double quotes (")
	 *
	 * @var string
	 */
	protected $etag = null;
	
	/**
	 * object's MIME-Type
	 *
	 * @var string
	 */
	protected $contenttype = null;

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
		if (!isset($type))
			$type = $this->guessContentType();

		if (is_resource($data))
		{
			if (!isset($size) || $size < 0)	//fall back: download stream, check size and then upload
			{
				$newData = fopen('php://temp', 'w+');
				stream_copy_to_stream($data, $newData);
				rewind($newData);
				$stats = @fstat($newData);
				$size = $stats['size'];
				if (!isset($size) || $size < 0);
					throw new Sabre_DAV_Exception_BadRequest('Content-Length for PUT cannot be determined');
			}
			$callback = new Sabre_DAV_S3_CurlStream($data, $size);
			$opts['curlopts'] = array(CURLOPT_UPLOAD => true, CURLOPT_INFILESIZE => $size, CURLOPT_READFUNCTION => array($callback, 'read'));
		}
		else	//should we even allow this? The directory createFile allows $data to be null so we have to handle this here
		{
			$opts['body'] = '';
			$size = 0;
		}
		
		$headers['Content-Length'] = $size;
		$headers['Content-Type'] = $type;
		$opts['headers'] = $headers;
		
		$storage = $this->getStorageClass();
		if ($storage)
			$opts['storage'] = $storage;
		
		$response = $this->s3->create_object($this->bucket, $this->object, $opts);
		if (!$response->isOK())
			throw new Sabre_DAV_S3_Exception('S3 PUT Object failed', $response);
		
		$this->setSize($size);
		$this->setContentType($type);
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
			$response = $this->s3->get_object($this->bucket, $this->object, $opts);
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
	 * Delete this object
	 *
	 * @throws Sabre_DAV_S3_Exception
	 * @return void
	 */
	public function delete()
	{
		$response = $this->s3->delete_object($this->bucket, $this->object);
		if (!$response->isOK())
			throw new Sabre_DAV_Exception('S3 DELETE Object failed', $response);
	}

	/**
	 * Returns the size of the node, in bytes
	 *
	 * @return int
	 */
	public function getSize()
	{
		return $this->size;
	}

	/**
	 * @param $size the $size to set
	 * @return void
	 */
	public function setSize($size)
	{
		$this->size = $size;
	}

	/**
	 * Returns the ETag for a file
	 * An ETag is a unique identifier representing the current version of the file. If the file changes, the ETag MUST change.
	 * ETags are according to RFC 2616 with surrounding double quotes (")
	 * Return null if the ETag can not effectively be determined
	 *
	 * @return mixed
	 */
	public function getETag()
	{
		return $this->etag;
	}

	/**
	 * @param $etag the $etag to set
	 * @return void
	 */
	public function setETag($etag)
	{
		$this->etag = $etag;
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
	 * Returns the mime-type for a file
	 * We'll assume "application/octet-stream" if not specified otherwise or guessed by file extension
	 *
	 * @return string
	 */
	public function getContentType()
	{
		return $this->contenttype;
	}

	/**
	 * @param $content_type the $content_type to set
	 * @return void
	 */
	public function setContentType($contenttype)
	{
		$this->contenttype = $contenttype;
	}

	/**
	 * Update file's meta data from a HEAD request
	 *
	 * @return void
	 */
	public function updateMetaData()
	{
		$response = $this->s3->get_object_headers($this->bucket, $this->object);
		if ($response->isOK() && $response->header)
		{
			if ($response->header['last-modified'])
			{
				$dt = new DateTime((string)$response->header['last-modified']);
				$ts = $dt->getTimestamp();
				$this->setLastModified($ts);
			}
			if ($response->header['content-type'])
				$this->setContentType((string)$response->header['content-type']);
			if ($response->header['content-length'])
				$this->setSize((int)$response->header['content-length']);
			if ($response->header['etag'])
				$this->setETag((string)$response->header['etag']);
			if ($response->header['x-amz-storage-class']) //not (yet?) sent by Amazon
				$this->setStorageClass((string)$response->header['x-amz-storage-class']);
		}
	}
}
