<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;

class CreateWikiJson {
	private $config;
	private $dbr;
	private $cache;
	private $wiki;
	private $databaseArray;
	private $wikiArray;
	private $cacheDir;
	private $databaseTimestamp;
	private $wikiTimestamp;
	private $initTime;

	public function __construct( string $wiki ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$this->cache = ObjectCache::getLocalClusterInstance();
		$this->cacheDir = $this->config->get( 'CreateWikiCacheDirectory' );
		$this->wiki = $wiki;

		AtEase::suppressWarnings();
		$this->databaseArray = json_decode( file_get_contents( $this->cacheDir . '/databases.json' ), true );
		$this->databaseTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ) );
		$this->wikiArray = json_decode( file_get_contents( $this->cacheDir . '/' . $wiki . '.json' ), true );
		$this->wikiTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );
		AtEase::restoreWarnings();

		$this->dbr = null;
		if ( !$this->databaseTimestamp ) {
			$this->dbr = wfGetDB( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );
			$this->initTime = $this->dbr->timestamp();

			$this->resetDatabaseList();
		}

		if ( !$this->wikiTimestamp ) {
			if ( !$this->dbr ) {
				$this->dbr = wfGetDB( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );
				$this->initTime = $this->dbr->timestamp();
			}

			$this->resetWiki();
		}
	}

	public function resetWiki() {
		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ), $this->initTime );

		// Rather than destroy object, let's fake the cache timestamp
		$this->wikiTimestamp = $this->initTime;
	}

	public function resetDatabaseList() {
		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ), $this->initTime );

		// Rather than destroy object, let's fake the catch timestamp
		$this->databaseTimestamp = $this->initTime;
	}

	public function update() {
		$changes = $this->newChanges();

		if ( $changes['databases'] ) {
			if ( !$this->dbr ) {
				$this->dbr = wfGetDB( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );
			}

			$this->generateDatabaseList();
		}

		if ( $changes['wiki'] ) {
			if ( !$this->dbr ) {
				$this->dbr = wfGetDB( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );
			}

			$this->generateWiki();
		}
	}

	private function generateDatabaseList() {
		$allWikis = $this->dbr->select(
			'cw_wikis',
			[
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_deleted',
				'wiki_url',
				'wiki_sitename'
			]
		);

		$combiList = [];
		$deletedList = [];

		foreach ( $allWikis as $wiki ) {
			if ( $wiki->wiki_deleted == 1 ) {
				$deletedList[$wiki->wiki_dbname] = [
					's' => $wiki->wiki_sitename,
					'c' => $wiki->wiki_dbcluster
				];
			} else {
				$combiList[$wiki->wiki_dbname] = [
					's' => $wiki->wiki_sitename,
					'c' => $wiki->wiki_dbcluster
				];

				if ( $wiki->wiki_url !== null ) {
					$combiList[$wiki->wiki_dbname]['u'] = $wiki->wiki_url;
				}
			}
		}

		file_put_contents( "{$this->cacheDir}/databases.json.tmp", json_encode( [ 'timestamp' => $this->databaseTimestamp, 'combi' => $combiList ] ), LOCK_EX );
		file_put_contents( "{$this->cacheDir}/deleted.json.tmp", json_encode( [ 'timestamp' => $this->databaseTimestamp, 'databases' => $deletedList ] ), LOCK_EX );

		if ( file_exists( "{$this->cacheDir}/databases.json.tmp" ) ) {
			rename( "{$this->cacheDir}/databases.json.tmp", "{$this->cacheDir}/databases.json" );
		}
		if ( file_exists( "{$this->cacheDir}/deleted.json.tmp" ) ) {
			rename( "{$this->cacheDir}/deleted.json.tmp", "{$this->cacheDir}/deleted.json" );
		}
	}

	private function generateWiki() {
		$wikiObject = $this->dbr->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $this->wiki
			]
		);

		if ( !$wikiObject ) {
			throw new MWException( "Wiki '{$this->wiki}' can not be found." );
		}

		$jsonArray = [
			'timestamp' => ( file_exists( $this->cacheDir . '/' . $this->wiki . '.json' ) ) ? $this->wikiTimestamp : 0,
			'database' => $wikiObject->wiki_dbname,
			'created' => $wikiObject->wiki_creation,
			'dbcluster' => $wikiObject->wiki_dbcluster,
			'category' => $wikiObject->wiki_category,
			'url' => $wikiObject->wiki_url ?? false,
			'core' => [
				'wgSitename' => $wikiObject->wiki_sitename,
				'wgLanguageCode' => $wikiObject->wiki_language
			],
			'states' => [
				'private' => (bool)$wikiObject->wiki_private,
				'closed' => $wikiObject->wiki_closed_timestamp ?? false,
				'inactive' => ( $wikiObject->wiki_inactive_exempt ) ? 'exempt' : ( $wikiObject->wiki_inactive_timestamp ?? false ),
				'experimental' => (bool)$wikiObject->wiki_experimental
			]
		];

		MediaWikiServices::getInstance()->getHookContainer()->run( 'CreateWikiJsonBuilder', [ $this->wiki, $this->dbr, &$jsonArray ] );

		// @phan-suppress-next-line SecurityCheck-PathTraversal
		file_put_contents( "{$this->cacheDir}/{$this->wiki}.json.tmp", json_encode( $jsonArray ), LOCK_EX );

		if ( file_exists( "{$this->cacheDir}/{$this->wiki}.json.tmp" ) ) {
			rename( "{$this->cacheDir}/{$this->wiki}.json.tmp", "{$this->cacheDir}/{$this->wiki}.json" );
		}
	}

	private function newChanges() {
		$changes = [
			'databases' => false,
			'wiki' => false
		];

		// Under php7.4 trying to access a index when the config is null results in a notice.
		$databaseTimestamp = $this->databaseArray['timestamp'] ?? null;
		$wikiTimestamp = $this->wikiArray['timestamp'] ?? null;

		if ( $databaseTimestamp < ( $this->databaseTimestamp ?: PHP_INT_MAX ) ) {
			$changes['databases'] = true;
		}

		if ( $wikiTimestamp < ( $this->wikiTimestamp ?: PHP_INT_MAX ) ) {
			$changes['wiki'] = true;
		}

		return $changes;
	}
}
