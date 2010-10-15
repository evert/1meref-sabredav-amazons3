<?php

/**
 * This is a marker interface for direct streaming (stream to stream) of get() and put() for S3
 * with extended put and get method signatures.
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_IFileStreamable
{

	/**
	 * Returns the data
	 *
	 * @param bool $streamout output stream directly for GET requests?
	 * @param int $start Range request start byte - 0-based and inclusive
	 * @param int $end Range request end byte - 0-based and inclusive
	 * @throws Sabre_DAV_S3_Exception, Sabre_DAV_Exception
	 * @return resource
	 */
	//public function get($streamout = false, $start = null, $end = null);

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
	//public function put($data, $size = null, $type = null);
}
