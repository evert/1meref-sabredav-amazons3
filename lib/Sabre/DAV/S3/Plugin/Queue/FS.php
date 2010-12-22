<?php

/**
 * Filesystem implementation of a shared queue
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Plugin_Queue_FS implements Sabre_DAV_S3_Plugin_IQueue
{
	const HEADER_SIZE = 24;

	const HEADER_PAD = ' ';

	const EOL = "\n";

	static $TR_ENCODE = array("\\" => '\\\\', "\n" => '\n', "\r" => '\r');

	static $TR_DECODE = array('\\\\' => "\\", '\n' => "\n", '\r' => "\r");

	/**
	 * The file to store the queue in
	 *
	 * @var string
	 */
	protected $filename = null;

	/**
	 * The filehandle to the file to store the queue in
	 *
	 * @var resource
	 */
	protected $filehandle = null;

	/**
	 * The acquired locks in this instance
	 *
	 * @var array
	 */
	protected $locks = array();

	/**
	 * The maximum number of processes allowed to process the queue
	 *
	 * @var int
	 */
	protected $maxlocks = 1;

	/**
	 * Initialize the Queue
	 *
	 * @param string $filename
	 * @param int $maxlocks
	 * @return void
	 */
	public function __construct($filename, $maxlocks = 1)
	{
		$this->maxlocks = $maxlocks;

		$path = dirname($filename);
		if ($path == '.')
			$path = '';
		if (strpos($path, '/') !== 0 && strpos($path, '\\') !== 0 && strpos($path, ':') !== 1) //not an absolute path?
			$path = getcwd() . DIRECTORY_SEPARATOR . $path;

		if ($path !== '' && file_exists($path))
			$this->filename = $path . DIRECTORY_SEPARATOR . basename($filename);

		if (isset($this->filename) && !file_exists($this->filename))
			file_put_contents($this->filename, '', FILE_APPEND | LOCK_EX);
	}

	/**
	 * Free up resources and locks on destruction
	 *
	 * @return void
	 */
	public function __destruct()
	{
		if (!empty($this->locks))
		{
			foreach ($this->locks as $lock => $l)
				$this->releaseLock($lock);
		}
		if ($this->filehandle)
		{
			fflush($this->filehandle);
			flock($this->filehandle, LOCK_UN);
			fclose($this->filehandle);
		}
	}

	/**
	 * Try to acquire a lock for processing power
	 *
	 * @param int maxlocks
	 * @return int|bool the lock number or false if no lock is available
	 */
	public function acquireLock()
	{
		$locked = false;

		for ($i = 1; $i <= $this->maxlocks; $i++)
		{
			$lock = fopen($this->filename . '.' . $i . '.lock', 'w');
			$locked = flock($lock, LOCK_EX | LOCK_NB);
			if ($locked)
			{
				fwrite($lock, time());
				$this->locks[$i] = $lock;
				break;
			}
			else
				fclose($lock);
		}

		return $locked ? $i : false;
	}

	/**
	 * Release the lock for processing power
	 *
	 * @param $lock
	 * @return void
	 */
	public function releaseLock($lock)
	{
		if (isset($this->locks[$lock]))
		{
			flock($this->locks[$lock], LOCK_UN);
			fclose($this->locks[$lock]);
			@unlink($this->filename . '.' . $lock . '.lock');
			unset($this->locks[$lock]);
		}
	}

	/**
	 * Reads the position of the next item to dequeue from the header
	 *
	 * @return integer
	 */
	private function getCurrentPos()
	{
		$pos = 0;

		fseek($this->filehandle, 0, SEEK_SET);
		$header = fread($this->filehandle, self::HEADER_SIZE);
		if (isset($header) && $header !== false && strlen($header) == self::HEADER_SIZE)
			$pos = (integer)unserialize(rtrim($header, self::EOL . self::HEADER_PAD));
		if ($pos < self::HEADER_SIZE)
			$this->setNextPos($pos = self::HEADER_SIZE);

		return $pos;
	}

	/**
	 * Sets the position of the next item to dequeue in the header
	 *
	 * @param integer $pos
	 * @return void
	 */
	private function setNextPos($pos)
	{
		fseek($this->filehandle, 0, SEEK_SET);
		$header = str_pad(serialize((integer)$pos), self::HEADER_SIZE - 1, self::HEADER_PAD, STR_PAD_RIGHT) . self::EOL;
		fwrite($this->filehandle, $header, self::HEADER_SIZE);
	}

	/**
	 * Checks if the queue is empty
	 *
	 * @return bool
	 */
	public function isEmpty()
	{
		if (!isset($this->filename))
			return false;
		if (!isset($this->filehandle))
			$this->filehandle = fopen($this->filename, 'r+');

		flock($this->filehandle, LOCK_EX); // blocks until lock is acquired

		$pos = $this->getCurrentPos();
		fflush($this->filehandle);
		$size = fstat($this->filehandle);
		$size = (int)$size['size'];

		flock($this->filehandle, LOCK_UN);

		return $pos >= $size;
	}

	/**
	 * Add strings to the end of the queue
	 *
	 * @param $data mixed|array
	 * @return bool
	 */
	public function enqueue($data)
	{
		if (!isset($this->filename))
			return false;
		if (!isset($this->filehandle))
			$this->filehandle = fopen($this->filename, 'r+');

		if (!is_array($data))
			$data = array($data);

		flock($this->filehandle, LOCK_EX); // blocks until lock is acquired
		fseek($this->filehandle, 0, SEEK_END);
		if (ftell($this->filehandle) < self::HEADER_SIZE)
			$this->setNextPos(self::HEADER_SIZE);

		foreach ($data as $v)
			fwrite($this->filehandle, substr(strtr(serialize($v), self::$TR_ENCODE), 0, 8191) . self::EOL, 8192);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed returns false on failure or empty queue
	 */
	public function dequeue($lock)
	{
		if (!isset($this->locks[$lock]))
			return false;
		if (!isset($this->filename))
			return false;
		if (!isset($this->filehandle))
			$this->filehandle = fopen($this->filename, 'r+');

		flock($this->filehandle, LOCK_EX); // blocks until lock is acquired
		fseek($this->filehandle, $this->getCurrentPos(), SEEK_SET);

		$data = stream_get_line($this->filehandle, 8192, self::EOL);
		if (isset($data) && $data !== false && $data !== '')
			$this->setNextPos(ftell($this->filehandle));

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		if (isset($data) && $data !== false && $data !== '')
			return unserialize(strtr($data, self::$TR_DECODE));
		else
			return false;
	}

	/**
	 * reorganize the queue file
	 *
	 * @param $lock a valid lock
	 * @return bool
	 */
	public function reorganize($lock)
	{
		if (!isset($this->locks[$lock]))
			return false;
		if (!isset($this->filename))
			return false;
		if (!isset($this->filehandle))
			$this->filehandle = fopen($this->filename, 'r+');

		flock($this->filehandle, LOCK_EX); // blocks until lock is acquired

		$pos = $this->getCurrentPos();
		if ($pos > self::HEADER_SIZE)
		{
			fseek($this->filehandle, $pos, SEEK_SET);
			$fh = fopen('php://temp', 'w+');
			stream_copy_to_stream($this->filehandle, $fh);
			$this->setNextPos(self::HEADER_SIZE);
			rewind($fh);
			stream_copy_to_stream($fh, $this->filehandle);
			fclose($fh);
			ftruncate($this->filehandle, ftell($this->filehandle));
		}

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}

	/**
	 * clear the queue file
	 *
	 * @param $lock a valid lock
	 * @return bool
	 */
	public function clear($lock)
	{
		if (!isset($this->locks[$lock]))
			return false;
		if (!isset($this->filename))
			return false;
		if (!isset($this->filehandle))
			$this->filehandle = fopen($this->filename, 'r+');

		flock($this->filehandle, LOCK_EX); // blocks until lock is acquired

		$this->setNextPos(self::HEADER_SIZE);
		ftruncate($this->filehandle, self::HEADER_SIZE);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}
}
