<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCacheFactory;
use stdClass;
use Wikimedia\AtEase\AtEase;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\IReadableDatabase;
use function file_exists;
use function file_put_contents;
use function is_array;
use function rename;
use function tempnam;
use function time;
use function unlink;
use function var_export;
use function wfTempDir;

class CreateWikiDataFactory {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::CacheDirectory,
		ConfigNames::CacheType,
		ConfigNames::UseClosedWikis,
		ConfigNames::UseExperimental,
		ConfigNames::UseInactiveWikis,
		ConfigNames::UsePrivateWikis,
	];

	private readonly BagOStuff $cache;
	private IReadableDatabase $dbr;

	/** @var string The wiki database name. */
	private string $dbname;

	/** @var string The directory path for cache files. */
	private readonly string $cacheDir;

	/** @var int The cached timestamp for the databases list. */
	private int $databasesTimestamp;

	/** @var int The cached timestamp for the wiki information. */
	private int $wikiTimestamp;

	public function __construct(
		ObjectCacheFactory $objectCacheFactory,
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->cache = ( $this->options->get( ConfigNames::CacheType ) !== null ) ?
			$objectCacheFactory->getInstance( $this->options->get( ConfigNames::CacheType ) ) :
			$objectCacheFactory->getLocalClusterInstance();

		$this->cacheDir = $this->options->get( ConfigNames::CacheDirectory );
	}

	public function newInstance( string $dbname ): self {
		$this->dbname = $dbname;

		$this->databasesTimestamp = (int)$this->cache->get(
			$this->cache->makeGlobalKey( 'CreateWiki', 'databases' )
		);

		$this->wikiTimestamp = (int)$this->cache->get(
			$this->cache->makeGlobalKey( 'CreateWiki', $dbname )
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
	public function syncCache(): void {
		// mtime will be 0 if the file does not exist as well, which means
		// it will be generated.

		$databasesMtime = $this->getCachedDatabaseList()['mtime'] ?? 0;

		// Regenerate database list cache if the databases.php file does not
		// exist or has no valid mtime
		if ( $databasesMtime === 0 || $databasesMtime < $this->databasesTimestamp ) {
			$this->resetDatabaseLists( isNewChanges: false );
		}

		$wikiMtime = $this->getCachedWikiData()['mtime'] ?? 0;

		// Regenerate wiki data cache if the file does not exist or has no valid mtime
		if ( $wikiMtime === 0 || $wikiMtime < $this->wikiTimestamp ) {
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
	public function resetDatabaseLists( bool $isNewChanges ): void {
		$mtime = time();
		if ( $isNewChanges ) {
			$this->databasesTimestamp = $mtime;
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

		$this->dbr ??= $this->databaseUtils->getGlobalReplicaDB();

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
			if ( !$row instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

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
	public function resetWikiData( bool $isNewChanges ): void {
		$mtime = time();
		if ( $isNewChanges ) {
			$this->wikiTimestamp = $mtime;
			$this->cache->set(
				$this->cache->makeGlobalKey( 'CreateWiki', $this->dbname ),
				$mtime
			);
		}

		$this->dbr ??= $this->databaseUtils->getGlobalReplicaDB();

		$row = $this->dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $this->dbname ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			if ( $this->databaseUtils->isRemoteWikiCentral( $this->dbname ) ) {
				// Don't throw an exception if we have not yet populated the
				// central wiki, so that the PopulateCentralWiki script can
				// successfully populate it.
				return;
			}

			throw new MissingWikiError( $this->dbname );
		}

		$states = [];

		if ( $this->options->get( ConfigNames::UsePrivateWikis ) ) {
			$states['private'] = (bool)$row->wiki_private;
		}

		if ( $this->options->get( ConfigNames::UseClosedWikis ) ) {
			$states['closed'] = (bool)$row->wiki_closed;
		}

		if ( $this->options->get( ConfigNames::UseInactiveWikis ) ) {
			$states['inactive'] = $row->wiki_inactive_exempt ? 'exempt' :
				(bool)$row->wiki_inactive;
		}

		if ( $this->options->get( ConfigNames::UseExperimental ) ) {
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

		$this->hookRunner->onCreateWikiDataFactoryBuilder( $this->dbname, $this->dbr, $cacheArray );
		$this->writeToFile( $this->dbname, $cacheArray );
	}

	/**
	 * Deletes the wiki data cache for a wiki.
	 * Probably used when a wiki is deleted or renamed.
	 *
	 * @param string $dbname
	 */
	public function deleteWikiData( string $dbname ): void {
		$this->cache->delete( $this->cache->makeGlobalKey( 'CreateWiki', $dbname ) );

		if ( file_exists( "{$this->cacheDir}/{$dbname}.php" ) ) {
			unlink( "{$this->cacheDir}/{$dbname}.php" );
		}
	}

	/**
	 * Writes data to a PHP file in the cache directory.
	 *
	 * @param string $fileName
	 * @param array $data
	 */
	private function writeToFile( string $fileName, array $data ): void {
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
	 * @return array Cached wiki data.
	 */
	private function getCachedWikiData(): array {
		// Avoid using file_exists for performance reasons. Including the file directly leverages
		// the opcode cache and prevents any file system access.
		// We only handle failures if the include does not work.

		$filePath = "{$this->cacheDir}/{$this->dbname}.php";
		$cacheData = AtEase::quietCall( static function ( $path ) {
			return include $path;
		}, $filePath );

		if ( is_array( $cacheData ) ) {
			return $cacheData;
		}

		return [ 'mtime' => 0 ];
	}

	/**
	 * @return array Cached database list
	 */
	private function getCachedDatabaseList(): array {
		// Avoid using file_exists for performance reasons. Including the file directly leverages
		// the opcode cache and prevents any file system access.
		// We only handle failures if the include does not work.

		$filePath = "{$this->cacheDir}/databases.php";
		$cacheData = AtEase::quietCall( static function ( $path ) {
			return include $path;
		}, $filePath );

		if ( is_array( $cacheData ) ) {
			return $cacheData;
		}

		return [ 'mtime' => 0 ];
	}
}
