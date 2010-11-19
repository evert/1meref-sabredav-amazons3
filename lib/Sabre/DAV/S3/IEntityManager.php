<?php

/**
 * Entity Manager Interface for persistable Objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_S3_IEntityManager
{
	/**
	 * Object Relational mapping strategy
	 * All objects are stored in a single table
	 *
	 * @var int
	 */
	const ORM_SINGLE_TABLE = 1;

	/**
	 * Object Relational mapping strategy
	 * Every concrete class has it's own table
	 *
	 * @var int
	 */
	const ORM_CONCRETE_CLASS = 2;

	/**
	 * In this flush mode Entities are only saved or removed when the Entity Manager unloads (__destruct)
	 *
	 * @var int
	 */
	const FLUSH_UNLOAD = 1;

	/**
	 * In this flush mode managed entities are saved or removed immediately in addition to FLUSH_UNLOAD
	 *
	 * @var int
	 */
	const FLUSH_IMMEDIATE = 2;

	/**
	 * Gets the current flush mode
	 *
	 * @return int
	 */
	public function getFlushMode();

	/**
	 * Sets the current flush mode
	 *
	 * @param int $flushmode
	 * @return void
	 */
	public function setFlushMode($flushmode);

	/**
	 * Reset the Entity Manger
	 * All unsaved changes to Entities are lost
	 *
	 * @return void
	 */
	public function clear();

	/**
	 * Flush and close the Entity Manager
	 * Entity Manager becomes inaccessable after this
	 *
	 * @return void
	 */
	public function close();

	/**
	 * Checks if the given Object is managed
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 */
	public function contains(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Get an Entity by Object ID
	 *
	 * @param string $oid
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function find($oid);

	/**
	 * Get an Entity by Class name and Key
	 *
	 * @param string $class
	 * @param array $key
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function findByKey($class, $key);

	/**
	 * Makes an Entity persistent
	 * Assigns a new Object ID if new
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param bool $overwrite
	 * @return bool
	 */
	public function persist(Sabre_DAV_S3_IPersistable $object, $overwrite = false);

	/**
	 * Make a copy (clone) of the Entity persistent
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function merge(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Refresh the Entity from the persistent state, even if the Entity is not managed
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function modernize(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Refresh the Entity from it's persistent state
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function refresh(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Detach the Entity from the persistence context
	 * Changes after this will not be saved
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function detach(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Delete the saved Entity
	 * Entity will still exist in the current persistence context, but changes not saved anymore
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function remove(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Save all pending changes
	 *
	 * @return bool
	 */
	public function flush();

	/**
	 * Remove all expired Entities
	 *
	 * @param int $before timestamp
	 * @param string $class
	 */
	public function expire($before, $class = null);

	/**
	 * Get an array of all managed Entities in this persistence context
	 *
	 * @return Sabre_DAV_S3_IPersistable[]
	 */
	public function getManaged();
}
