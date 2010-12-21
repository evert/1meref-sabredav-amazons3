<?php

/**
 * Interface for a shared queue
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_S3_Plugin_IQueue
{
	/**
	 * Are there entries left in the queue?
	 *
	 * @return boolean
	 */
	public function isEmpty();

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @return string|boolean
	 */
	public function dequeue();

	/**
	 * Add strings to the end of the queue
	 *
	 * @param $data string|array
	 * @return boolean
	 */
	public function enqueue($data);

	/**
	 * reorganize the queue storage
	 *
	 * @return boolean
	 */
	public function organize();

	/**
	 * clear the queue
	 *
	 * @return boolean
	 */
	public function clear();
}
