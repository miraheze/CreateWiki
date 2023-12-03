<?php

namespace Miraheze\CreateWiki;

use BagOStuff;
use Config;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCache;
use UnexpectedValueException;
use Wikimedia\AtEase\AtEase;
use Wikimedia\Rdbms\DBConnRef;

class CreateWikiJson {

	/**
	 * The configuration object.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * The database connection reference object.
	 *
	 * @var DBConnRef
	 */
	private $dbr;

	/**
	 * The cache object.
	 *
	 * @var BagOStuff
	 */
	private $cache;

	/**
	 * The wiki database name.
	 *
	 * @var string
	 */
	private $wiki;

	/**
	 * The database array information for the wiki.
	 *
	 * @var array
	 */
	private $databaseArray;

	/**
	 * The wiki information array.
	 *
	 * @var array
	 */
	private $wikiArray;

	/**
	 * The directory path for cache files.
	 *
	 * @var string
	 */
	private $cacheDir;

	/**
	 * The timestamp for the database information.
	 *
	 * @var int
	 */
	private $databaseTimestamp;

	/**
	 * The timestamp for the wiki information.
	 *
	 * @var int
	 */
	private $wikiTimestamp;

	/**
	 * The time the object was initialised.
	 *
	 * @var int
	 */
	private $initTime;

	/**
	 * The CreateWiki hook runner object.
	 *
	 * @var CreateWikiHookRunner
	 */
	private $hookRunner;

