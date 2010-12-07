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
	 * The current Object Relational Mapping (ORM) strategy used
	 *
	 * @var int
	 */
	protected $ormstrategy = Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE;

	/**
	 * The current flush mode
	 *
	 * @var int
	 */
	protected $flushmode = Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD;

	/**
	 * Is the Entity manager accessable?
	 *
	 * @var bool
	 */
	protected $isopen = false;

	/**
	 * Holds all currently managed Entities
	 *
	 * @var Sabre_DAV_S3_IPersistable[]
	 */
	protected $managed = array();

	/**
	 * Holds the ids of all newly persistent Entities
	 *
	 * @var string[]
	 */
	protected $add = array();

	/**
	 * Holds the ids of all to be deleted Entities
	 *
	 * @var string[]
	 */
	protected $remove = array();

	/**
	 * Holds a clone of all newly persistent Entities which were detached
	 *
	 * @var Sabre_DAV_S3_IPersistable[]
	 */
	protected $add_detached = array();

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
		$this->isopen = true;
	}

	/**
	 * Close and flush at the end of the persistence context
	 *
	 * @return void
	 */
	public function __destruct()
	{
		if ($this->isopen)
			$this->close();
	}

	/**
	 * Creates a unique ID for the Object from it's Key
	 *
	 * @param $key
	 * @param $class
	 * @return string
	 */
	protected function generateOID($class, $key)
	{
		if (array_key_exists('oid', $key))
			throw new ErrorException('Entity Manager cannot create generic Object IDs with the oid itself as part of the key');

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
		return $this->flushmode;
	}

	/**
	 * Set the current flush mode
	 *
	 * @param int $flushmode
	 */
	public function setFlushMode($flushmode)
	{
		$this->$flushmode = (int)$flushmode;
	}

	/**
	 * Reset the Entity Manger
	 * All unsaved changes to Entities are lost
	 *
	 * @return void
	 */
	public function clear()
	{
		$this->managed = array();
		$this->add = array();
		$this->remove = array();
		$this->add_detached = array();
	}

	/**
	 * Flush and close the Entity Manager
	 * Entity Manager becomes inaccessable after this
	 *
	 * @return void
	 */
	public function close()
	{
		if (!$this->isopen)
			return;

		if (($this->flushmode & Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD) == Sabre_DAV_S3_IEntityManager::FLUSH_UNLOAD)
			$this->flush();
		$this->clear();
		$this->isopen = false;
	}

	/**
	 * Checks if the given Object is managed
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 */
	public function contains(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		$oid = $object->getOID();

		if (!array_key_exists($oid, $this->managed))
			return false;

		return ($object === $this->managed[$oid]);
	}

	/**
	 * Get an Entity by Object ID
	 *
	 * @param string $oid
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function find($oid)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		if (array_key_exists($oid, $this->add_detached))
		{
			$this->managed[$oid] = $this->add_detached[$oid];
			unset($this->add_detached[$oid]);
		}

		if (array_key_exists($oid, $this->managed))
			return $this->managed[$oid];

		$object = $this->load($oid);
		if (!$object || !($object instanceof Sabre_DAV_S3_IPersistable))
			return false;
		$object->markDirty(false);
		if ($object->_afterLoad($this) === false)
			return false;

		$this->managed[$oid] = $object;

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
		$oid = $this->generateOID($class, $key);

		return $this->find($oid);
	}

	/**
	 * Makes an Entity managed
	 * If the Entity has no oid the the Entity is made persistent
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param bool $overwrite
	 */
	public function manage(Sabre_DAV_S3_IPersistable $object, $overwrite = false)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		$overwrite = isset($overwrite) ? (bool)$overwrite : false;

		$oid = $object->getOID();

		if (!isset($oid))
			return $this->persist($object, $overwrite);

		if (!$overwrite && array_key_exists($oid, $this->managed) && $this->managed[$oid] !== $object)
			throw new ErrorException("Entity Manager already manages an object with that oid ($oid) in this context");

		$this->managed[$oid] = $object;

		return true;
	}

	/**
	 * Makes an Entity persistent
	 * Assigns a new persistence Object ID if new
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @param bool $overwrite
	 * @return bool
	 */
	public function persist(Sabre_DAV_S3_IPersistable $object, $overwrite = false)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		$overwrite = isset($overwrite) ? (bool)$overwrite : false;

		if ($object->_beforePersist($this) === false)
			return false;

		if (is_null($this->getObjectProperty($object, 'entity_created')))
			$this->setObjectProperty($object, 'entity_created', time());

		$oid = $object->getOID();
		$class = get_class($object);

		if (!isset($oid))
		{
			$oid = $this->generateOID($class, $object->getKey());
			$this->setObjectProperty($object, 'oid', $oid);
		}

		if (!$overwrite && array_key_exists($oid, $this->managed) && $this->managed[$oid] !== $object)
			throw new ErrorException("Entity Manager already manages an object with that oid ($oid) in this context");

		$offset = array_search($oid, $this->remove);
		if ($offset !== false)
			array_splice($this->remove, $offset, 1);

		$offset = array_search($oid, $this->add);
		if ($offset !== false)
			array_splice($this->add, $offset, 1);

		if (array_key_exists($oid, $this->add_detached))
			unset($this->add_detached[$oid]);

		$this->managed[$oid] = $object;

		$result = false;
		if (($this->flushmode & Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE) == Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE)
		{
			if ($object->_beforeSave($this) !== false)
			{
				$this->setObjectProperty($object, 'entity_lastmodified', time());
				$result = $this->save($object);
				if ($result)
					$object->markDirty(false);
				$result = $result & $object->_afterSave($this);
			}
		}
		else
		{
			array_push($this->add, $oid);
			$result = true;
		}

		if ($object->_afterPersist($this) === false)
			return false;
		else
			return $result;
	}

	/**
	 * Make a copy (clone) of the Entity persistent
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	public function merge(Sabre_DAV_S3_IPersistable $object)
	{
		$newobject = clone $object;

		if ($this->persist($newobject, true))
			return $newobject;
		else
			return false;
	}

	/**
	 * Refresh the Entity from the persistent state, even if the Entity is not managed
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function modernize(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		if ($object->_beforeRefresh($this) === false)
			return false;

		$oid = $object->getOID();
		$class = get_class($object);

		if (!isset($oid))
		{
			$oid = $this->generateOID($class, $object->getKey());
			$this->setObjectProperty($object, 'oid', $oid);
		}

		$persistentobj = $this->load($oid);
		if (!$persistentobj || !($persistentobj instanceof Sabre_DAV_S3_IPersistable))
			return false;

		$persistentobj->markDirty(false);

		if ($persistentobj->_afterLoad($this) === false)
			return false;

		if (get_class($persistentobj) !== $class)
			throw new ErrorException("Entity Manager cannot refresh the object because the classes do not match");

		$refobj = new ReflectionObject($object);

		$properties = $object->getPersistentProperties();
		foreach ($properties as $classprops)
		{
			foreach ($classprops as $property)
			{
				$refprop = $refobj->getProperty($property);
				$refprop->setAccessible(true);
				$refprop->setValue($object, $refprop->getValue($persistentobj));
			}
		}

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
			throw new ErrorException('Entity Manager received a refresh request for an unmanaged object');

		if (!$this->modernize($object))
			return false;

		$offset = array_search($object->getOID(), $this->add);
		if ($offset !== false)
			array_splice($this->add, $offset, 1);

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
		if (!$this->contains($object))
			throw new ErrorException('Entity Manager received a detach request for an unmanaged object');

		if ($object->_beforeDetach($this) === false)
			return false;

		$oid = $object->getOID();

		if (in_array($oid, $this->add))
		{
			$newobject = clone $object;
			$this->add_detached[$oid] = $newobject;
		}

		unset($this->managed[$oid]);

		if ($object->_afterDetach($this) === false)
			return false;
		else
			return true;
	}

	/**
	 * Delete the saved Entity
	 * Entity will still exist in the current persistence context, but changes not saved anymore
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	public function remove(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->contains($object))
			throw new ErrorException('Entity Manager received a remove request for an unmanaged object');

		if ($object->_beforeRemove($this) === false)
			return false;

		$oid = $object->getOID();
		$class = get_class($object);

		$offset = array_search($oid, $this->remove);
		if ($offset !== false)
			array_splice($this->remove, $offset, 1);

		$offset = array_search($oid, $this->add);
		if ($offset !== false)
			array_splice($this->add, $offset, 1);

		$result = true;
		if (($this->flushmode & Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE) == Sabre_DAV_S3_IEntityManager::FLUSH_IMMEDIATE)
			$result = $this->delete($oid);
		else
			array_push($this->remove, $oid);

		if ($object->_afterRemove($this) === false)
			return false;
		else
			return $result;
	}

	/**
	 * Save all pending changes
	 *
	 * @return bool
	 */
	public function flush()
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		$result = true;

		foreach ($this->remove as $oid)
		{
			$result = $result & $this->delete($oid);
		}
		$this->remove = array();

		foreach ($this->add_detached as $object)
		{
			if ($object->_beforeSave($this) !== false)
			{
				$this->setObjectProperty($object, 'entity_lastmodified', time());
				$r = $this->save($object);
				if ($r)
					$object->markDirty(false);
				$r = $r & $object->_afterSave($this);
				$result = $result & $r;
			}
		}
		$this->add_detached = array();

		foreach ($this->managed as $object)
		{
			if (in_array($object->getOID(), $this->add) || $object->isDirty())
			{
				if ($object->_beforeSave($this) !== false)
				{
					$this->setObjectProperty($object, 'entity_lastmodified', time());
					$r = $this->save($object);
					if ($r)
						$object->markDirty(false);
					$r = $r & $object->_afterSave($this);
					$result = $result & $r;
				}
			}
		}
		$this->add = array();

		return $result;
	}

	/**
	 * Remove all expired Entities
	 *
	 * @param int $before timestamp
	 * @param string $class
	 */
	public function expire($before, $class = null)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		foreach ($this->managed as $object)
		{
			if (!empty($class) && get_class($object) !== $class)
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
		return $this->managed;
	}

	/**
	 * Get the creation time of an Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return mixed
	 */
	public function getCreationTime(Sabre_DAV_S3_IPersistable $object)
	{
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

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
		if (!$this->isopen)
			throw new ErrorException('Entity Manager is in an illegal state');

		return $this->getObjectProperty($object, 'entity_lastmodified');
	}
}
