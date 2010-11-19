<?php

/**
 * Interface for persistable objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_S3_IPersistable
{

	/**
	 * Returns the Object's Entity Manager
	 *
	 * @return Sabre_DAV_S3_IEntityManager
	 */
	public static function getEntityManager();

	/**
	 * Sets the Object's Entity Manager
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return void
	 */
	public static function setEntityManager(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Find the Object by Key or create a new Instance
	 * Parameters after $key have to match the constructor
	 *
	 * @param string $class
	 * @param array $key
	 * @return Sabre_DAV_S3_IPersistable
	 */
	public static function getInstanceByKey($class, $key);

	/**
	 * Returns the unique Object ID
	 * In the form of "Class:id"
	 *
	 * @return string
	 */
	public function getOID();

	/**
	 * Returns the Object's Key. Associative array with key and value.
	 *
	 * @return array
	 */
	public function getKey();

	/**
	 * Returns the property names to persist in a two dimensional array with the first array key being __CLASS__ and the second array a list of property names for that class.
	 * Every subclass with new properties to persist has to overwrite this function and return the merged array with it's parent class.
	 * Private properties cannot be saved.
	 *
	 * @return array
	 */
	public function getPersistentProperties();

	/**
	 * Returns true if the Object was modified
	 *
	 * @return bool
	 */
	public function isDirty();

	/**
	 * Marks the Object as dirty
	 *
	 * @return void
	 */
	public function markDirty();

	/**
	 * Make the Object persistent
	 *
	 * @param bool $overwrite
	 * @return bool
	 */
	public function persist();

	/**
	 * Refresh the Object from the persistent state, even if the object is not managed
	 *
	 * @return bool
	 */
	public function modernize();

	/**
	 * Refresh the Object from it's persistent state
	 *
	 * @return bool
	 */
	public function refresh();

	/**
	 * Detach the Object from the persistence context
	 *
	 * @return bool
	 */
	public function detach();

	/**
	 * Delete the saved Object
	 *
	 * @return bool
	 */
	public function remove();

	/**
	 * Gets called just before saving the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeSave(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just after saving the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterSave(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just after loading the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterLoad(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just before the Object becomes persistent
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforePersist(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just after the Object became persistent
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterPersist(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just before refreshing the Object
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeRefresh(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just after the Object was refreshed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterRefresh(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just before the Object is detached
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeDetach(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just after the Object was detached
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterDetach(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just before the Object is removed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _beforeRemove(Sabre_DAV_S3_IEntityManager $entitymanager);

	/**
	 * Gets called just after the Object was removed
	 *
	 * @param Sabre_DAV_S3_IEntityManager $entitymanager
	 * @return bool
	 */
	public function _afterRemove(Sabre_DAV_S3_IEntityManager $entitymanager);
}
