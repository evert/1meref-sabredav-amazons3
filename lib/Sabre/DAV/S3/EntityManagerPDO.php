<?php

/**
 * PDO implementation of Entity Manager for persistable Objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_EntityManagerPDO extends Sabre_DAV_S3_EntityManager
{
	/**
	 * Prefix for table names
	 *
	 * @var string
	 */

	const TABLE_PREFIX = 'EMPDO_';
	/**
	 * The PDO Object
	 *
	 * @var PDO
	 */
	protected $pdo = null;

	/**
	 * Initialize the Entity Manager
	 *
	 * @param PDO $pdo
	 * @param int $ormstrategy
	 * @param int $flushmode
	 * @return void
	 */
	public function __construct(PDO $pdo, $ormstrategy = Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE, $flushmode = Sabre_DAV_S3_EntityManagerFS::FLUSH_UNLOAD)
	{
		if (isset($pdo))
		{
			$this->pdo = $pdo;
			if ($pdo->query('SELECT 1;') !== false)
				$this->isopen = true;
		}

		$this->ormstrategy = $ormstrategy;
		$this->setFlushMode($flushmode);
	}

	/**
	 * Returns the table name for a given Object ID
	 *
	 * @param string $oid
	 * @return string
	 */
	protected function getTableName($oid)
	{
		switch ($this->ormstrategy)
		{
			case Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE:
				return self::TABLE_PREFIX . 'Object';
			case Sabre_DAV_S3_IEntityManager::ORM_CONCRETE_CLASS:
				$class = explode(':', $oid);
				return self::TABLE_PREFIX . $class[0];
			default:
				return null;
		}
	}

	/**
	 * Creates the table for a given Object if it not already exists
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	protected function createTable(Sabre_DAV_S3_IPersistable $object)
	{
		$oid = $object->getOID();
		$table = $this->getTableName($oid);

		$sql = 'CREATE TABLE IF NOT EXISTS ' . $table . '
			(
				oid VARCHAR(255) NOT NULL PRIMARY KEY,
				object BLOB NULL DEFAULT NULL,
				created TIMESTAMP NULL DEFAULT NULL,
				lastmodified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			)
			CHARACTER SET utf8 COLLATE utf8_general_ci;';

		if ($this->pdo->exec($sql) === false)
			throw new ErrorException("Entity Manager cannot access the database");

		return true;
	}

	/**
	 * Load the Entity with the given Object ID
	 *
	 * @param string $oid
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	protected function load($oid)
	{
		$sql = 'SELECT object FROM ' . $this->getTableName($oid) . ' WHERE oid = :oid;';
		$qry = $this->pdo->prepare($sql);
		if ($qry->execute(array(':oid' => $oid)) === false)
			return false;

		$data = $qry->fetch(PDO::FETCH_ASSOC);
		if ($data === false)
			return false;

		return unserialize($data['object']);
	}

	/**
	 * Save the given Entity
	 *
	 * @param Sabre_DAV_S3_IPersistable $object
	 * @return bool
	 */
	protected function save(Sabre_DAV_S3_IPersistable $object)
	{
		$oid = $object->getOID();

		$data = serialize($object);
		if (!$data)
			throw new ErrorException("Entity Manager cannot serialize the Object ($oid)");

		$sql = 'UPDATE ' . $this->getTableName($oid) . ' SET object = :object WHERE oid = :oid;';
		$qry = $this->pdo->prepare($sql);
		if ($qry->execute(array(':oid' => $oid, ':object' => $data)) === false)
		{
			if (!$this->createTable($object))
				throw new ErrorException("Entity Manager cannot access the database");

			if ($qry->execute(array(':oid' => $oid, ':object' => $data)) === false)
				throw new ErrorException("Entity Manager cannot access the database");
		}

		if ($qry->rowCount() < 1)
		{
			$sql = 'INSERT INTO ' . $this->getTableName($oid) . ' (oid, object, created) VALUES (:oid, :object, NOW());';
			$qry = $this->pdo->prepare($sql);
			if ($qry->execute(array(':oid' => $oid, ':object' => $data)) === false)
				throw new ErrorException("Entity Manager cannot access the database");
			
			if ($qry->rowCount() < 1)
				throw new ErrorException("Entity Manager cannot insert the Object ($oid)");
		}

		return true;
	}

	/**
	 * Delete the given Entity by Object ID
	 *
	 * @param string $oid
	 */
	protected function delete($oid)
	{
		$sql = 'DELETE FROM ' . $this->getTableName($oid) . ' WHERE oid = :oid;';
		$qry = $this->pdo->prepare($sql);
		if ($qry->execute(array(':oid' => $oid)) === false)
			return false;

		return ($qry->rowCount() > 0);
	}
}
