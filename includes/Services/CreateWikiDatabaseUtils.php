<?php

namespace Miraheze\CreateWiki\Services;

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
}
