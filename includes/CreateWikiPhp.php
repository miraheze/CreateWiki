<?php

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

class CreateWikiPhp {

	private CreateWikiDataFactory $dataFactory;

	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner ) {
		$dataFactory = MediaWikiServices::getInstance()->get( 'CreateWikiDataFactory' );
		$this->dataFactory = $dataFactory->newInstance( $wiki );
	}

	public function update() {
		$this->dataFactory->syncCache();
	}

	public function resetWiki() {
		$this->dataFactory->resetWikiData( isNewChanges: true );
	}
}
