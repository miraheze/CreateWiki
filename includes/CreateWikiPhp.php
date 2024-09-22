<?php

namespace Miraheze\CreateWiki;

use BagOStuff;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCache;
use UnexpectedValueException;
use Wikimedia\Rdbms\DBConnRef;

class CreateWikiPhp {

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
	 * The CreateWiki hook runner object.
	 *
	 * @var CreateWikiHookRunner
	 */
	private $hookRunner;

	/**
	 * CreateWikiPhp constructor.
	 *
	 * @param string $wiki
	 * @param CreateWikiHookRunner|null $hookRunner
	 */
	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );

		$this->hookRunner = $hookRunner ?? MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		$objectCacheFactory = MediaWikiServices::getInstance()->getObjectCacheFactory();
		$this->cache = $this->config->get( 'CreateWikiCacheType' ) ?
			$objectCacheFactory->getInstance( $this->config->get( 'CreateWikiCacheType' ) ) :
			ObjectCache::getLocalClusterInstance();
		$this->cacheDir = $this->config->get( 'CreateWikiCacheDirectory' );
		$this->wiki = $wiki;

		$this->wikiTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', $wiki ) );
		$this->databaseTimestamp = (int)$this->cache->get( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ) );

		if ( !$this->wikiTimestamp ) {
			$this->resetWiki();
		}

		if ( !$this->databaseTimestamp ) {
			$this->resetDatabaseList();
		}
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
			$this->resetWiki();
		}

		$databasesMtime = 0;
		if ( file_exists( "{$this->cacheDir}/databases.php" ) ) {
			$databasesMtime = $this->getCachedDatabaseList()['mtime'] ?? 0;
		}

		// Regenerate database list if the file does not exist or has no valid mtime
		if ( $databasesMtime === 0 || $databasesMtime < $this->databaseTimestamp ) {
			$this->resetDatabaseList();
		}
	}

	/**
	 * Resets the cached list of databases by fetching the current list from the database.
	 * This function queries the 'cw_wikis' table for database names and clusters, and writes
	 * the updated list to a PHP file within the cache directory. It also updates the
	 * modification timestamp and stores it in the cache for future reference.
	 */
	public function resetDatabaseList() {
		$databaseLists = [];
		$this->hookRunner->onCreateWikiPhpGenerateDatabaseList( $databaseLists );

		$mtime = time();

		if ( !empty( $databaseLists ) ) {
			foreach ( $databaseLists as $name => $content ) {
				$list = [
					'mtime' => $mtime,
					'databases' => $content,
				];

				$this->writeWithLock( $name, $list );
			}

			$this->databaseTimestamp = $mtime;
			$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ), $this->databaseTimestamp );
			return;
		}

		$this->dbr ??= MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );

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

		$this->writeWithLock( 'databases', $databases );

		$this->databaseTimestamp = $mtime;
		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', 'databases' ), $this->databaseTimestamp );
	}

	/**
	 * Resets the wiki information.
	 *
	 * This method retrieves new information for the wiki and updates the cache.
	 */
	public function resetWiki() {
		$this->dbr ??= MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
			->getMaintenanceConnectionRef( DB_REPLICA, [], $this->config->get( 'CreateWikiDatabase' ) );

		$wikiObject = $this->dbr->selectRow(
			'cw_wikis',
			'*',
			[ 'wiki_dbname' => $this->wiki ]
		);

		if ( !$wikiObject ) {
			throw new UnexpectedValueException( "Wiki '{$this->wiki}' cannot be found." );
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

		$cacheArray = [
			'mtime' => time(),
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

		$this->hookRunner->onCreateWikiPhpBuilder( $this->wiki, $this->dbr, $cacheArray );

		$this->writeWithLock( $this->wiki, $data );

		$this->wikiTimestamp = $data['mtime'];
		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ), $this->wikiTimestamp );
	}

	/**
	 * Retrieves cached wiki data.
	 *
	 * @return array|null
	 */
	public function getCachedWikiData() {
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
	public function getCachedDatabaseList() {
		$filePath = "{$this->cacheDir}/databases.php";
		if ( file_exists( $filePath ) ) {
			return include $filePath;
		}

		return null;
	}

	/**
	 * Writes data to a PHP file within the cache directory, ensuring that
	 * the operation is protected by a file lock to prevent concurrent write issues.
	 * The method updates the PHP file asynchronously, checking if the existing file
	 * is newer than the temporary file before writing.
	 *
	 * @param string $list The base name of the cache file (without the .php extension).
	 * @param array $data The associative array to be written as a PHP file.
	 */
	private function writeWithLock( string $list, array $data ) {
		$filePath = "{$this->cacheDir}/$list.php";

		// Check if the existing file is newer than the temporary file
		if ( file_exists( $filePath ) && ( filemtime( $filePath ) > $data['mtime'] ) ) {
			// If $list.php is newer, return without writing.
			// This likely means it is already updated and we
			// don't want a race condition.
			return;
		}

		// Serialize data to PHP format
		$content = "<?php\n\nreturn " . var_export( $data, true ) . ";\n";

		// Generate a unique temporary file name in the cache directory
		$tempFilePath = tempnam( $this->cacheDir, "{$list}_tmp_" );

		// Write content to the temporary file with exclusive lock
		if ( file_put_contents( $tempFilePath, $content, LOCK_EX ) !== false ) {
			// Rename the temporary file to the final file
			if ( !rename( $tempFilePath, $filePath ) ) {
				// If rename fails, delete the temporary file
				unlink( $tempFilePath );
			}
		} else {
			// If write failed, delete the temporary file
			unlink( $tempFilePath );
		}
	}
}
