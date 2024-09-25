<?php

namespace Miraheze\CreateWiki;

use BagOStuff;
use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCache;
use ObjectCacheFactory;
use UnexpectedValueException;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IConnectionProvider;

class CreateWikiPhpDataFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'CreateWikiCacheDirectory',
		'CreateWikiCacheType',
		'CreateWikiDatabase',
		'CreateWikiUseClosedWikis',
		'CreateWikiUseExperimental',
		'CreateWikiUseInactiveWikis',
		'CreateWikiUsePrivateWikis',
	];


	/** @var ServiceOptions */
	private $options;

	/** @var IConnectionProvider */
	private $connectionProvider;

	/** @var DBConnRef */
	private $dbr;

	/** @var BagOStuff */
	private $cache;
	
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	/**
	 * The wiki database name.
	 *
	 * @var string
	 */
	private $wiki;

	/**
	 * The directory path for cache files.
	 *
	 * @var string
	 */
	private $cacheDir;

	/**
	 * The timestamp for the wiki information.
	 *
	 * @var int
	 */
	private $wikiTimestamp;

	/**
	 * The timestamp for the databases list.
	 *
	 * @var int
	 */
	private $databaseTimestamp;

	/**
	 * CreateWikiPhpDataFactory constructor.
	 *
	 * @param IConnectionProvider $connectionProvider
	 * @param ObjectCacheFactory $objectCacheFactory
	 * @param CreateWikiHookRunner $hookRunner
	 * @param ServiceOptions $options
	 */
	public function __construct(
		IConnectionProvider $connectionProvider,
		ObjectCacheFactory $objectCacheFactory,
		CreateWikiHookRunner $hookRunner,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->connectionProvider = $connectionProvider;
		$this->hookRunner = $hookRunner;
		$this->options = $options;

		$this->cache = $this->options->get( 'CreateWikiCacheType' ) ?
			$objectCacheFactory->getInstance( $this->options->get( 'CreateWikiCacheType' ) ) :
			ObjectCache::getLocalClusterInstance();

		$this->cacheDir = $this->options->get( 'CreateWikiCacheDirectory' );
	}

	/**
	 * Create a new CreateWikiPhpDataFactory instance.
	 *
	 * @param string $wiki
	 * @return self
	 */
	public function newInstance( string $wiki ): self {
		$this->wiki = $wiki;

		$this->wikiTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );
		$this->databaseTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ) );

		if ( !$this->wikiTimestamp ) {
			$this->resetWiki();
		}

		if ( !$this->databaseTimestamp ) {
			$this->resetDatabaseList();
		}

		return $this;
	}

	/**
	 * Update function to check if the cached wiki data and database list are outdated.
	 * If either the wiki cache file or the database cache file has been modified,
	 * it will reset the corresponding cached data.
	 */
	public function update() {
		// mtime will be 0 if the file does not exist as well, which means
		// it will be generated.

		$wikiMtime = 0;
		if ( file_exists( "{$this->cacheDir}/{$this->wiki}.php" ) ) {
			$wikiMtime = $this->getCachedWikiData()['mtime'] ?? 0;
		}

		// Regenerate wiki cache if the file does not exist or has no valid mtime
		if ( $wikiMtime == 0 || $wikiMtime < $this->wikiTimestamp ) {
			$this->resetWiki( false );
		}

		$databasesMtime = 0;
		if ( file_exists( "{$this->cacheDir}/databases.php" ) ) {
			$databasesMtime = $this->getCachedDatabaseList()['mtime'] ?? 0;
		}

		// Regenerate database list if the file does not exist or has no valid mtime
		if ( $databasesMtime === 0 || $databasesMtime < $this->databaseTimestamp ) {
			$this->resetDatabaseList( false );
		}
	}

	/**
	 * Resets the cached list of databases by fetching the current list from the database.
	 * This function queries the 'cw_wikis' table for database names and clusters, and writes
	 * the updated list to a PHP file within the cache directory. It also updates the
	 * modification timestamp and stores it in the cache for future reference.
	 *
	 * @param bool $isNewChanges
	 */
	public function resetDatabaseList( bool $isNewChanges = true ) {
		$mtime = time();
		if ( $isNewChanges ) {
			$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ), $mtime );
		}

		$databaseLists = [];
		$this->hookRunner->onCreateWikiGenerateDatabaseLists( $databaseLists );

		if ( !empty( $databaseLists ) ) {
			foreach ( $databaseLists as $name => $content ) {
				$list = [
					'mtime' => $mtime,
					'databases' => $content,
				];

				$tmpFile = tempnam( '/tmp/', $name );

				if ( $tmpFile ) {
					if ( file_put_contents( $tmpFile, "<?php\n\nreturn " . var_export( $list, true ) . ";\n" ) ) {
						if ( !rename( $tmpFile, "{$this->cacheDir}/{$name}.php" ) ) {
							unlink( $tmpFile );
						}
					} else {
						unlink( $tmpFile );
					}
				}
			}

			return;
		}

		$this->dbr ??= $this->connectionProvider->getReplicaDatabase(
			$this->options->get( 'CreateWikiDatabase' )
		);

		$databaseList = $this->dbr->select(
			'cw_wikis',
			[
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_deleted',
				'wiki_url',
				'wiki_sitename',
			]
		);

		$databases = [
			'mtime' => $mtime,
			'databases' => [],
		];
		foreach ( $databaseList as $row ) {
			$databases['databases'][$row->wiki_dbname] = [
				's' => $row->wiki_sitename,
				'c' => $row->wiki_dbcluster,
			];
			if ( $row->wiki_url !== null ) {
				$databases['databases'][$row->wiki_dbname]['u'] = $row->wiki_url;
			}
		}

		$tmpFile = tempnam( '/tmp/', 'databases' );
		if ( $tmpFile ) {
			if ( file_put_contents( $tmpFile, "<?php\n\nreturn " . var_export( $databases, true ) . ";\n" ) ) {
				if ( !rename( $tmpFile, "{$this->cacheDir}/databases.php" ) ) {
					unlink( $tmpFile );
				}
			} else {
				unlink( $tmpFile );
			}
		}
	}

	/**
	 * Resets the wiki information.
	 *
	 * This method retrieves new information for the wiki and updates the cache.
	 *
	 * @param bool $isNewChanges
	 */
	public function resetWiki( bool $isNewChanges = true ) {
		$mtime = time();
		if ( $isNewChanges ) {
			$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ), $mtime );
		}

		$this->dbr ??= $this->connectionProvider->getReplicaDatabase(
			$this->options->get( 'CreateWikiDatabase' )
		);

		$wikiObject = $this->dbr->selectRow(
			'cw_wikis',
			'*',
			[ 'wiki_dbname' => $this->wiki ]
		);

		if ( !$wikiObject ) {
			throw new UnexpectedValueException( "Wiki '{$this->wiki}' cannot be found." );
		}

		$states = [];

		if ( $this->options->get( 'CreateWikiUsePrivateWikis' ) ) {
			$states['private'] = (bool)$wikiObject->wiki_private;
		}

		if ( $this->options->get( 'CreateWikiUseClosedWikis' ) ) {
			$states['closed'] = $wikiObject->wiki_closed_timestamp ?? false;
		}

		if ( $this->options->get( 'CreateWikiUseInactiveWikis' ) ) {
			$states['inactive'] = ( $wikiObject->wiki_inactive_exempt ) ? 'exempt' : ( $wikiObject->wiki_inactive_timestamp ?? false );
		}

		if ( $this->options->get( 'CreateWikiUseExperimental' ) ) {
			$states['experimental'] = (bool)$wikiObject->wiki_experimental;
		}

		$cacheArray = [
			'mtime' => $mtime,
			'database' => $wikiObject->wiki_dbname,
			'created' => $wikiObject->wiki_creation,
			'dbcluster' => $wikiObject->wiki_dbcluster,
			'category' => $wikiObject->wiki_category,
			'url' => $wikiObject->wiki_url ?? false,
			'core' => [
				'wgSitename' => $wikiObject->wiki_sitename,
				'wgLanguageCode' => $wikiObject->wiki_language,
			],
			'states' => $states,
		];

		$this->hookRunner->onCreateWikiDataFactoryBuilder( $this->wiki, $this->dbr, $cacheArray );
		$this->cacheWikiData( $cacheArray );
	}

	/**
	 * Caches the wiki data to a file.
	 *
	 * @param array $data
	 */
	private function cacheWikiData( array $data ) {
		$tmpFile = tempnam( '/tmp/', $this->wiki );

		if ( $tmpFile ) {
			if ( file_put_contents( $tmpFile, "<?php\n\nreturn " . var_export( $data, true ) . ";\n" ) ) {
				if ( !rename( $tmpFile, "{$this->cacheDir}/{$this->wiki}.php" ) ) {
					unlink( $tmpFile );
				}
			} else {
				unlink( $tmpFile );
			}
		}
	}

	/**
	 * Deletes the cache data for a wiki.
	 * Probably used when a wiki is deleted or renamed.
	 *
	 * @param string $wiki
	 */
	public function deleteWikiData( string $wiki ) {
		$this->cache->delete( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );

		if ( file_exists( "{$this->cacheDir}/$wiki.php" ) ) {
			unlink( "{$this->cacheDir}/$wiki.php" );
		}
	}

	/**
	 * Retrieves cached wiki data.
	 *
	 * @return array|null
	 */
	private function getCachedWikiData() {
		$filePath = "{$this->cacheDir}/{$this->wiki}.php";
		if ( file_exists( $filePath ) ) {
			return include $filePath;
		}

		return null;
	}

	/**
	 * Retrieves cached database list.
	 *
	 * @return array|null
	 */
	private function getCachedDatabaseList() {
		$filePath = "{$this->cacheDir}/databases.php";
		if ( file_exists( $filePath ) ) {
			return include $filePath;
		}

		return null;
	}
}
