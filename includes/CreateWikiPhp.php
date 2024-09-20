<?php

namespace Miraheze\CreateWiki;

use BagOStuff;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use ObjectCache;
use UnexpectedValueException;
use Wikimedia\AtEase\AtEase;
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
		if ( !$this->wikiTimestamp ) {
			$this->resetWiki();
		}
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

		$jsonArray = [
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

		$this->hookRunner->onCreateWikiPhpBuilder( $this->wiki, $this->dbr, $jsonArray );
		$this->cacheWikiData( $jsonArray );
	}

	/**
	 * Caches the wiki data to a file.
	 *
	 * @param array $data
	 */
	private function cacheWikiData( array $data ) {
		$filePath = "{$this->cacheDir}/{$this->wiki}.php";
		$data['timestamp'] = time();

		$content = "<?php\n\nreturn " . var_export( $data, true ) . ";\n";
		file_put_contents( $filePath, $content );

		$this->cache->set( $this->cache->makeGlobalKey( 'CreateWiki', $this->wiki ), $data['timestamp'] );
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
