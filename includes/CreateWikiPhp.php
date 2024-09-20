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

		$this->cache = ObjectCache::getLocalClusterInstance();
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
		clearstatcache();
		if ( $this->wikiTimestamp < filemtime( "{$this->cacheDir}/{$this->wiki}.php" ) ) {
			$this->resetWiki();
		}

		if ( $this->databaseTimestamp < filemtime( "{$this->cacheDir}/databases.php" ) ) {
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

		$filePath = "{$this->cacheDir}/databases.php";
		file_put_contents( $filePath, "<?php\n\nreturn " . var_export( $databases, true ) . ";\n" );

		clearstatcache();
		$this->databaseTimestamp = filemtime( $filePath );
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

		$cacheArray = [
			'timestamp' => (int)$this->dbr->timestamp(),
			'database' => $wikiObject->wiki_dbname,
			'created' => $wikiObject->wiki_creation,
			'dbcluster' => $wikiObject->wiki_dbcluster,
			'category' => $wikiObject->wiki_category,
			'url' => $wikiObject->wiki_url ?? false,
			'core' => [
				'wgSitename' => $wikiObject->wiki_sitename,
				'wgLanguageCode' => $wikiObject->wiki_language,
			]
		];

		$this->hookRunner->onCreateWikiPhpBuilder( $this->wiki, $this->dbr, $cacheArray );
		$this->cacheWikiData( $cacheArray );
	}

	/**
	 * Caches the wiki data to a file.
	 *
	 * @param array $data
	 */
	private function cacheWikiData( array $data ) {
		$filePath = "{$this->cacheDir}/{$this->wiki}.php";

		$content = "<?php\n\nreturn " . var_export( $data, true ) . ";\n";
		file_put_contents( $filePath, $content );

		clearstatcache();
		$this->wikiTimestamp = filemtime( $filePath );
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
}
