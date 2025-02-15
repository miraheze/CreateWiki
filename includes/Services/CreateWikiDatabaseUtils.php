<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class CreateWikiDatabaseUtils {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	public function getCentralWikiID(): bool|string {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );
		return $dbr->getDomainID();
	}

	public function isCurrentWikiCentral(): bool {
		return WikiMap::isCurrentWikiDbDomain( $this->getCentralWikiID() );
	}
}
