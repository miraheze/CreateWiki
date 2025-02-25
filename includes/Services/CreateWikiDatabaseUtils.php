<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class CreateWikiDatabaseUtils {

	private string $centralWikiID = '';

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	public function getCentralWikiID(): string {
		if ( !$this->centralWikiID ) {
			$this->centralWikiID = $this->getCentralWikiReplicaDB()->getDomainID() ?: '';
		}

		return $this->centralWikiID;
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

	public function getRemoteWikiPrimaryDB( string $wiki ): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase( $wiki );
	}

	public function getRemoteWikiReplicaDB( string $wiki ): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase( $wiki );
	}

	public function isCurrentWikiCentral(): bool {
		static $isCentral = null;
		return $isCentral ??= WikiMap::isCurrentWikiDbDomain( $this->getCentralWikiID() );
	}

	public function isRemoteWikiCentral( string $wiki ): bool {
		return $wiki === $this->getCentralWikiID();
	}
}
