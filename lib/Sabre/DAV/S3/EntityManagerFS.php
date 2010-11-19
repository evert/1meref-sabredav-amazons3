<?php

/**
 * Filesystem implementation of Entity Manager for persistable Objects
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_EntityManagerFS extends Sabre_DAV_S3_EntityManager
{
	/**
	 * Prefix for file names
	 *
	 * @var string
	 */
	const FILE_PREFIX = '';

	/**
	 * Extension for filenames
	 *
	 * @var string
	 */
	const FILE_EXTENSION = '.entity';

	/**
	 * The directory to store Entities in
	 *
	 * @var string
	 */
	protected $datadir = null;

	/**
	 * Initialize the Entity Manager
	 *
	 * @param string $datadir
	 * @param int $ormstrategy
	 * @param int $flushmode
	 * @return void
	 */
	public function __construct($datadir, $ormstrategy = Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE, $flushmode = Sabre_DAV_S3_EntityManagerFS::FLUSH_UNLOAD)
	{
		$path = rtrim($datadir, '/\\');
		if (strpos($datadir, '/') !== 0 && strpos($datadir, '\\') !== 0 && strpos($datadir, ':') !== 1)
		{
			$basepath = dirname($_SERVER['SCRIPT_FILENAME']);
			$path = $basepath . DIRECTORY_SEPARATOR . $path;
		}
		if ($path !== '' && file_exists($path))
		{
			$this->datadir = $path;
			$this->isopen = true;
		}

		$this->ormstrategy = $ormstrategy;
		$this->setFlushMode($flushmode);
	}

	/**
	 * Get the file directory for a given Object ID
	 *
	 * @param string $oid
	 * @return string
	 */
	protected function getFileDir($oid)
	{
		$class_id = explode(':', $oid);

		switch ($this->ormstrategy)
		{
			case Sabre_DAV_S3_IEntityManager::ORM_SINGLE_TABLE:
				return $this->datadir;
			case Sabre_DAV_S3_IEntityManager::ORM_CONCRETE_CLASS:
				return $this->datadir . DIRECTORY_SEPARATOR . $class_id[0];
			default:
				return null;
		}
	}

	/**
	 * Get the file name for a given Object ID
	 *
	 * @param string $oid
	 * @return string
	 */
	protected function getFileName($oid)
	{
		$dir = $this->getFileDir($oid);

		if ($dir)
			return $dir . DIRECTORY_SEPARATOR . Sabre_DAV_S3_EntityManagerFS::FILE_PREFIX . str_replace(':', '-', $oid) . Sabre_DAV_S3_EntityManagerFS::FILE_EXTENSION;
		else
			return null;
	}

	/**
	 * Load the Entity with the given Object ID
	 *
	 * @param string $oid
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	protected function load($oid)
	{
		$filename = $this->getFileName($oid);

		if (!file_exists($filename))
			return false;

		$data = file_get_contents($filename);
		if ($data === false)
			throw new ErrorException("Entity Manager cannot read the file ($filename)");
			//return false;


		$object = unserialize($data);

		return $object;
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
		$filedir = $this->getFileDir($oid);
		$filename = $this->getFileName($oid);

		if (!file_exists($filedir))
			mkdir($filedir, 0777, true);

		$fh = fopen($filename, 'w');
		if ($fh === false)
			throw new ErrorException("Entity Manager cannot create the file ($filename)");
		if (fwrite($fh, serialize($object)) === false)
			throw new ErrorException("Entity Manager cannot write the file ($filename)");
		fclose($fh);

		return true;
	}

	/**
	 * Delete the given Entity by Object ID
	 *
	 * @param string $oid
	 */
	protected function delete($oid)
	{
		return @unlink($this->getFileName($oid));
	}
}
