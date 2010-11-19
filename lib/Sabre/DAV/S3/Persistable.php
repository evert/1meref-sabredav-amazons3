<?php

/**
 * Abstract base class for persistable Objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
abstract class Sabre_DAV_S3_Persistable implements Sabre_DAV_S3_IPersistable
{
	/**
	 * Persistence Entity Manager
	 *
	 * @var Sabre_DAV_S3_IEntityManager
	 */
	private static $entitymanager = null;

	/**
	 * The node's Object ID
	 *
	 * @var mixed
	 */
	protected $oid = null;

	/**
	 * The timestamp when the Entity was created
	 *
	 * @var int
	 */
	protected $entity_created = null;

	/**
	 * The timestamp when the Entity was last modified
	 *
	 * @var int
	 */
	protected $entity_lastmodified = null;

	/**
	 * Did the node's state change?
	 *
	 * @var bool
	 */
	protected $dirty = true;

	/**
	 * Returns the Entity Manager
	 *
	 * @return Sabre_DAV_S3_IEntityManager
	 */
	public static final function getEntityManager()
	{
		$class = __CLASS__;
		return $class::$entitymanager;
	}

	/**
	 * Sets the Entity Manager
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return void
	 */
	public static final function setEntityManager(Sabre_DAV_S3_IEntityManager $entitymanager = null)
	{
		$class = __CLASS__;
		if (isset($entitymanager) && !isset($class::$entitymanager))
			$class::$entitymanager = $entitymanager;
	}

	/**
	 * Find the Object by Key or create a new Instance
	 * Parameters after $key have to match the constructor
	 *
	 * @param string $class
	 * @param array $key
	 * @return Sabre_DAV_S3_IPersistable
	 */
	public static function getInstanceByKey($class, $key)
	{
		$object = null;

		if (self::getEntityManager())
			$object = self::getEntityManager()->findByKey($class, $key);

		if (!$object)
		{
			$args = func_get_args();
			$refobj = new ReflectionClass($class);
			$object = $refobj->newInstanceArgs(array_slice($args, 2));
			$object->persist();
		}

		return $object;
	}

	/**
	 * Initialize the class
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return void
	 */
	public function __construct(Sabre_DAV_S3_IEntityManager $entitymanager = null)
	{
		$this->setEntityManager($entitymanager);
	}

	/**
	 * Return the list of properties to save
	 *
	 * @return array
	 */
	public function __sleep()
	{
		$properties = array();
		$classproperties = $this->getPersistentProperties();
		foreach ($classproperties as $prop)
			$properties = array_merge($prop, $properties);

		return $properties;
	}

	/**
	 * Returns the node's Object ID
	 * In the form of "Class:id"
	 *
	 * @return string
	 */
	public final function getOID()
	{
		return $this->oid;
	}

	/**
	 * Returns the node's Key
	 *
	 * @return array
	 */
	public function getKey()
	{
		return array('oid' => $this->oid);
	}

	/**
	 * Returns the property names to persist in a two dimensional array with the first array key being __CLASS__ and the second array a list of property names for that class.
	 * Every subclass with new properties to persist has to overwrite this function and return the merged array with it's parent class
	 *
	 * @return array
	 */
	public function getPersistentProperties()
	{
		return array
		(
			__CLASS__ => array
			(
				'oid',
				'entity_created',
				'entity_lastmodified'
			)
		);
	}

	/**
	 * Returns true if the Object was modified
	 *
	 * @return bool
	 */
	public final function isDirty()
	{
		return $this->dirty;
	}

	/**
	 * Marks the Object as dirty
	 *
	 * @return void
	 */
	public final function markDirty($dirty = true)
	{
		$this->dirty = !isset($dirty) ? true : (bool)$dirty;
	}

	/**
	 * Make the Object persistent
	 *
	 * @param bool $overwrite
	 * @return bool
	 */
	public final function persist($overwrite = false)
	{
		if (!$this->getEntityManager())
			return false;

		return $this->getEntityManager()->persist($this, $overwrite);
	}

	/**
	 * Refresh the Object from the persistent state, even if the object is not managed
	 *
	 * @return bool
	 */
	public final function modernize()
	{
		if (!$this->getEntityManager())
			return false;

		return $this->getEntityManager()->modernize($this);
	}

	/**
	 * Refresh the Object from it's persistent state
	 *
	 * @return bool
	 */
	public final function refresh()
	{
		if (!$this->getEntityManager())
			return false;

		return $this->getEntityManager()->refresh($this);
	}

	/**
	 * Detach the Object from the persistence context
	 *
	 * @return bool
	 */
	public final function detach()
	{
		if (!$this->getEntityManager())
			return false;

		return $this->getEntityManager()->detach($this);
	}

	/**
	 * Delete the saved Object
	 *
	 * @return bool
	 */
	public final function remove()
	{
		if (!$this->getEntityManager())
			return false;

		return $this->getEntityManager()->remove($this);
	}

	/**
	 * Gets called just before saving the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeSave(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just after saving the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterSave(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just after loading the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterLoad(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just before the Object becomes persistent
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforePersist(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just after the Object became persistent
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterPersist(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just before refreshing the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeRefresh(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just after the Object was refreshed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterRefresh(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just before the Object is detached
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeDetach(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just after the Object was detached
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterDetach(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just before the Object is removed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeRemove(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}

	/**
	 * Gets called just after the Object was removed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterRemove(Sabre_DAV_S3_IEntityManager $entitymanager)
	{
		return true;
	}
}
