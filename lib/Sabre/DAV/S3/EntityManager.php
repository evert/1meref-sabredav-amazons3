<?php

/**
 * Abstract Entity Manager for persistable Objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_DAV_S3_EntityManager implements Sabre_DAV_S3_IEntityManager
{
	/**
	 * Is the Entity manager ready and accessable?
	 *
	 * @var bool
	 */
	protected $open = false;

	/**
	 * The current Object Relational Mapping (ORM) strategy used
	 *
	 * @var int
	 */
	protected $orm_strategy = Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE;

	/**
	 * The current flush mode
	 *
	 * @var int
	 */
	protected $flush_mode = Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD;

	/**
	 * Holds all Entities in this persistence context
	 *
	 * @var Sabre_DAV_S3_IPersistable[]
	 */
	protected $context = array();

	/**
	 * Holds the OIDs of all currently managed Entities
	 *
	 * @var string[]
	 */
	protected $managed = array();

/**
	 * Holds the OIDs of all removed (to be deleted) Entities
	 *
	 * @var string[]
	 */
	protected $removed = array();

	/**
	 * Initialize the Entity Manager
	 *
	 * @param int $ormstrategy
	 * @param int $flushmode
	 * @return void
	 */
	public function __construct($ormstrategy = Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE, $flushmode = Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD)
	{
		$this->setFlushMode($flushmode);
		$this->open = true;
	}

	/**
	 * Close and flush at the end of the persistence context
	 *
	 * @return void
	 */
	public function __destruct()
	{
		if ($this->open)
			$this->close();
	}

	/**
	 * Creates a unique ID for the Object from it's Key
	 *
	 * @param $key
	 * @param $class
	 * @return string
	 * @todo get an id from datastore?
	 */
	protected function generateOID($class, $key)
	{
		if (array_key_exists('oid', $key))
			throw new ErrorException('Entity Manager cannot create generic Object IDs with the OID itself as part of the key.');

		ksort($key);
		return $class . ':' . md5(__CLASS__ . chr(0) . $class . chr(0) . implode(chr(0), $key));
	}

	/**
	 * Get the value of a protected or private property
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param string $name
	 * @return mixed
	 */
	protected function getObjectProperty(Sabre_DAV_S3_IPersistable $object, $name)
	{
		$refobj = new ReflectionObject($object);
		if ($refobj->hasProperty($name))
		{
			$refprop = $refobj->getProperty($name);
			$refprop->setAccessible(true);

			return $refprop->getValue($object);
		}
		else
			return null;
	}

	/**
	 * Set the value of a protected or private property
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param string $name
	 * @param mixed $value
	 * @return bool
	 */
	protected function setObjectProperty(Sabre_DAV_S3_IPersistable $object, $name, $value)
	{
		$refobj = new ReflectionObject($object);
		if ($refobj->hasProperty($name))
		{
			$refprop = $refobj->getProperty($name);
			$refprop->setAccessible(true);
			$refprop->setValue($object, $value);

			return true;
		}
		else
			return false;
	}

	/**
	 * Is there an Entity with the given OID in the data store
	 *
	 * @param string $oid
	 * @return bool
	 */
	abstract protected function exists($oid);

	/**
	 * Load the Entity with the given Object ID
	 *
	 * @param string $oid
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	abstract protected function load($oid);

	/**
	 * Save the given Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	abstract protected function save(Sabre_DAV_S3_IPersistable $object);

	/**
	 * Delete the given Entity by Object ID
	 *
	 * @param string $oid
	 */
	abstract protected function delete($oid);

	/**
	 * Return the current flush mode
	 *
	 * @return int
	 */
	public function getFlushMode()
	{
		return $this->flush_mode;
	}

	/**
	 * Set the current flush mode
	 *
	 * @param int $flushmode
	 */
	public function setFlushMode($flushmode)
	{
		$this->flush_mode = (int)$flushmode;
	}

	/**
	 * Reset the Entity Manger
	 * All unsaved changes to Entities are lost
	 *
	 * @return void
	 */
	public function clear()
	{
		$this->context = array();
		$this->managed = array();
		$this->removed = array();
	}

	/**
	 * Flush and close the Entity Manager
	 * Entity Manager becomes inaccessable after this
	 *
	 * @return void
	 */
	public function close()
	{
		if (!$this->open)
			return;

		if (($this->flush_mode & Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD) == Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD)
			$this->flush();
		$this->clear();
		$this->open = false;
	}

	/**
	 * Checks if the given Object is managed
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 */
	public function contains(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		$oid = $object->getOID();

		if (!array_key_exists($oid, $this->managed))
			return false;

		return ($object === $this->context[$oid]);
	}

	/**
	 * Get an Entity by Object ID
	 *
	 * @param string $oid
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function find($oid)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		if (array_key_exists($oid, $this->context))
		{
			unset($this->removed[$oid]);
			if (!array_key_exists($oid, $this->managed))
				$this->managed[$oid] = false;

			return $this->context[$oid];
		}

		$object = $this->load($oid);
		if (!$object || !($object instanceof Sabre_DAV_S3_IPersistable))
			return null;

		$object->markDirty(false);

		if ($object->_afterLoad($this) === false)
			return false;

		$this->context[$oid] = $object;
		$this->managed[$oid] = true;

		return $object;
	}

	/**
	 * Get an Entity by Class name and Key
	 *
	 * @param string $class
	 * @param array $key
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function findByKey($class, $key)
	{
		return $this->find($this->generateOID($class, $key));
	}

	/**
	 * Makes an Entity persistent
	 * Assigns a new persistence Object ID (OID) if new
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param bool $overwrite
	 * @return bool
	 */
	public function persist(Sabre_DAV_S3_IPersistable $object, $overwrite = false)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		$overwrite = isset($overwrite) ? (bool)$overwrite : false;

		if ($object->_beforePersist($this) === false)
			return false;

		$oid = $object->getOID();

		if (!isset($oid))
			$oid = $this->generateOID(get_class($object), $object->getKey());

		if (!$overwrite && array_key_exists($oid, $this->context) && $this->context[$oid] !== $object)
			throw new ErrorException("Entity Manager already has an object with that OID ($oid) in this context.");
		//@todo check if object exists in datastore?!

		if (is_null($object->getOID()))
			$this->setObjectProperty($object, 'oid', $oid);

		if (is_null($this->getObjectProperty($object, 'entity_created')))
			$this->setObjectProperty($object, 'entity_created', time());

		unset($this->removed[$oid]);
		$this->context[$oid] = $object;
		if (!array_key_exists($oid, $this->managed))
			$this->managed[$oid] = false;

		$result = true;
		if ($object->isDirty() && ($this->flush_mode & Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE) == Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE)
		{
			if ($object->_beforeSave($this) !== false)
			{
				$this->setObjectProperty($object, 'entity_lastmodified', time());
				$result = $this->save($object);
				if ($result)
				{
					$this->managed[$oid] = true;
					$object->markDirty(false);
					$result = $result & $object->_afterSave($this);
				}
			}
			else
				$result = false;
		}

		if ($object->_afterPersist($this) === false)
			return false;
		else
			return $result;
	}

	/**
	 * Delete the Entity from the data store
	 * Entity will still exist in the current persistence context, but changes are not saved anymore
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function remove(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		if (!in_array($object, $this->context, true))
			return false;

		if ($object->_beforeRemove($this) === false)
			return false;

		$oid = $object->getOID();

		unset($this->managed[$oid]);
		if (!array_key_exists($oid, $this->removed))
			$this->removed[$oid] = false;

		$result = true;
		if (($this->flush_mode & Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE) == Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE)
		{
			$result = $this->delete($oid);
			if ($result)
				$this->removed[$oid] = true;
		}

		if ($object->_afterRemove($this) === false)
			return false;
		else
			return $result;
	}

	/**
	 * Copy (merge) the state of one Entity into another
	 *
	 * @param Sabre_DAV_S3_IPersistable $destination
	 * @param Sabre_DAV_S3_IPersistable $source
	 * @return bool
	 */
	public function updateObjectState(Sabre_DAV_S3_IPersistable $destination, Sabre_DAV_S3_IPersistable $source)
	{
		if (get_class($source) !== get_class($destination))
			throw new ErrorException("Entity Manager cannot update the object state because the class types do not match.");

/*		if (is_null($destination->getOID()))
			throw new ErrorException("Entity Manager cannot update the object state because it has no OID.");

		if ($source->getOID() !== $destination->getOID())
			throw new ErrorException("Entity Manager cannot update the object state because the OIDs do not match.");*/

		$refclass = new ReflectionClass(get_class($destination));

		$properties = $destination->getPersistentProperties();
		foreach ($properties as $classprops)
		{
			foreach ($classprops as $property)
			{
				$refprop = $refclass->getProperty($property);
				$refprop->setAccessible(true);
				$refprop->setValue($destination, $refprop->getValue($source));
			}
		}

		return true;
	}

	/**
	 * Refresh a (managed, detached or new) Entity from the data store
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function modernize(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		if ($object->_beforeRefresh($this) === false)
			return false;

		$oid = $object->getOID();

		if (!isset($oid))
			$oid = $this->generateOID(get_class($object), $object->getKey());

		$persistentobj = $this->load($oid);
		if (!$persistentobj || !($persistentobj instanceof Sabre_DAV_S3_IPersistable))
			return false;

		$persistentobj->markDirty(false);

		if ($persistentobj->_afterLoad($this) === false)
			return false;

		if (!$this->updateObjectState($object, $persistentobj))
			return false;

		$object->markDirty(false);

		if ($object->_afterRefresh($this) === false)
			return false;
		else
			return true;
	}

	/**
	 * Refresh the Entity from it's persistent state
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function refresh(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->contains($object))
			return false;

		if (!$this->modernize($object))
			return false;

		return true;
	}

	/**
	 * Detach the Entity from the persistence context
	 * Changes after this will not be saved
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function detach(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		if (!in_array($object, $this->context, true))
			return false;

		if ($object->_beforeDetach($this) === false)
			return false;

		$oid = $object->getOID();

		unset($this->context[$oid]);
		unset($this->managed[$oid]);
		unset($this->removed[$oid]);

		if ($object->_afterDetach($this) === false)
			return false;
		else
			return true;
	}

	/**
	 * Merge the state of the given Entity into the current persistence context
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return Sabre_DAV_S3_IPersistable|bool the Entity the state was merged into
	 */
	public function merge(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		if (in_array($object, $this->context, true))
			return $object;
		//@todo if object is removed throw an error?!

		$newobject = $this->find($object->getOID());
		if (!$newobject)
			$newobject = clone $object;
		else
			$this->updateObjectState($newobject, $object);

		if ($this->persist($newobject))
			return $newobject;
		else
			return false;
	}

	/**
	 * Save all pending changes
	 *
	 * @return bool
	 */
	public function flush()
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		$result = true;

		foreach ($this->removed as $oid => &$value)
		{
			if (!$value)
			{
				$value = $this->delete($oid);
				$result = $result & $value;
			}
		}

		foreach ($this->managed as $oid => &$value)
		{
			$object = $this->context[$oid];

			if ($object->isDirty())
			{
				if ($object->_beforeSave($this) !== false)
				{
					$this->setObjectProperty($object, 'entity_lastmodified', time());
					$value = $this->save($object);
					if ($value)
					{
						$object->markDirty(false);
						$result = $object->_afterSave($this) & $result;
					}
					else
						$result = false;
				}
			}
		}

		return $result;
	}

	/**
	 * Remove all expired Entities
	 *
	 * @param int $age timestamp
	 * @param string $class
	 */
	public function expire($before, $class = null)
	{
		if (!$this->open)
			throw new ErrorException('Entity Manager is in an illegal state.');

		foreach ($this->managed as $oid => &$value)
		{
			$object = $this->context[$oid];

			if (isset($class) && get_class($object) !== $class)
				continue;

			$mtime = $this->getObjectProperty($object, 'entity_lastmodified');
			if (!isset($mtime))
				$mtime = $this->getObjectProperty('entity_created');

			if (isset($mtime) && $mtime < $before)
				$this->remove($object);
		}

		return true;
	}

	/**
	 * Get an array of all managed Entities in this persistence context
	 *
	 * @return Sabre_DAV_S3_IPersistable[]
	 */
	public function getManaged()
	{
		return array_intersect_key($this->context, $this->managed);
	}

	/**
	 * Get the creation time of an Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return mixed
	 */
	public function getCreationTime(Sabre_DAV_S3_IPersistable $object)
	{
		return $this->getObjectProperty($object, 'entity_created');
	}

	/**
	 * Get the last persistent modification time of an Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return mixed
	 */
	public function getLastModified(Sabre_DAV_S3_IPersistable $object)
	{
		return $this->getObjectProperty($object, 'entity_lastmodified');
	}
}
