<?php

/**
 * This ICollection interface extends the base ICollection interface with common methods for all S3 collection classes (Directory, Bucket, Account)
 *
 * @package Sabre
 * @subpackage DAV
 * @author Paul Voegler
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
interface Sabre_DAV_S3_ICollection extends Sabre_DAV_S3_INode, Sabre_DAV_ICollection
{
	/**
	 * Add a child to the children collection
	 * 
	 * @param Sabre_DAV_S3_INode $node
	 * @return void
	 */
	public function addChild(Sabre_DAV_S3_INode $node);

	/**
	 * Removes the child specified by it's name from the children collection
	 * 
	 * @param string $name
	 * @return void
	 */
	public function removeChild($name);

	/**
	 * Updates the children collection from S3
	 *
	 * @param bool $fulltree If true, all subdirectories will also be parsed, only the current path otherwise
	 * @return void
	 */
	public function requestChildren($fulltree = false);
	
	/**
	 * Resets the children collection
	 * 
	 * @return void
	 */
	public function clearChildren();
}
