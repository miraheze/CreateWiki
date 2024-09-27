<?php

namespace Miraheze\CreateWiki;

use BagOStuff;
use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCache;
use ObjectCacheFactory;
use UnexpectedValueException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

class CreateWikiDataFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'CreateWikiCacheDirectory',
		'CreateWikiCacheType',
		'CreateWikiDatabase',
		'CreateWikiUseClosedWikis',
		'CreateWikiUseExperimental',
		'CreateWikiUseInactiveWikis',
		'CreateWikiUsePrivateWikis',
	];

	private BagOStuff $cache;
	private CreateWikiHookRunner $hookRunner;
	private IConnectionProvider $connectionProvider;
	private IReadableDatabase $dbr;
	private ServiceOptions $options;

	/** @var string The wiki database name. */
	private string $wiki;

	/** @var string The directory path for cache files. */
	private string $cacheDir;

	/** @var int The cached timestamp for the databases list. */
	private int $databasesTimestamp;

	/** @var int The cached timestamp for the wiki information. */
	private int $wikiTimestamp;

	/**
	 * CreateWikiDataFactory constructor.
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
	 * Create a new CreateWikiDataFactory instance.
	 *
	 * @param string $wiki
	 * @return self
	 */
	public function newInstance( string $wiki ): self {
		$this->wiki = $wiki;

		$this->databasesTimestamp = (int)$this->cache->get(
			$this->cache->makeGlobalKey( 'CreateWiki', 'databases' )
		);

		$this->wikiTimestamp = (int)$this->cache->get(
			$this->cache->makeGlobalKey( 'CreateWiki', $wiki )
		);

		if ( !$this->databasesTimestamp ) {
			$this->resetDatabaseLists( isNewChanges: true );
		}

		if ( !$this->wikiTimestamp ) {
			$this->resetWikiData( isNewChanges: true );
		}

		return $this;
	}

	/**
	 * Syncs the cache by checking if the cached wiki data or database list is outdated.
	 * If either the wiki or database cache file has been modified, it will reset
	 * and regenerate the cached data.
	 */
	public function syncCache() {
		// mtime will be 0 if the file does not exist as well, which means
		// it will be generated.

		$databasesMtime = 0;
		if ( file_exists( "{$this->cacheDir}/databases.php" ) ) {
			$databasesMtime = $this->getCachedDatabaseList()['mtime'] ?? 0;
		}

		// Regenerate database list cache if the databases.php file does not
		// exist or has no valid mtime
		if ( $databasesMtime === 0 || $databasesMtime < $this->databasesTimestamp ) {
			$this->resetDatabaseLists( isNewChanges: false );
		}

		$wikiMtime = 0;
		if ( file_exists( "{$this->cacheDir}/{$this->wiki}.php" ) ) {
			$wikiMtime = $this->getCachedWikiData()['mtime'] ?? 0;
		}

		// Regenerate wiki data cache if the file does not exist or has no valid mtime
		if ( $wikiMtime == 0 || $wikiMtime < $this->wikiTimestamp ) {
			$this->resetWikiData( isNewChanges: false );
		}
	}

	/**
	 * Resets the cached database lists by fetching the current lists from the database.
	 * This function queries the 'cw_wikis' table for database names and clusters, and writes
	 * the updated list to a PHP file within the cache directory. It also updates the
	 * modification time (mtime) and stores it in the cache for future reference.
	 *
	 * @param bool $isNewChanges
	 */
	public function resetDatabaseLists( bool $isNewChanges ) {
		$mtime = time();
		if ( $isNewChanges ) {
			$this->cache->set(
				$this->cache->makeGlobalKey( 'CreateWiki', 'databases' ),
				$mtime
			);
		}

		$databaseLists = [];
		$this->hookRunner->onCreateWikiGenerateDatabaseLists( $databaseLists );

		if ( $databaseLists ) {
			foreach ( $databaseLists as $name => $content ) {
				$list = [
					'mtime' => $mtime,
					'databases' => $content,
				];

				$this->writeToFile( $name, $list );
			}

			return;
		}

		$this->dbr ??= $this->connectionProvider->getReplicaDatabase(
			$this->options->get( 'CreateWikiDatabase' )
		);

		$databaseList = $this->dbr->newSelectQueryBuilder()
			->table( 'cw_wikis' )
			->fields( [
				'wiki_dbcluster',
				'wiki_dbname',
				'wiki_deleted',
				'wiki_sitename',
				'wiki_url',
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$databases = [];
		foreach ( $databaseList as $row ) {
			$databases[$row->wiki_dbname] = [
				's' => $row->wiki_sitename,
				'c' => $row->wiki_dbcluster,
			];

			if ( $row->wiki_url !== null ) {
				$databases[$row->wiki_dbname]['u'] = $row->wiki_url;
			}
		}

		$list = [
			'mtime' => $mtime,
			'databases' => $databases,
		];

		$this->writeToFile( 'databases', $list );
	}

	/**
	 * Resets the wiki data information.
	 *
	 * This method retrieves new information for the wiki and updates the cache.
	 *
	 * @param bool $isNewChanges
	 */
	public function resetWikiData( bool $isNewChanges ) {
		$mtime = time();
		if ( $isNewChanges ) {
			$this->cache->set(
				$this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ),
				$mtime
			);
		}

		$this->dbr ??= $this->connectionProvider->getReplicaDatabase(
			$this->options->get( 'CreateWikiDatabase' )
		);

		$row = $this->dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $this->wiki ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			throw new UnexpectedValueException( "Wiki '{$this->wiki}' cannot be found." );
		}

		$states = [];

		if ( $this->options->get( 'CreateWikiUsePrivateWikis' ) ) {
			$states['private'] = (bool)$row->wiki_private;
		}

		if ( $this->options->get( 'CreateWikiUseClosedWikis' ) ) {
			$states['closed'] = (bool)$row->wiki_closed;
		}

		if ( $this->options->get( 'CreateWikiUseInactiveWikis' ) ) {
			$states['inactive'] = $row->wiki_inactive_exempt ? 'exempt' :
				(bool)$row->wiki_inactive;
		}

		if ( $this->options->get( 'CreateWikiUseExperimental' ) ) {
			$states['experimental'] = (bool)$row->wiki_experimental;
		}

		$cacheArray = [
			'mtime' => $mtime,
			'database' => $row->wiki_dbname,
			'created' => $row->wiki_creation,
			'dbcluster' => $row->wiki_dbcluster,
			'category' => $row->wiki_category,
			'url' => $row->wiki_url ?? false,
			'core' => [
				'wgSitename' => $row->wiki_sitename,
				'wgLanguageCode' => $row->wiki_language,
			],
			'states' => $states,
		];

		$this->hookRunner->onCreateWikiDataFactoryBuilder( $this->wiki, $this->dbr, $cacheArray );
		$this->writeToFile( $this->wiki, $cacheArray );
	}

	/**
	 * Deletes the wiki data cache for a wiki.
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
	 * Writes data to a PHP file in the cache directory.
	 *
	 * @param string $fileName
	 * @param array $data
	 */
	private function writeToFile( string $fileName, array $data ) {
		$tmpFile = tempnam( wfTempDir(), $fileName );
		if ( $tmpFile ) {
			if ( file_put_contents( $tmpFile, "<?php\n\nreturn " . var_export( $data, true ) . ";\n" ) ) {
				if ( !rename( $tmpFile, "{$this->cacheDir}/{$fileName}.php" ) ) {
					unlink( $tmpFile );
				}
			} else {
				unlink( $tmpFile );
			}
		}
	}

	/**
	 * Retrieves cached wiki data.
	 *
	 * @return ?array
	 */
	private function getCachedWikiData(): ?array {
		$filePath = "{$this->cacheDir}/{$this->wiki}.php";
		if ( file_exists( $filePath ) ) {
			return include $filePath;
		}

		return null;
	}

	/**
	 * Retrieves cached database list.
	 *
	 * @return ?array
	 */
	private function getCachedDatabaseList(): ?array {
		$filePath = "{$this->cacheDir}/databases.php";
		if ( file_exists( $filePath ) ) {
			return include $filePath;
		}

		return null;
	}
}
