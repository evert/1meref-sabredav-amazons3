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
	const LOCK_READ = 1;

	const LOCK_WRITE = 2;

	/**
	 * Try to acquire a read or write lock
	 *
	 * @param string $locktype [LOCK_READ, LOCK_WRITE]
	 * @return integer|boolean the lock number or false if no lock is available
	 */
	public function acquireLock($locktype);

	/**
	 * Release the read or write lock
	 *
	 * @param integer $lock
	 * @return boolean
	 */
	public function releaseLock($lock);

	/**
	 * Checks if the queue is empty
	 *
	 * @param integer $lock a valid read or write lock
	 * @return boolean
	 */
	public function isEmpty($lock = 0);

	/**
	 * Add data to the end of the queue
	 * May be an array to write multiple records. Pass an array of an array to write an array.
	 *
	 * @param mixed $data
	 * @param integer $lock a valid write lock
	 * @return boolean
	 */
	public function enqueue($data, $lock = 0);

	/**
	 * Add data to the end of the queue
	 * May be an array to write multiple records. Pass an array of an array to write an array.
	 *
	 * @param mixed $data
	 * @param integer $lock a valid write lock
	 * @return boolean
	 */
	public function push($data, $lock = 0);

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid read lock
	 * @return mixed The first queue entry or false on failure
	 */
	public function dequeue($lock = 0);

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid read lock
	 * @return mixed The first queue entry or false on failure
	 */
	public function shift($lock = 0);

	/**
	 * Get the last entry and delete it from the queue
	 *
	 * @param $lock a valid read lock
	 * @return mixed The last queue entry or false on failure
	 */
	public function pop($lock = 0);

	/**
	 * Add data to the top of the queue
	 * May be an array to write multiple records. Pass an array of an array to write an array.
	 *
	 * @param mixed $data
	 * @param integer $lock a valid write lock
	 * @return boolean
	 */
	public function unshift($data, $lock = 0);

	/**
	 * reorganize the queue file
	 *
	 * @param $lock a valid write lock
	 * @return bool
	 */
	public function reorganize($lock = 0);

	/**
	 * clear the queue file
	 *
	 * @param $lock a valid write lock
	 * @return bool
	 */
	public function clear($lock = 0);
}
