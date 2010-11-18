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
	protected $shutdownfunction_enabled = true;

	/**
	 * Initializes the plugin
	 *
	 * @param Sabre_DAV_Server $server
	 * @return void
	 */
	public function initialize(Sabre_DAV_Server $server)
	{
		$this->server = $server;
		$this->server->subscribeEvent('unknownMethod', array($this, 'httpUpdateHandler'), 1);
		register_shutdown_function(array($this, 'refreshPersistenceContext'));
	}

	/**
	 * This method handles UPDATE requests to update the persistent state of Entities
	 *
	 * @param string $method
	 * @param string $uri
	 * @return bool
	 */
	public function httpUpdateHandler($method, $uri)
	{
		if ($method !== 'UPDATE')
			return true;

		$this->shutdownfunction_enabled = false;
		ignore_user_abort(true);
		ob_end_clean();

		$remote_addr = $this->server->httpRequest->getRawServerValue('REMOTE_ADDR');
		$server_addr = $this->server->httpRequest->getRawServerValue('SERVER_ADDR');
		if ($remote_addr !== $server_addr && $remote_addr !== '127.0.0.1')
		{
			header('Connection: close', true, 403);
			return false;
		}

		//header('Connection: close', true, 204);
		header('Connection: close', true, 200);
		header('Content-Type: text/plain', true);
		flush();
		//set_time_limit(0);


		if (!($this->server->tree instanceof Sabre_DAV_S3_Tree))
		{
			echo 'Tree is not a Sabre_DAV_S3_Tree!' . PHP_EOL;
			return false;
		}

		$em = $this->server->tree->getEntityManager();
		$s3 = $this->server->tree->getS3();

		if (!$em || !$s3)
		{
			echo 'No Entity Manager or S3 Service available!' . PHP_EOL;
			return false;
		}

		echo 'waiting...' . PHP_EOL;
		flush();

		usleep(250000); //give a fighting chance to S3 to propagte (250ms)


		$body = $this->server->httpRequest->getBody(true);

		echo 'updating: ' . PHP_EOL . $body;
		flush();

		$id = strtok($body, "\r\n");
		while ($id !== false)
		{
			if ($id !== '')
			{
				echo PHP_EOL . 'searching: ' . $id . PHP_EOL;
				flush();

				$node = $em->find($id);
				if ($node)
				{
					try
					{
						echo 'requesting meta data...' . PHP_EOL;
						flush();

						if ($node instanceof Sabre_DAV_S3_Bucket)
						{
							$metafile = $node->getMetaFile();
							if ($metafile)
								$metafile->requestMetaData(true);
						}
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

								echo 'requesting children...' . PHP_EOL;
								flush();

								$node->requestChildren();

								$children_new = $refprop->getValue($node);
								$children_removed = array_diff($children_old, $children_new);
								$children_added = array_diff($children_new, $children_old);

								foreach ($children_removed as $id_removed)
								{
									$child = $em->find($id_removed);
									if ($child)
									{
										echo 'removing child: ' . $id_removed . PHP_EOL;
										flush();

										$child->remove();
									}
								}
								foreach ($children_added as $id_added)
								{
									echo 'adding child: ' . $id_added . PHP_EOL;
									flush();
								}

								$refprop->setValue($node, $children_new);
								$node->markDirty(!empty($children_removed) || !empty($children_added) || $dirtystate);
							}
						}
					}
					catch (Sabre_DAV_S3_Exception $e)
					{
						echo 's3 error: ' . $e->getMessage() . PHP_EOL;
						echo 'removing: ' . $node->getID() . PHP_EOL;
						flush();

						$node->remove();
					}
					catch (Exception $e)
					{
						echo 'error: ' . $e->getMessage() . PHP_EOL;
						flush();
					}
				}
			}
			$id = strtok("\r\n");
		}

		echo PHP_EOL . 'flushing persistence context...' . $id_removed . PHP_EOL . PHP_EOL;
		flush();

		$em->flush();

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
			$nodelist .= $node->getID() . "\n";

		$request = $this->server->httpRequest;
		if (!empty($nodelist))
		{
			$header = 'UPDATE ' . $request->getRawServerValue('REQUEST_URI') . " HTTP/1.1\r\n";
			$host = $request->getRawServerValue('HTTP_HOST');
			if ($host)
				$header .= 'Host: ' . $host . "\r\n";
			$header .= "Connection: close\r\n";
			$header .= 'Content-Length: ' . strlen($nodelist) . "\r\n";
			//$header .= "Expect: 100-continue\r\n";
			$header .= "\r\n";

			$socket = fsockopen($request->getRawServerValue('SERVER_NAME'), $request->getRawServerValue('SERVER_PORT'));

			if ($socket)
			{
				fwrite($socket, $header);
				//$response = fgets($socket);
				//if (strtoupper(trim($response, "\r\n\t ")) == 'HTTP/1.1 200 OK');
				fwrite($socket, $nodelist);
				fclose($socket);
			}
		}
	}
}