	/**
	 * CreateWikiJson constructor.
	 *
	 * @param string $wiki
	 * @param CreateWikiHookRunner|null $hookRunner
	 */
	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );

		$this->hookRunner = $hookRunner ?? MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );
		$this->cache = ObjectCache::getLocalClusterInstance();
		$this->cacheDir = $this->config->get( 'CreateWikiCacheDirectory' );
		$this->wiki = $wiki;

		AtEase::suppressWarnings();
		$databaseFile = file_get_contents( $this->cacheDir . '/databases.json' );
		$this->databaseArray = json_decode( $databaseFile, true );
		$this->databaseTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ) );
		$wikiDatabaseFile = file_get_contents( $this->cacheDir . '/' . $wiki . '.json' );
		$this->wikiArray = json_decode( $wikiDatabaseFile, true );
		$this->wikiTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );
		AtEase::restoreWarnings();

		if ( !$this->databaseTimestamp ) {
			$this->resetDatabaseList();
		}

		if ( !$this->wikiTimestamp ) {
			$this->resetWiki();
		}
	}

	/**
	 * Generates a new JSON file for the current wiki.
	 *
	 * This method resets the current wiki by getting a new timestamp from the database
	 * and updates the cache with the new information.
	 */
	public function resetWiki() {
		$this->dbr ??= MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );

		$this->initTime ??= $this->dbr->timestamp();

		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ), $this->initTime );

		// Rather than destroy object, let's fake the cache timestamp
		$this->wikiTimestamp = $this->initTime;
	}

	/**
	 * Resets the database list.
	 *
	 * This method resets the database list by using the database load balancer
	 * to get the current time and store it in the cache. The timestamp is then
	 * updated for the current database list.
	 */
	public function resetDatabaseList() {
		$this->dbr ??= MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );

		$this->initTime ??= $this->dbr->timestamp();

		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ), $this->initTime );

		// Rather than destroy object, let's fake the cache timestamp
		$this->databaseTimestamp = $this->initTime;
	}

	/**
	 * Updates the wiki and database lists if there are any changes.
	 *
	 * This method updates the wiki and database lists if there are any changes and generates new JSON files
	 * for the updated information and stores it in the cache directory specified in the config.
	 */
	public function update() {
		$changes = $this->newChanges();

		if ( $changes['databases'] ) {
			$this->dbr ??= MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
				->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
				->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );

			$this->generateDatabaseList();
		}

		if ( $changes['wiki'] ) {
			$this->dbr ??= MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
				->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
				->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );

			$this->generateWiki();
		}
	}

	/**
	 * Generates the database list.
	 *
	 * This method retrieves information from the database about all wikis,
	 * separates deleted and non-deleted wikis, and writes the data to a file.
	 *
	 * The data for each wiki includes its database name, database cluster,
	 * site name, and URL (if applicable). The resulting data is saved in two
	 * separate files: one for non-deleted wikis (combi) and one for deleted wikis.
	 *
	 * The method also triggers the CreateWikiJsonGenerateDatabaseList hook that
	 * allows extensions to modify the data before it is written to a file.
	 */
	private function generateDatabaseList() {
		$databaseLists = [];
		$this->hookRunner->onCreateWikiJsonGenerateDatabaseList( $databaseLists );

		if ( !empty( $databaseLists ) ) {
			$this->generateDatabasesJsonFile( $databaseLists );
			return;
		}

		$allWikis = $this->dbr->select(
			'cw_wikis',
			[
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_deleted',
				'wiki_url',
				'wiki_sitename',
			]
		);

		$combiList = [];
		$deletedList = [];

		foreach ( $allWikis as $wiki ) {
			if ( $wiki->wiki_deleted == 1 ) {
				$deletedList[$wiki->wiki_dbname] = [
					's' => $wiki->wiki_sitename,
					'c' => $wiki->wiki_dbcluster,
				];
			} else {
				$combiList[$wiki->wiki_dbname] = [
					's' => $wiki->wiki_sitename,
					'c' => $wiki->wiki_dbcluster,
				];

				if ( $wiki->wiki_url !== null ) {
					$combiList[$wiki->wiki_dbname]['u'] = $wiki->wiki_url;
				}
			}
		}

		$databaseLists = [
			'databases' => [
				'combi' => $combiList,
			],
			'deleted' => [
				'deleted' => 'databases',
				'databases' => $deletedList,
			],
		];

		$this->generateDatabasesJsonFile( $databaseLists );
	}

	private function generateDatabasesJsonFile( array $databaseLists ) {
		foreach ( $databaseLists as $name => $contents ) {
			$contents = [ 'timestamp' => $this->databaseTimestamp ] + $contents;
			$contents[$name] ??= 'combi';

			$contents[ $contents[$name] ] ??= [];

			unset( $contents[$name] );

			$tmpFile = tempnam( '/tmp/', 'CreateWiki-' );

			if ( $tmpFile ) {
				if ( file_put_contents( $tmpFile, json_encode( $contents ) ) ) {
					if ( !rename( $tmpFile, "{$this->cacheDir}/{$name}.json" ) ) {
						unlink( $tmpFile );
					}
				} else {
					unlink( $tmpFile );
				}
			}
		}
	}

	/**
	 * Generates data related to a wiki and stores it in a JSON file.
	 *
	 * The data generated includes:
	 * - timestamp: Unix timestamp of the last modification of the JSON file. If file doesn't exist, set to 0.
	 * - database: database name of the wiki.
	 * - created: creation date of the wiki.
	 * - dbcluster: database cluster of the wiki.
	 * - category: category of the wiki.
	 * - url: URL of the wiki.
	 * - core: contains core information of the wiki, including its name and language code.
	 * - states: contains information about the state of the wiki, including privacy, closure, activity and experimental status.
	 *
	 * The generated data is stored in a JSON file with the same name as the database of the wiki.
	 * The method also triggers the CreateWikiJsonBuilder hook to allow extensions to add more data to the JSON file.
	 */
	private function generateWiki() {
		$wikiObject = $this->dbr->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $this->wiki
			]
		);

		if ( !$wikiObject ) {
			throw new UnexpectedValueException( "Wiki '{$this->wiki}' can not be found." );
		}

		$states = [];

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$states['private'] = (bool)$wikiObject->wiki_private;
		}

		if ( $this->config->get( 'CreateWikiUseClosedWikis' ) ) {
			$states['closed'] = $wikiObject->wiki_closed_timestamp ?? false;
		}

		if ( $this->config->get( 'CreateWikiUseInactiveWikis' ) ) {
			$states['inactive'] = ( $wikiObject->wiki_inactive_exempt ) ? 'exempt' : ( $wikiObject->wiki_inactive_timestamp ?? false );
		}

		if ( $this->config->get( 'CreateWikiUseExperimental' ) ) {
			$states['experimental'] = (bool)$wikiObject->wiki_experimental;
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
			'states' => $states
		];

		$this->hookRunner->onCreateWikiJsonBuilder( $this->wiki, $this->dbr, $jsonArray );

		$tmpFile = tempnam( '/tmp/', 'CreateWiki-' );
		if ( $tmpFile ) {
			if ( file_put_contents( $tmpFile, json_encode( $jsonArray ) ) ) {
				if ( !rename( $tmpFile, "{$this->cacheDir}/{$this->wiki}.json" ) ) {
					unlink( $tmpFile );
				}
			} else {
				unlink( $tmpFile );
			}
		}
	}

	/**
	 * Determine if the information on databases or a specific wiki has changed.
	 *
	 * @return array $changes An array with two keys, 'databases' and 'wiki', both are set to either `true` or `false`
	 * indicating if the information has changed. If either key is set to `true`, then new data needs to be generated,
	 * if it is set to `false`, then the information is up to date and no new data needs to be generated.
	 */
	private function newChanges() {
		$changes = [
			'databases' => false,
			'wiki' => false
		];

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
