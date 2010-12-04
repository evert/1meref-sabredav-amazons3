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
	 * The S3 response or staus code
	 *
	 * @var ResponseCore|int
	 */
	protected $response;

	/**
	 * Creates the exception
	 *
	 * @param string $message
	 * @param ResponseCore|int $s3response The S3 response object or just a status code
	 */
	public function __construct($message, $response = null)
	{
		$this->response = $response;
		parent::__construct($message);
	}

	/**
	 * Returns the HTTP statuscode for this exception
	 *
	 * @return int
	 */
	public function getHTTPCode()
	{
		if (isset($this->response))
		{
			if (is_int($this->response))
				return $this->response;
			else
				return $this->response->status;
		}
		else
			return 500;
	}
}
