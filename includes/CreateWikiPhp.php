<?php

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class CreateWikiPhp {

	private CreateWikiDataFactory $dataFactory;

	/**
	 * @param string $wiki
	 * @param CreateWikiHookRunner|null $hookRunner
	 */
	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner = null ) {
		$dataFactory = MediaWikiServices::getInstance()->get( 'CreateWikiDataFactory' );
		$this->dataFactory = $dataFactory->newInstance( $wiki );
	}

	/**
	 * @param bool $isNewChanges
	 */
	public function resetWiki( bool $isNewChanges = true ) {
		$this->dataFactory->resetWikiData( $isNewChanges );
	}
}
