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
	 * Try to acquire a lock for processing power
	 *
	 * @param int maxlocks
	 * @return int|bool the lock number or false if no lock is available
	 */
	public function acquireLock();

	/**
	 * Release the lock for processing power
	 *
	 * @param $lock
	 * @return void
	 */
	public function releaseLock($lock);

	/**
	 * Checks if the queue is empty
	 *
	 * @return bool
	 */
	public function isEmpty();

	/**
	 * Add strings to the end of the queue
	 *
	 * @param $data string|array
	 * @return bool
	 */
	public function enqueue($data);

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed returns false on failure or empty queue
	 */
	public function dequeue($lock);

	/**
	 * reorganize the queue file
	 *
	 * @param $lock a valid lock
	 * @return bool
	 */
	public function reorganize($lock);

	/**
	 * clear the queue file
	 *
	 * @param $lock a valid lock
	 * @return bool
	 */
	public function clear($lock);
}
