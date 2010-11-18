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
	 * @param int $flushmode
	 * @return void
	 */
	public function __construct($datadir, $flushmode = Sabre_DAV_S3_EntityManagerFS::FLUSH_UNLOAD)
	{
		$datadir = rtrim($datadir, '/\\');
		if ($datadir !== '' && file_exists($datadir))
		{
			$this->datadir = $datadir . DIRECTORY_SEPARATOR;
			$this->isopen = true;
		}

		$this->setFlushMode($flushmode);
	}

	/**
	 * Get the file name for a given id
	 *
	 * @param string $id
	 * @return string
	 */
	protected function getFileName($id)
	{
		return $this->datadir . Sabre_DAV_S3_EntityManagerFS::FILE_PREFIX . str_replace(':', DIRECTORY_SEPARATOR, $id) . Sabre_DAV_S3_EntityManagerFS::FILE_EXTENSION;
	}

	/**
	 * Load the Entity with the given id
	 *
	 * @param string $id
	 * @return Sabre_DAV_S3_IPersistable|bool
	 */
	protected function load($id)
	{
		$filename = $this->getFileName($id);

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
		$id = $object->getID();
		$filename = $this->getFileName($id);
		$dir = explode(':', $id);
		$dir = $dir[0];

		if (!file_exists($this->datadir . $dir))
			mkdir($this->datadir . $dir);

		$fh = fopen($filename, 'w');
		if ($fh === false)
			throw new ErrorException("Entity Manager cannot create the file ($filename)");
		if (fwrite($fh, serialize($object)) === false)
			throw new ErrorException("Entity Manager cannot write the file ($filename)");
		fclose($fh);

		return true;
	}

	/**
	 * Delete the given Entity
	 *
	 * @param string $id
	 */
	protected function delete($id)
	{
		return @unlink($this->getFileName($id));
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

		$classes = array();
		if (!isset($class))
		{
			foreach (glob($this->datadir . '*') as $dir)
				if (is_dir($dir) && $dir !== '.' && $dir !== '..')
					array_push($classes, basename($dir));
		}
		else
			$classes = array($class);

		foreach ($classes as $class)
		{
			foreach (glob($this->datadir . $class . DIRECTORY_SEPARATOR . Sabre_DAV_S3_EntityManagerFS::FILE_PREFIX . '*' . Sabre_DAV_S3_EntityManagerFS::FILE_EXTENSION) as $filename)
			{
				$mtime = filemtime($filename);
				if ($mtime !== false && $mtime < $before)
					@unlink($filename);
			}
		}

		return true;
	}
}
