<?php

/**
 * S3 Persistence Update Plugin
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_DAV_S3_Plugin extends Sabre_DAV_ServerPlugin
{
	/**
	 * The Server
	 *
	 * @var Sabre_DAV_Server
	 */
	protected $server;

	/**
	 * Do we need to run the shutdown function?
	 *
	 * @var bool
	 */
	protected $shutdownfunction_enabled = false;

	/**
	 * The lock for exclusive processing power
	 *
	 * @var resource
	 */
	protected $processlock = null;

	/**
	 * The maximum time the script is allowed to process the queue
	 *
	 * @var int
	 */
	protected $maxprocesstime = null;

	/**
	 * The queue provider
	 *
	 * @var Sabre_DAV_S3_Plugin_IQueue
	 */
	protected $queue;

	/**
	 * The lifetime in seconds of an entity before it is updated again
	 *
	 * @var int
	 */
	protected $lifetime = 0;

	/**
	 * Creates the Plugin
	 *
	 * @param Sabre_DAV_S3_Plugin_IQueue $queue The queue provider
	 * @param int $lifetime The minimum time in seconds passed needed from the last modification time for an entity until it is updated.
	 */
	public function __construct(Sabre_DAV_S3_Plugin_IQueue $queue, $lifetime = 0, $maxprocesstime = null)
	{
		$this->queue = $queue;
		$this->lifetime = $lifetime;
		$this->maxprocesstime = $maxprocesstime;
	}

	/**
	 * Initializes the Plugin
	 *
	 * @param Sabre_DAV_Server $server
	 * @return void
	 */
	public function initialize(Sabre_DAV_Server $server)
	{
		$this->server = $server;
		$this->server->subscribeEvent('beforeMethod', array($this, 'httpUpdate'), 0);
		register_shutdown_function(array($this, 'refreshPersistenceContext'));
	}

	private $uselogfile = false;

	private function elog($s)
	{
		if ($this->uselogfile)
		{
			if (!isset($this->logfile))
			{
				$this->logfile = fopen(__FILE__ . '.log', 'a');
				$this->logfileid = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
				$prefix = PHP_EOL . $this->logfileid . ': ';
				$this->logfiletr = array("\r\n" => $prefix, "\n" => $prefix, "\r" => $prefix);
			}
			flock($this->logfile, LOCK_EX);	// blocks until lock is acquired
			fwrite($this->logfile, $this->logfileid . ': ' . strtr($s, $this->logfiletr) . PHP_EOL);
			fflush($this->logfile);
			flock($this->logfile, LOCK_UN);
		}

		echo $s . PHP_EOL;
		flush();
	}

	/**
	 * Try to acquire the lock for exclusive processing power
	 *
	 * @return boolean set to true if lock could be acquired
	 */
	protected function acquireProcessLock()
	{
		$this->processlock = fopen(__FILE__, 'r');
		flock($this->processlock, LOCK_EX | LOCK_NB);
		$r = fgets($this->processlock, 3);

		return ($r === '<?');
	}

	/**
	 * Release the lock for exclusive processing power
	 *
	 * @return void
	 */
	protected function releaseProcessLock()
	{
		if ($this->processlock)
			flock($this->processlock, LOCK_UN);
	}

	/**
	 * Process a list of nodes (Entity IDs) to update from S3
	 *
	 * @param Sabre_DAV_S3_Plugin_IQueue $queue
	 * @param Sabre_DAV_S3_IEntityManager $em
	 * @param int $max_process_time stop processing after this many of seconds
	 * @return boolean
	 */
	protected function processQueue(Sabre_DAV_S3_Plugin_IQueue $queue, Sabre_DAV_S3_IEntityManager $em)
	{
		$start = time();

		while (!($this->max_process_time > 0) || time() - $start < $this->max_process_time)
		{
			$oid = $queue->dequeue();
			if ($oid === false)
				break;	//abort on error or empty queue
			if (empty($oid))
				continue;	//continue on empty line

			$forceupdate = false;
			if (strpos($oid, '!') === 0)
			{
				$oid = substr($oid, 1);
				$forceupdate = true;
			}

			$this->elog(PHP_EOL . 'searching for: ' . $oid . '... ');
			$node = $em->find($oid);
			if ($node)
			{
				$this->elog('name: "' . $node->getName() . '"');

				if ($em->contains($node))
				{
					$lastmodified = $node->getLastUpdated();
					if ($forceupdate || $lastmodified + $this->lifetime <= time() || !isset($lastmodified))
					{
						try
						{
							if ($forceupdate)
								$this->elog('forcing update: ' . (time() - $lastmodified) . '/' . $this->lifetime);
							else
								$this->elog('age qualifies for update: ' . (time() - $lastmodified) . '/' . $this->lifetime);

							$this->elog('requesting meta data...');
							$node->requestMetaData(true);

							if ($node instanceof Sabre_DAV_S3_ICollection)
							{
								$refobj = new ReflectionObject($node);
								if ($refobj->hasProperty('children_oid'))
								{
									$dirtystate = $node->isDirty();

									$refprop = $refobj->getProperty('children_oid');
									$refprop->setAccessible(true);
									$children_old = $refprop->getValue($node);

									$this->elog('requesting children...');
									$node->requestChildren();

									$children_new = $refprop->getValue($node);
									$children_removed = array_diff($children_old, $children_new);
									$children_added = array_diff($children_new, $children_old);

									foreach ($children_removed as $oid_removed)
									{
										$child = $em->find($oid_removed);
										if ($child)
										{
											$this->elog('removing child: ' . $oid_removed . ' (' . $child->getName() . ')');
											$child->remove();
										}
									}
									foreach ($children_added as $oid_added)
									{
										$child = $em->find($oid_added);
										if ($child)
											$this->elog('adding child: ' . $oid_added . ' (' . $child->getName() . ')');
									}

									$node->markDirty(!empty($children_removed) || !empty($children_added) || $dirtystate);
								}
							}
							$node->setLastUpdated();
						}
						catch (Sabre_DAV_S3_Exception $e)
						{
							$this->elog('s3 error: ' . $e->getMessage());
							if ($e->getHTTPCode() == 404)
							{
								$this->elog('removing...');
								$node->remove();
								$parent = $node->getParent();
								if (isset($parent))
								{
									$this->elog('removing from parent: ' . $parent->getOID() . ' (' . $parent->getName() . ')');
									try
									{
										$parent->removeChild($node->getName());
										$this->elog('success!');
									}
									catch (Exception $e)
									{
										$this->elog('error: ' . $e->getMessage());
									}
								}
							}
						}
						catch (Exception $e)
						{
							$this->elog('error: ' . $e->getMessage());
						}
					}
					else
						$this->elog('skipping update: ' . (time() - $lastmodified) . '/' . $this->lifetime);
				}
				else
					$this->elog('already scheduled for removal!');
			}
			else
				$this->elog('not found!');
		}

		return true;
	}

	/**
	 * This method handles UPDATE requests to update the persistent state of Entities
	 *
	 * @param string $method
	 * @param string $uri
	 * @return bool
	 */
	public function httpUpdate($method, $uri)
	{
		if ($method !== 'UPDATE')
		{
			$this->shutdownfunction_enabled = true;
			return true;
		}

		ignore_user_abort(true);
		ob_end_clean();
		set_time_limit(0);

		$remote_addr = $this->server->httpRequest->getRawServerValue('REMOTE_ADDR');
		$server_addr = $this->server->httpRequest->getRawServerValue('SERVER_ADDR');
		if ($remote_addr !== '127.0.0.1' && $remote_addr !== $server_addr)
		{
			header('Connection: close', true, 403);
			return false;
		}

		if (strtolower($this->server->httpRequest->getHeader('Expect')) === '100-continue')
			header('Connection: close', true, 200);
		else
			header('Connection: close', true, 204);
		header('Content-Type: text/plain', true);

		usleep(250000); //give a fighting chance to S3 to propagte (250ms)

		$ts = microtime(true);
		$tsm = (integer)floor(($ts - floor($ts)) * 1000);
		$this->elog('------------------------------------------------------------------------------' . PHP_EOL .
			$_SERVER['REQUEST_METHOD'] . ' ' . gmdate('D, d M Y H:i:s T', floor($ts)) . ' @' . sprintf('%.6F', $ts) . PHP_EOL .
			$_SERVER['HTTP_HOST'] . ' ' . $_SERVER['REQUEST_URI'] . PHP_EOL .
			'------------------------------------------------------------------------------');


		if (!($this->server->tree instanceof Sabre_DAV_S3_Tree))
		{
			$this->elog('Tree is not a Sabre_DAV_S3_Tree!');
			return false;
		}

		$em = $this->server->tree->getEntityManager();
		$s3 = $this->server->tree->getS3();
		if (!$em || !$s3)
		{
			$this->elog('No Entity Manager or S3 Service available!');
			return false;
		}
		$em->setFlushMode(Sabre_DAV_S3_IEntityManager::FLUSH_MANUAL);

		$body = strtr(rtrim($this->server->httpRequest->getBody(true), "\r\n"), array("\r\n" => "\n", "\r" => "\n"));
		$this->elog('requested updates: ' . PHP_EOL . $body);

		$body = explode("\n", $body);
		$this->queue->enqueue($body);

		if ($this->acquireProcessLock())
		{
			$this->elog(PHP_EOL . 'spawning new process to handle the queue...');
			$this->processQueue($this->queue, $em);
			$this->queue->organize();

			$this->releaseProcessLock();

			$this->elog(PHP_EOL . 'flushing persistence context...');
			$em->flush();
		}
		else
			$this->elog(PHP_EOL . 'queue process already running, updates added to queue...');

		$this->elog('execution time: ' . (microtime(true) - $ts) . PHP_EOL .
			'------------------------------------------------------------------------------');

		return false;
	}

	/**
	 * Gets called when the script shuts down
	 * Makes an asynchronous call to the server to update the persistent state of currently managed Entities
	 *
	 * @return void
	 */
	public function refreshPersistenceContext()
	{
		if (!$this->shutdownfunction_enabled)
			return;

		if (!($this->server->tree instanceof Sabre_DAV_S3_Tree))
			return;

		$em = $this->server->tree->getEntityManager();
		if (!$em)
			return;

		$cache = $em->getManaged();

		$nodelist = '';
		foreach ($cache as $node)
			$nodelist .= $node->getOID() . "\n";

		$request = $this->server->httpRequest;
		if (!empty($nodelist))
		{
			$header = 'UPDATE /' . $request->getRawServerValue('REQUEST_METHOD') . $request->getRawServerValue('REQUEST_URI') . " HTTP/1.1\r\n";
			$host = $request->getRawServerValue('HTTP_HOST');
			if ($host)
				$header .= 'Host: ' . $host . "\r\n";
			$header .= "Connection: close\r\n";
			$header .= 'Content-Length: ' . strlen($nodelist) . "\r\n";
			$header .= "Expect: 100-continue\r\n";
			$header .= "\r\n";

			$socket = fsockopen('tcp://localhost', $request->getRawServerValue('SERVER_PORT'));

			if ($socket)
			{
				fwrite($socket, $header);
				$response = fgets($socket);
				if (strtoupper(trim($response, "\r\n\t ")) == 'HTTP/1.1 100 CONTINUE')
					fwrite($socket, $nodelist);
				fclose($socket);
			}
		}
	}
}
