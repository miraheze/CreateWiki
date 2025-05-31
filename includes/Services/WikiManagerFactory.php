<?php

namespace Miraheze\CreateWiki\Services;

use Exception;
use FatalError;
use ManualLogEntry;
use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Maintenance\PopulateMainPage;
use Miraheze\CreateWiki\Maintenance\SetContainersAccess;
use Wikimedia\Rdbms\DBConnectionError;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LBFactoryMulti;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_rand;
use function json_encode;
use function min;
use function wfTimestamp;
use const DB_PRIMARY;
use const MW_INSTALL_PATH;
use const TS_UNIX;

class WikiManagerFactory {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Collation,
		ConfigNames::DatabaseClusters,
		ConfigNames::DatabaseSuffix,
		ConfigNames::SQLFiles,
		ConfigNames::StateDays,
		ConfigNames::Subdomain,
		MainConfigNames::LBFactoryConf,
	];

	private DBConnRef $dbw;
	private DBConnRef $cwdb;

	private ?ILoadBalancer $lb = null;

	private string $dbname;
	private array $tables = [];

	private bool $exists;
	private ?string $cluster = null;

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataFactory $dataFactory,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly CreateWikiNotificationsManager $notificationsManager,
		private readonly CreateWikiValidator $validator,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserFactory $userFactory,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * @param-taint $dbname tainted
	 */
	public function newInstance( string $dbname ): self {
		// Get connection for the CreateWiki database
		$this->cwdb = $this->databaseUtils->getGlobalPrimaryDB();

		// Check if the database exists in the cw_wikis table
		$check = $this->cwdb->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $dbname ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$check ) {
			$hasClusters = $this->options->get( ConfigNames::DatabaseClusters );
			if ( $hasClusters ) {
				// DB doesn't exist, and we have clusters

				// Make sure we are using LBFactoryMulti
				$lbFactoryConf = $this->options->get( MainConfigNames::LBFactoryConf );
				if ( $lbFactoryConf['class'] !== LBFactoryMulti::class ) {
					throw new ConfigException(
						'Must use LBFactoryMulti when using clusters with CreateWiki.'
					);
				}

				// Calculate the size of each cluster
				$clusterSizes = [];
				foreach ( $hasClusters as $cluster ) {
					$clusterSizes[$cluster] = $this->cwdb->newSelectQueryBuilder()
						->select( '*' )
						->from( 'cw_wikis' )
						->where( [ 'wiki_dbcluster' => $cluster ] )
						->caller( __METHOD__ )
						->fetchRowCount();
				}

				// Pick the cluster with the least number of databases
				$smallestClusters = array_keys( $clusterSizes, min( $clusterSizes ), true );
				$this->cluster = (string)$smallestClusters[array_rand( $smallestClusters )] ?: null;

				// Make sure we set the new database in sectionsByDB early
				// so that if the cluster is empty it is populated so that a new
				// database can be created on an empty cluster.
				$lbFactoryConf['sectionsByDB'][$dbname] = $this->cluster;
				$lbFactoryMulti = new LBFactoryMulti( $lbFactoryConf );

				$lbs = $lbFactoryMulti->getAllMainLBs();
				$this->lb = $lbs[$this->cluster];
				$newDbw = $this->lb->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );
				if ( $newDbw === false ) {
					throw new DBConnectionError();
				}
			} else {
				// DB doesn't exist, and there are no clusters
				$newDbw = $this->cwdb;
			}
		} else {
			// DB exists
			$newDbw = $this->databaseUtils->getRemoteWikiPrimaryDB( $dbname );
		}

		$this->dbname = $dbname;
		$this->dbw = $newDbw;
		$this->exists = (bool)$check;

		return $this;
	}

	public function exists(): bool {
		return $this->exists;
	}

	public function doCreateDatabase(): void {
		try {
			$dbCollation = $this->options->get( ConfigNames::Collation );
			$dbQuotes = $this->dbw->addIdentifierQuotes( $this->dbname );
			$this->dbw->query( "CREATE DATABASE {$dbQuotes} {$dbCollation};", __METHOD__ );
		} catch ( Exception $e ) {
			throw new FatalError( "Wiki '{$this->dbname}' already exists." );
		}

		if ( $this->lb ) {
			// If we are using DatabaseClusters we will have an LB
			// and we will use that which will use the clusters
			// defined in $wgLBFactoryConf.
			$conn = $this->lb->getConnection( DB_PRIMARY, [], $this->dbname );
			if ( $conn === false ) {
				throw new DBConnectionError();
			}
			$this->dbw = $conn;
			return;
		}

		// If we aren't using DatabaseClusters, we don't have an LB
		// So we just connect to $this->dbname using the main
		// database configuration.
		$this->dbw = $this->databaseUtils->getRemoteWikiPrimaryDB( $this->dbname );
	}

	public function create(
		string $sitename,
		string $language,
		bool $private,
		string $category,
		string $requester,
		string $actor,
		string $reason,
		array $extra
	): ?string {
		if ( $this->exists() ) {
			throw new FatalError( "Wiki '{$this->dbname}' already exists." );
		}

		$checkErrors = $this->validator->validateDatabaseName(
			dbname: $this->dbname,
			exists: $this->exists()
		);

		if ( $checkErrors ) {
			return $checkErrors;
		}

		$this->doCreateDatabase();

		$extraFields = [];
		$this->hookRunner->onCreateWikiCreationExtraFields( $extraFields );

		// Filter $extra to only include keys present in $extraFields
		$filteredData = array_intersect_key( $extra, array_flip( $extraFields ) );
		$extraData = json_encode( $filteredData ) ?: '[]';

		$this->cwdb->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->row( [
				'wiki_dbname' => $this->dbname,
				'wiki_dbcluster' => $this->cluster,
				'wiki_sitename' => $sitename,
				'wiki_language' => $language,
				'wiki_private' => (int)$private,
				'wiki_creation' => $this->dbw->timestamp(),
				'wiki_category' => $category,
				'wiki_extra' => $extraData,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->doAfterCreate(
			$sitename,
			$private,
			$requester,
			$actor,
			$reason,
			$extra
		);

		return null;
	}

	private function doAfterCreate(
		string $sitename,
		bool $private,
		string $requester,
		string $actor,
		string $reason,
		array $extra
	): void {
		foreach ( $this->options->get( ConfigNames::SQLFiles ) as $sqlfile ) {
			$this->dbw->sourceFile( $sqlfile, fname: __METHOD__ );
		}

		$this->hookRunner->onCreateWikiCreation( $this->dbname, $private );

		DeferredUpdates::addCallableUpdate(
			function () use ( $requester, $extra ) {
				$this->recache();

				$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

				Shell::makeScriptCommand(
					SetContainersAccess::class,
					[ '--wiki', $this->dbname ]
				)->limits( $limits )->execute();

				Shell::makeScriptCommand(
					PopulateMainPage::class,
					[ '--wiki', $this->dbname ]
				)->limits( $limits )->execute();

				if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
					Shell::makeScriptCommand(
						MW_INSTALL_PATH . '/extensions/CentralAuth/maintenance/createLocalAccount.php',
						[
							$requester,
							'--wiki', $this->dbname
						]
					)->limits( $limits )->execute();

					Shell::makeScriptCommand(
						MW_INSTALL_PATH . '/maintenance/createAndPromote.php',
						[
							$requester,
							'--bureaucrat',
							'--interface-admin',
							'--sysop',
							'--force',
							'--wiki', $this->dbname
						]
					)->limits( $limits )->execute();
				}

				if ( $extra ) {
					$this->hookRunner->onCreateWikiAfterCreationWithExtraData( $extra, $this->dbname );
				}
			},
			DeferredUpdates::POSTSEND,
			$this->cwdb
		);

		if ( $actor !== '' ) {
			$notificationData = [
				'type' => 'wiki-creation',
				'extra' => [
					'wiki-url' => $this->validator->getValidUrl( $this->dbname ),
					'sitename' => $sitename,
				],
				'subject' => $this->messageLocalizer->msg(
					'createwiki-email-subject', $sitename
				)->inContentLanguage()->escaped(),
				'body' => [
					'html' => $this->messageLocalizer->msg(
						'createwiki-email-body'
					)->inContentLanguage()->parse(),
					'text' => $this->messageLocalizer->msg(
						'createwiki-email-body'
					)->inContentLanguage()->text(),
				],
			];

			$this->notificationsManager->sendNotification( $notificationData, [ $requester ] );
			$this->logEntry( 'farmer', 'createwiki', $actor, $reason, [ '4::wiki' => $this->dbname ] );
		}
	}

	public function delete( bool $force ): ?string {
		$this->compileTables();

		$row = $this->cwdb->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $this->dbname ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			throw new MissingWikiError( $this->dbname );
		}

		$deletionDate = $row->wiki_deleted_timestamp;
		$unixDeletion = (int)wfTimestamp( TS_UNIX, $deletionDate );
		$unixNow = (int)wfTimestamp( TS_UNIX, $this->dbw->timestamp() );

		// Return error if the wiki is not deleted, force is not used, or the deletion grace period has not passed.
		$deletionGracePeriod = (int)$this->options->get( ConfigNames::StateDays )['deleted'] * 86400;
		$deletedWiki = (bool)$row->wiki_deleted && (bool)$row->wiki_deleted_timestamp;

		if (
			!$force &&
			(
				!$deletedWiki ||
				( $unixNow - $unixDeletion ) < $deletionGracePeriod
			)
		) {
			return "Wiki {$this->dbname} can not be deleted yet.";
		}

		$data = $this->dataFactory->newInstance( $this->dbname );
		$data->deleteWikiData( $this->dbname );

		foreach ( $this->tables as $table => $selector ) {
			$this->cwdb->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( [ $selector => $this->dbname ] )
				->caller( __METHOD__ )
				->execute();
		}

		$this->recache();

		$this->hookRunner->onCreateWikiDeletion( $this->cwdb, $this->dbname );

		return null;
	}

	public function rename( string $newDatabaseName ): ?string {
		// NOTE: $this->dbname is the old database name

		$this->compileTables();

		$error = $this->validator->validateDatabaseName(
			dbname: $newDatabaseName,
			// It shouldn't ever exist yet as we are renaming to it.
			exists: false
		);

		if ( $error ) {
			return "Can not rename {$this->dbname} to {$newDatabaseName} because: {$error}";
		}

		foreach ( $this->tables as $table => $selector ) {
			$this->cwdb->newUpdateQueryBuilder()
				->update( $table )
				->set( [ $selector => $newDatabaseName ] )
				->where( [ $selector => $this->dbname ] )
				->caller( __METHOD__ )
				->execute();
		}

		/**
		 * Since the wiki at $new likely won't be cached yet, this will also
		 * run resetWikiData() on it since it has no mtime, so that it will
		 * generate the new cache file for it as well.
		 */
		$data = $this->dataFactory->newInstance( $newDatabaseName );
		$data->deleteWikiData( $this->dbname );

		$this->recache();

		$this->hookRunner->onCreateWikiRename( $this->cwdb, $this->dbname, $newDatabaseName );

		return null;
	}

	private function logEntry(
		string $log,
		string $action,
		string $actor,
		string $reason,
		array $params
	): void {
		$user = $this->userFactory->newFromName( $actor );

		if ( !$user ) {
			return;
		}

		$logDBConn = $this->databaseUtils->getCentralWikiPrimaryDB();

		$logEntry = new ManualLogEntry( $log, $action );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'CreateWiki' ) );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );
		$logID = $logEntry->insert( $logDBConn );
		$logEntry->publish( $logID );
	}

	private function compileTables(): void {
		$tables = [];

		$this->hookRunner->onCreateWikiTables( $tables );

		$tables['cw_wikis'] = 'wiki_dbname';

		$this->tables = $tables;
	}

	private function recache(): void {
		$centralWiki = $this->databaseUtils->getCentralWikiID();
		$data = $this->dataFactory->newInstance( $centralWiki );
		$data->resetDatabaseLists( isNewChanges: true );
	}
}
