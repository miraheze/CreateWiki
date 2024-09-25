<?php

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class CreateWikiPhp {

	/** @var CreateWikiPhpDataFactory */
	private $dataFactory;

	/**
	 * CreateWikiPhp constructor.
	 *
	 * @param string $wiki
	 * @param CreateWikiHookRunner|null $hookRunner
	 */
	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner = null ) {
		$dataFactory = MediaWikiServices::getInstance()->get( 'CreateWikiPhpDataFactory' );
		$this->dataFactory = $dataFactory->newInstance( $wiki );
	}

	/**
	 * Update function to check if the cached wiki data and database list are outdated.
	 * If either the wiki cache file or the database cache file has been modified,
	 * it will reset the corresponding cached data.
	 */
	public function update() {
		$this->dataFactory->update();
	}

	/**
	 * Resets the cached list of databases by fetching the current list from the database.
	 * This function queries the 'cw_wikis' table for database names and clusters, and writes
	 * the updated list to a PHP file within the cache directory. It also updates the
	 * modification timestamp and stores it in the cache for future reference.
	 *
	 * @param bool $isNewChanges
	 */
	public function resetDatabaseList( bool $isNewChanges = true ) {
		$this->dataFactory->resetDatabaseList( $isNewChanges );
	}

	/**
	 * Resets the wiki information.
	 *
	 * This method retrieves new information for the wiki and updates the cache.
	 *
	 * @param bool $isNewChanges
	 */
	public function resetWiki( bool $isNewChanges = true ) {
		$this->dataFactory->resetWiki( $isNewChanges );
	}

	/**
	 * Deletes the cache data for a wiki.
	 * Probably used when a wiki is deleted or renamed.
	 *
	 * @param string $wiki
	 */
	public function deleteWikiData( string $wiki ) {
		$this->dataFactory->deleteWikiData( $wiki );
	}
}
