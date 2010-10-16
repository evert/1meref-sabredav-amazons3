<?php

/**
 * This class is a helper class to realize stream to stream transfer with S3 using curl
 * Has read, write and header functions to be passed to curl via CURLOPT_READFUNCTION, CURLOPT_WRITEFUNCTION and CURLOPT_HEADERFUNCTION
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_CurlStream
{
	/**
	 * The resource to read from or write to
	 *
	 * @var resource
	 */
	protected $resource = null;

	/**
	 * The size of the stream in case of streaming up
	 *
	 * @var int
	 */
	protected $streamsize = null;

	/**
	 * The maximum chunk size to read. Can be overidden by curl to be lower.
	 *
	 * @var int
	 */
	protected $maxchunk = 8192;

	/**
	 * The number of byte already prcessed internally
	 *
	 * @var unknown_type
	 */
	protected $handled = 0;

	/**
	 * $resource could be obtained from curl if null. Otherwise this overrides the filehandle passed by curl in case of reads.
	 * For uploads (reads) the $streamsize also has to be passed to curl via CURLOPT_INFILESIZE! All processing will stop if
	 * $streamsize is given and reached. If there is still data to write by curl, curl will fail.
	 * $maxchunk can be lowered by curl.
	 *
	 * @param resource $resource
	 * @param int $streamsize
	 * @param int $maxchunk
	 */
	public function __construct($resource = null, $streamsize = null, $maxchunk = 8192)
	{
		$this->resource = $resource;
		$this->streamsize = $streamsize;
		$this->maxchunk = $maxchunk;
		$this->handled = 0;
	}

	/**
	 * Gets called by curl with the CURLOPT_READFUNCTION option for uploads (requests)
	 * PHP Manual is wrong about the method signature as of Sep 2010
	 *
	 * @param resource $curlhandle
	 * @param resource $filehandle
	 * @param int $maxsize
	 */
	public function read($curlhandle, $filehandle, $maxsize)
	{
		if (isset($this->resource))
			$filehandle = $this->resource;
		if (!is_resource($filehandle))
			throw new ErrorException('Input resource missing');
		if (feof($filehandle) || (isset($this->streamsize) && $this->handled >= $this->streamsize))
			return null;

		$maxchunk = (isset($this->streamsize) && $this->streamsize - $this->handled < $this->maxchunk) ? $this->streamsize - $this->handled : $this->maxchunk;
		$maxchunk = min($maxchunk, $maxsize);

		$data = fread($filehandle, $maxchunk);
		if ($data === false)
			return null;
		$size = strlen($data);
		$this->handled += $size;

		return $data;
	}

	/**
	 * Gets called by curl with the CURLOPT_WRITEFUNCTION option for downloads (request response)
	 *
	 * @param resource $curlhandle
	 * @param string $data
	 */
	public function write($curlhandle, $data)
	{
		if (!isset($data) || $data === '')
			return 0;
		if (isset($this->streamsize) && $this->handled >= $this->streamsize)
			return false;

		$filehandle = $this->resource;
		if (!is_resource($filehandle))
			throw new ErrorException('Output resource missing');

		$size = strlen($data);
		$fwrite = 0;
		for ($written = 0; $written < $size; $written += $fwrite)
		{
			$fwrite = fwrite($filehandle, substr($data, $written));
			if ($fwrite === false)
				return $written;
			$this->handled += $fwrite;
		}

		return $written;
	}

	/**
	 * Gets called by curl with CURLOPT_HEADERFUNCTION option for downloads (request response)
	 * Merges / overwrites any existing headers in output buffer (if enabled!) and passes through the request response headers.
	 *
	 * @param unknown_type $curlhandle
	 * @param unknown_type $data
	 */
	public function header($curlhandle, $data)
	{
		$size = strlen($data);
		$matches = null;

		if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/i', $data, $matches))
		{
			if (!in_array((int)$matches[1], array(200, 206, 304, 307))) //307 is handled by AmazonS3->authenticate() to request again. Headers should not have been sent by then!?
				throw new ErrorException('Unexpected status code (' . $matches[1] . ')');
			$data = preg_replace('/^HTTP\/\d+\.\d+/i', $_SERVER['SERVER_PROTOCOL'], $data); //set our HTTP protocol version (Amazon is 1.1 but we could be 1.0)
		}
		if (preg_match('/^[coneti]{10}\:/i', $data)) //strip out server or load balancer "Connection:" header
			$data = null;
		if (preg_match('/^[kep\-aliv]{10}\:/i', $data)) //strip out server or load balancer "Keep-Alive:" header
			$data = null;

		if (isset($data))
			header($data, true);

		return $size;
	}
}
