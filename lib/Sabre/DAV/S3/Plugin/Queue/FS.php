<?php

/**
 * Filesystem implementation of a shared string queue
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Plugin_Queue_FS implements Sabre_DAV_S3_Plugin_IQueue
{
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
	 * The current read (dequeue) position in the file
	 *
	 * @var int
	 */
	protected $pos = 0;

	/**
	 * Initialize the Queue
	 *
	 * @param string $filename
	 * @return void
	 */
	public function __construct($filename)
	{
		$path = dirname($filename);
		if ($path == '.')
			$path = '';
		if (strpos($path, '/') !== 0 && strpos($path, '\\') !== 0 && strpos($path, ':') !== 1)	//not an absolute path?
			$path = getcwd() . DIRECTORY_SEPARATOR . $path;

		if ($path !== '' && file_exists($path))
		{
			$this->filename = $path . DIRECTORY_SEPARATOR . basename($filename);
			$this->filehandle = fopen($this->filename, !file_exists($this->filename) ? 'w+' : 'r+');
		}
	}

	/**
	 * Are there entries left in the queue?
	 *
	 * @return boolean
	 */
	public function isEmpty()
	{
		if (!$this->filehandle)
			return true;

		return ($this->pos >= filesize($this->filename));
	}

	/**
	 * Get the top entry and delete it from the queue
	 *
	 * @return string|boolean
	 */
	public function dequeue()
	{
		if (!$this->filehandle)
			return false;

		$trans = array('\\\\' => "\\", '\n' => "\n", '\r' => "\r", '\0' => "\0");
		$data = "\0";

		flock($this->filehandle, LOCK_EX);	// blocks until lock is acquired
		fseek($this->filehandle, $this->pos, SEEK_SET);

		do
		{
			$startpos = ftell($this->filehandle);
			$data = stream_get_line($this->filehandle, 8192, "\n");
			$this->pos = ftell($this->filehandle);
		}
		while (isset($data) && $data !== false && $data !== '' && $data[0] === "\0");

		if (isset($data) && $data !== false && $data !== '' && $data[0] !== "\0")
		{
			fseek($this->filehandle, $startpos, SEEK_SET);
			fwrite($this->filehandle, "\0");
		}

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		if (isset($data) && $data !== false && $data !== '' && $data[0] !== "\0")
			return strtr(substr($data, 1), $trans);
		else
			return false;
	}

	/**
	 * Add strings to the end of the queue
	 *
	 * @param $data string|array
	 * @return boolean
	 */
	public function enqueue($data)
	{
		if (!$this->filehandle)
			return false;

		$trans = array("\\" => '\\\\', "\n" => '\n', "\r" => '\r', "\0" => '\0');
		$data = (array)$data;

		flock($this->filehandle, LOCK_EX);	// blocks until lock is acquired
		fseek($this->filehandle, 0, SEEK_END);

		foreach ($data as $v)
			fwrite($this->filehandle, 's' . substr(strtr((string)$v, $trans), 0, 8190) . "\n", 8192);

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}

	/**
	 * reorganize the queue file
	 * only call this if you are sure there are no other reading instances that have already begun dequeueing!
	 *
	 * @return boolean
	 */
	public function organize()
	{
		if (!$this->filehandle)
			return false;

		flock($this->filehandle, LOCK_EX);	// blocks until lock is acquired
		fseek($this->filehandle, $this->pos, SEEK_SET);

		do
		{
			$startpos = ftell($this->filehandle);
			$data = stream_get_line($this->filehandle, 8192, "\n");
		}
		while (isset($data) && $data !== false && $data !== '' && $data[0] === "\0");

		if (isset($data) && $data !== false && $data !== '' && $data[0] !== "\0")
		{
			if ($startpos > 0)
			{
				fseek($this->filehandle, $startpos, SEEK_SET);
				$fh = fopen('php://temp', 'w+');
				stream_copy_to_stream($this->filehandle, $fh);
				fseek($this->filehandle, 0, SEEK_SET);
				stream_copy_to_stream($fh, $this->filehandle);
				fclose($fh);
				ftruncate($this->filehandle, ftell($this->filehandle));
			}
		}
		else
			ftruncate($this->filehandle, 0);

		$this->pos = 0;

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}

	/**
	 * clear the queue file
	 * only call this if you are sure there are no other reading instances that have already begun dequeueing!
	 *
	 * @return boolean
	 */
	public function clear()
	{
		if (!$this->filehandle)
			return false;

		flock($this->filehandle, LOCK_EX);	// blocks until lock is acquired

		ftruncate($this->filehandle, 0);
		$this->pos = 0;

		fflush($this->filehandle);
		flock($this->filehandle, LOCK_UN);

		return true;
	}
}
