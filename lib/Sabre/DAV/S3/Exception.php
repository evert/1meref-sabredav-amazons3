<?php

/**
 * S3 Exception
 *
 * The S3 Exception is thrown when REST calls to S3 fail for some reason
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Exception extends Sabre_DAV_Exception
{
	/**
	 * The S3 response
	 *
	 * @var ResponseCore
	 */
	protected $s3response;

	/**
	 * Creates the exception
	 *
	 * @param string $message
	 * @param ResponseCore $s3response The S3 response object
	 * @todo still need to figure out what to do with the S3 response object
	 */
	public function __construct($message, $s3response = null)
	{
		$this->s3response = $s3response;
		if ($s3response)
			$message .= ' (S3 status: ' . $s3response->status . ')';
		parent::__construct($message);
	}

	/**
	 * Returns the HTTP statuscode for this exception
	 *
	 * @return int
	 */
	public function getHTTPCode()
	{
		return 500;
	}
}
