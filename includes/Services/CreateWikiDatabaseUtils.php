<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class CreateWikiDatabaseUtils {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	public function getCentralWikiID(): bool|string {
		return $this->getCentralWikiReplicaDB()->getDomainID();
	}

	public function getCentralWikiPrimaryDB(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase( 'virtual-createwiki-central' );
	}

	public function getCentralWikiReplicaDB(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );
	}
	
	public function getGlobalPrimaryDB(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase( 'virtual-createwiki' );
	}

	public function getGlobalReplicaDB(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki' );
	}

	public function isCurrentWikiCentral(): bool {
		return WikiMap::isCurrentWikiDbDomain( $this->getCentralWikiID() );
	}

	public function isRemoteWikiCentral( string $wiki ): bool {
		return $wiki === $this->getCentralWikiID();
	}
}
