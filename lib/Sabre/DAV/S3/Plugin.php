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
	 * The lifetime in seconds of an entity before it is updated again
	 *
	 * @var int
	 */
	protected $lifetime = 0;

	/**
	 * Creates the Plugin
	 *
	 * @param int $lifetime The minimum time in seconds passed needed from the last modification time for an entity until it is updated.
	 */
	public function __construct($lifetime = 0)
	{
		$this->lifetime = $lifetime;
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
				echo 'acquireing exclusive lock for log file...' . PHP_EOL;
				flush();
				$this->logfile = fopen(__FILE__ . '.log', 'a');
				flock($this->logfile, LOCK_EX);	// blocks until lock is acquired
			}
			fwrite($this->logfile, $s);
		}

		echo $s;
		flush();
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
			$_SERVER['REQUEST_METHOD'] . ' ' . gmdate('D, d M Y H:i:s.', floor($ts)) . $tsm . ' GMT' . PHP_EOL .
			$_SERVER['HTTP_HOST'] . ' ' . $_SERVER['REQUEST_URI'] . PHP_EOL .
			'------------------------------------------------------------------------------' . PHP_EOL);


		if (!($this->server->tree instanceof Sabre_DAV_S3_Tree))
		{
			$this->elog('Tree is not a Sabre_DAV_S3_Tree!' . PHP_EOL);
			return false;
		}

		$em = $this->server->tree->getEntityManager();
		$s3 = $this->server->tree->getS3();
		if (!$em || !$s3)
		{
			$this->elog('No Entity Manager or S3 Service available!' . PHP_EOL);
			return false;
		}
		$em->setFlushMode(Sabre_DAV_S3_IEntityManager::FLUSH_MANUAL);

		$body = $this->server->httpRequest->getBody(true);
		$this->elog('requested updates: ' . PHP_EOL . $body);

		$oid = strtok($body, "\r\n");
		while ($oid !== false)
		{
			if ($oid !== '')
			{
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
					$this->elog('found!' . PHP_EOL .
						'name: "' . $node->getName() . '"' . PHP_EOL);

					$lastmodified = $node->getLastUpdated();
					if ($forceupdate || $lastmodified + $this->lifetime <= time() || !isset($lastmodified))
					{
						try
						{
							if ($forceupdate)
								$this->elog('forcing update: ' . (time() - $lastmodified) . '/' . $this->lifetime . PHP_EOL);
							else
								$this->elog('age qualifies for update: ' . (time() - $lastmodified) . '/' . $this->lifetime . PHP_EOL);

							$this->elog('requesting meta data...' . PHP_EOL);
							$node->requestMetaData(true);

							if ($node instanceof Sabre_DAV_S3_ICollection)
							{
								$refobj = new ReflectionObject($node);
								if ($refobj->hasProperty('children_id'))
								{
									$dirtystate = $node->isDirty();

									$refprop = $refobj->getProperty('children_id');
									$refprop->setAccessible(true);

									$children_old = $refprop->getValue($node);
									$refprop->setValue($node, array());

									$this->elog('requesting children...' . PHP_EOL);
									$node->requestChildren();

									$children_new = $refprop->getValue($node);
									$children_removed = array_diff($children_old, $children_new);
									$children_added = array_diff($children_new, $children_old);

									foreach ($children_removed as $id_removed)
									{
										$child = $em->find($id_removed);
										if ($child)
										{
											$this->elog('removing child: ' . $id_removed . PHP_EOL);
											$child->remove();
										}
									}
									foreach ($children_added as $id_added)
									{
										$this->elog('adding child: ' . $id_added . PHP_EOL);
									}

									$refprop->setValue($node, $children_new);
									$node->markDirty(!empty($children_removed) || !empty($children_added) || $dirtystate);
								}
							}
							$node->setLastUpdated();
						}
						catch (Sabre_DAV_S3_Exception $e)
						{
							$this->elog('s3 error: ' . $e->getMessage() . PHP_EOL);
							if ($e->getHTTPCode() == 404)
							{
								$this->elog('removing: ' . $node->getOID() . PHP_EOL);
								$node->remove();
								$parent = $node->getParent();
								if (isset($parent))
								{
									$this->elog('removing from parent: ' . $parent->getOID() . PHP_EOL);
									$parent->removeChild($node->getName());
								}
							}
						}
						catch (Exception $e)
						{
							$this->elog('error: ' . $e->getMessage() . PHP_EOL);
						}
					}
					else
						$this->elog('skipping update: ' . (time() - $lastmodified) . '/' . $this->lifetime . PHP_EOL);
				}
				else
				{
					$this->elog('not found!' . PHP_EOL);
				}
			}
			$oid = strtok("\r\n");
		}

		$this->elog(PHP_EOL . 'flushing persistence context...' . PHP_EOL);
		$em->flush();

		$this->elog('execution time: ' . (microtime(true) - $ts) . PHP_EOL .
			'------------------------------------------------------------------------------' . PHP_EOL . PHP_EOL);

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
			$header = 'UPDATE ' . $request->getRawServerValue('REQUEST_URI') . " HTTP/1.1\r\n";
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
