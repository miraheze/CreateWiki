<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class CreateWikiDatabaseUtils {

	private IConnectionProvider $connectionProvider;

	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
	}

	public function getCentralWikiID(): bool|string {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );
		return $dbr->getDomainID();
	}

	public function isCurrentWikiCentral(): bool {
		return WikiMap::isCurrentWikiDbDomain( $this->getCentralWikiID() );
	}
}
