<?php

class CreateWikiJson {
	private $dbr = null;
	private $cache = null;
	private $wiki = null;
	private $databaseArray = [];
	private $wikiArray = [];
	private $cacheDir = null;

	public function __construct( string $wiki ) {
		global $wgCreateWikiDatabase, $wgCreateWikiCacheDirectory;

		$this->dbr = wfGetDB( DB_REPLICA, [], $wgCreateWikiDatabase );
		$this->cache = ObjectCache::getLocalClusterInstance();
		$this->cacheDir = $wgCreateWikiCacheDirectory;
		$this->wiki = $wiki;
		$this->databaseArray = json_decode( file_get_contents( $this->cacheDir . '/databases.json' ), true );
		$this->databaseTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ) );
		$this->wikiArray = json_decode( file_get_contents( $this->cacheDir . '/' . $wiki . '.json' ), true );
		$this->wikiTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );
	}

	public function update() {
		if ( $this->newChanges() ) {
			$this->generateDatabaseList();
			$this->generateWiki();

			return true;
		}

		return false;
	}

	private function generateDatabaseList() {
		$allWikis = $this->dbr->select(
			'cw_wikis',
			[
				'wiki_dbname',
				'wiki_deleted',
				'wiki_url'
			]
		);

		$wikiList = [];
		$domainList = [];

		foreach ( $allWikis as $wiki ) {
			if ( $wiki->wiki_deleted == 0 ) {
				$wikiList['all'][] = $wiki->wiki_dbname;
			} else {
				$wikiList['deleted'][] = $wiki->wiki_dbname;
			}

			if ( !is_null( $wiki->wiki_url ) ) {
				$domainList[$wiki->wiki_url] = $this->wiki_dbname;
			}
		}

		file_put_contents( $this->cacheDir . "/databases.json", json_encode( [ 'timestamp' => $this->databaseTimestamp, 'databases' => $wikiList['all'], 'domains' => $domainList ] ), LOCK_EX );
		file_put_contents( $this->cacheDir . "/deleted.json", json_encode( [ 'timestamp' => $this->databaseTimestamp, 'databases' => $wikiList['deleted'] ] ), LOCK_EX );
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
			throw new MWException( 'Wiki can not be found.' );
		}

		$jsonArray = [
			'timestamp' => $this->wikiTimestamp,
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
				'inactive' => ( $wikiObject->wiki_inactive_exempt ) ? 'exempt' : ( $wikiObject->wiki_inactive_timestamp ?? false )
			]
		];

		Hooks::run( 'CreateWikiJsonBuilder', [ $this->wiki, $this->dbr, $jsonArray ] );

		file_put_contents( $this->cacheDir . '/' . $this->wiki . '.json', json_encode( $jsonArray ), LOCK_EX );
	}

	private function newChanges() {
		if (
			$this->databaseArray['timestamp'] < ( ( $this->databaseTimestamp ) ? $this->databaseTimestamp : PHP_INT_MAX )
			|| $this->wikiArray['timestamp'] < ( ( $this->wikiTimestamp ) ? $this->wikiTimestamp : PHP_INT_MAX )
		) {
			return true;
		}

		return fase;
	}
}

