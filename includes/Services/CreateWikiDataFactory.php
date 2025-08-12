<?php

namespace Miraheze\CreateWiki\Services;

use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCacheFactory;
use stdClass;
use Wikimedia\AtEase\AtEase;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\IReadableDatabase;
use function class_exists;
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

	/** @var string The directory path for cache files. */
	private readonly string $cacheDir;

	/** @var int The cached timestamp for the databases list. */
	private int $databasesTimestamp;

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

	public function newInstance(): self {
		$this->databasesTimestamp = (int)$this->cache->get(
			$this->cache->makeGlobalKey( 'CreateWiki', 'databases' )
		);

		if ( !$this->databasesTimestamp ) {
			$this->resetDatabaseLists( isNewChanges: true );
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
	}

	/**
	 * Resets the cached database lists by fetching the current lists from the database.
	 * This function queries the 'cw_wikis' table for database names and clusters, and writes
	 * the updated list to a PHP file within the cache directory. It also updates the
	 * modification time (mtime) and stores it in the cache for future reference.
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
	 * Writes data to a PHP file in the cache directory.
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
