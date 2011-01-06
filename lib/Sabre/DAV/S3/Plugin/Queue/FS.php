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
	const DATA_MAXSIZE = 8192; // including EOL

	const HEADER_SIZE = 128; // including EOL

	const HEADER_PAD = ' ';

	const EOL = "\n";

	const EOL_SIZE = 1;

	static $HEADER = array('cnt' => 0, 'fst' => self::HEADER_SIZE, 'end' => self::HEADER_SIZE);

	static $TR_ENCODE = array("\\" => '\\\\', "\n" => '\n', "\r" => '\r', "\0" => '\0');

	static $TR_DECODE = array('\\\\' => "\\", '\n' => "\n", '\r' => "\r", '\0' => "\0");

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
		foreach ($this->locks as $lock => $l)
			$this->releaseLock($lock);
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
	 * Reads the header
	 *
	 * @return integer
	 */
	private function getHeader()
	{
		$pos = 0;
		$size = 0;

		fseek($this->filehandle, 0, SEEK_SET);
		$header = fread($this->filehandle, self::HEADER_SIZE);
		if ($header !== false && strlen($header) == self::HEADER_SIZE)
			$header = unserialize(rtrim(substr($header, 0, -self::EOL_SIZE), self::HEADER_PAD));
		else
			$header = null;

		if (!$header)
		{
			$header = self::$HEADER;
			$this->setHeader($header);
			ftruncate($this->filehandle, self::HEADER_SIZE);
		}

		return $header;
	}

	/**
	 * Writes the header
	 *
	 * @param integer $pos
	 * @return void
	 */
	private function setHeader($header)
	{
		fseek($this->filehandle, 0, SEEK_SET);
		$header = str_pad(serialize($header), self::HEADER_SIZE - self::EOL_SIZE, self::HEADER_PAD, STR_PAD_RIGHT) . self::EOL;
		$written = fwrite($this->filehandle, $header, self::HEADER_SIZE);
		if ($written === false)
		{
			flock($this->filehandle, LOCK_UN);
			throw new ErrorException('Queue write error!');
		}
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

		$header = $this->getHeader();
		fflush($this->filehandle);

		flock($this->filehandle, LOCK_UN);

		return $header['cnt'] <= 0;
	}

	/**
	 * Add data to the end of the queue
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
		$header = $this->getHeader();
		fseek($this->filehandle, $header['end'], SEEK_SET);

		foreach ($data as $v)
		{
			$v = strtr(serialize($v), self::$TR_ENCODE);
			if (strlen($v) > self::DATA_MAXSIZE - self::EOL_SIZE)
			{
				flock($this->filehandle, LOCK_UN);
				throw new ErrorException('Queue data too large!');
			}
			$written = fwrite($this->filehandle, $v . self::EOL, self::DATA_MAXSIZE);
			if ($written === false)
			{
				flock($this->filehandle, LOCK_UN);
				throw new ErrorException('Queue write error!');
			}
			$header['end'] +=  $written;
			$header['cnt']++;
		}

		$this->setHeader($header);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}

	/**
	 * Add data to the end of the queue
	 *
	 * @param $data mixed|array
	 * @return bool
	 */
	public function push($data)
	{
		return $this->enqueue($data);
	}

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed The first queue entry or false on failure
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
		$header = $this->getHeader();
		if ($header['cnt'] <= 0)
		{
			fflush($this->filehandle);
			flock($this->filehandle, LOCK_UN);
			return false;
		}

		fseek($this->filehandle, $header['fst'], SEEK_SET);
		$data = stream_get_line($this->filehandle, self::DATA_MAXSIZE, self::EOL);
		if ($data === false || $data === '')
		{
			flock($this->filehandle, LOCK_UN);
			throw new ErrorException('Queue read error!');
		}
		$header['fst'] += strlen($data) + self::EOL_SIZE;
		$header['cnt']--;

		$this->setHeader($header);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return unserialize(strtr($data, self::$TR_DECODE));
	}

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed The first queue entry or false on failure
	 */
	public function shift($lock)
	{
		return $this->dequeue($lock);
	}

	/**
	 * Get the last entry and delete it from the queue
	 *
	 * @param $lock a valid lock
	 * @return mixed The last queue entry or false on failure
	 */
	public function pop($lock)
	{
		if (!isset($this->locks[$lock]))
			return false;
		if (!isset($this->filename))
			return false;
		if (!isset($this->filehandle))
			$this->filehandle = fopen($this->filename, 'r+');

		flock($this->filehandle, LOCK_EX); // blocks until lock is acquired
		$header = $this->getHeader();
		if ($header['cnt'] <= 0)
		{
			fflush($this->filehandle);
			flock($this->filehandle, LOCK_UN);
			return false;
		}

		$seekto = max($header['fst'], $header['end'] - self::DATA_MAXSIZE);
		fseek($this->filehandle, $seekto, SEEK_SET);
		$data = fread($this->filehandle, min($header['end'] - $header['fst'], self::DATA_MAXSIZE));
		if ($data === false || $data === '')
		{
			flock($this->filehandle, LOCK_UN);
			throw new ErrorException('Queue read error!');
		}

		$start = strrpos($data, self::EOL, -self::EOL_SIZE - 1);
		if ($start === false)
			$start = 0;
		else
			$start += self::EOL_SIZE;
		$data = substr($data, $start);
		$header['end'] -= strlen($data);
		$header['cnt']--;

		$this->setHeader($header);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return unserialize(strtr(substr($data, 0, -self::EOL_SIZE), self::$TR_DECODE));
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
		$header = $this->getHeader();
		fflush($this->filehandle);
		$size = fstat($this->filehandle);
		if ($size)
			$size = $size['size'];

		if ($header['fst'] > self::HEADER_SIZE || $header['end'] < $size)
		{
			$fh = fopen('php://temp', 'w+');
			stream_copy_to_stream($this->filehandle, $fh, $header['end'] - $header['fst'], $header['fst']);
			$header['end'] = self::HEADER_SIZE + $header['end'] - $header['fst'];
			$header['fst'] = self::HEADER_SIZE;
			$this->setHeader($header);
			rewind($fh);
			fseek($this->filehandle, self::HEADER_SIZE, SEEK_SET);
			stream_copy_to_stream($fh, $this->filehandle);
			fclose($fh);
			ftruncate($this->filehandle, $header['end']);
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

		$this->setHeader(self::$HEADER);
		ftruncate($this->filehandle, self::HEADER_SIZE);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}
}
