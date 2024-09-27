<?php

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class WikiManager {

	private WikiManagerFactory $factory;
	public bool $exists;

	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner ) {
		$factory = MediaWikiServices::getInstance()->get( 'WikiManagerFactory' );
		$this->factory = $factory->newInstance( $wiki );
		$this->exists = $factory->exists();
	}

	public function create(
		string $siteName,
		string $language,
		bool $private,
		string $category,
		string $requester,
		string $actor,
		string $reason
	): ?string {
		return $this->factory->create(
			$siteName,
			$language,
			$private,
			$category,
			$requester,
			$actor,
			$reason
		);
	}

	public function delete( bool $force = false ): ?string {
		return $this->factory->delete( $force );
	}

	public function rename( string $newDatabaseName ): ?string {
		return $this->factory->rename( $newDatabaseName );
	}

	public function checkDatabaseName( string $dbname, bool $forRename ): ?string {
		return $this->factory->checkDatabaseName( $dbname, $forRename );
	}
}
