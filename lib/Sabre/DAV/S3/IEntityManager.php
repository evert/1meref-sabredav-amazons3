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
	 * In this flush mode managed entities are saved or removed only by invoking the flush() method
	 *
	 * @var int
	 */
	const FLUSH_MANUAL = 0;

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
	const FLUSH_IMMEDIATE = 3;

	/**
	 * Return the current flush mode
	 *
	 * @return int
	 */
	public function getFlushMode();

	/**
	 * Set the current flush mode
	 *
	 * @param int $flushmode
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
	 * Assigns a new persistence Object ID (OID) if new
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param bool $overwrite
	 * @return bool
	 */
	public function persist(Sabre_DAV_S3_IPersistable $object, $overwrite = false);

	/**
	 * Delete the Entity from the data store
	 * Entity will still exist in the current persistence context, but changes are not saved anymore
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function remove(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Copy (merge) the state of one Entity into another
	 *
	 * @param Sabre_DAV_S3_IPersistable $destination
	 * @param Sabre_DAV_S3_IPersistable $source
	 * @return bool
	 */
	public function updateObjectState(Sabre_DAV_S3_IPersistable $destination, Sabre_DAV_S3_IPersistable $source);

	/**
	 * Refresh a (managed, detached or new) Entity from the data store
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
	 * Make a copy (clone) of the Entity persistent
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function merge(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Save all pending changes
	 *
	 * @return bool
	 */
	public function flush();

	/**
	 * Remove all expired Entities
	 *
	 * @param int $age timestamp
	 * @param string $class
	 */
	public function expire($before, $class = null);

	/**
	 * Get an array of all managed Entities in this persistence context
	 *
	 * @return Sabre_DAV_S3_IPersistable[]
	 */
	public function getManaged();

	/**
	 * Get the creation time of an Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return mixed
	 */
	public function getCreationTime(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Get the last persistent modification time of an Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return mixed
	 */
	public function getLastModified(Sabre_DAV_S3_IPersistable $object);
}
