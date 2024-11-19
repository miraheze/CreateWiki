<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class CreateWikiDatabaseUtils {

	private IConnectionProvider $connectionProvider;

	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
	}

	public function getGlobalWikiID(): bool|string {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki-global' );
		return $dbr->getDomainID();
	}

	public function isCurrentWikiGlobal(): bool {
		return WikiMap::isCurrentWikiDbDomain( $this->getGlobalWikiID() );
	}
}
