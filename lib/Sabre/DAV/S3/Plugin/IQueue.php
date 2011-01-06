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
	 * Add data to the end of the queue
	 *
	 * @param $data mixed|array
	 * @return bool
	 */
	public function enqueue($data);

	/**
	 * Add data to the end of the queue
	 *
	 * @param $data mixed|array
	 * @return bool
	 */
	public function push($data);

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed The first queue entry or false on failure
	 */
	public function dequeue($lock);

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed The first queue entry or false on failure
	 */
	public function shift($lock);

	/**
	 * Get the last entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed The last queue entry or false on failure
	 */
	public function pop($lock);

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
