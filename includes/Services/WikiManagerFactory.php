<?php

namespace Miraheze\CreateWiki\Services;

use ConfigException;
use Exception;
use ExtensionRegistry;
use FatalError;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LBFactoryMulti;

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

	private CreateWikiDataFactory $dataFactory;
	private CreateWikiHookRunner $hookRunner;
	private CreateWikiNotificationsManager $notificationsManager;

	private IConnectionProvider $connectionProvider;
	private MessageLocalizer $messageLocalizer;
	private UserFactory $userFactory;
	private DBConnRef $dbw;
	private DBConnRef $cwdb;

	private ServiceOptions $options;

	private ?ILoadBalancer $lb = null;

	private string $dbname;
	private array $tables = [];

	private bool $exists;
	private ?string $cluster = null;

	public function __construct(
		IConnectionProvider $connectionProvider,
		CreateWikiDataFactory $dataFactory,
		CreateWikiHookRunner $hookRunner,
		CreateWikiNotificationsManager $notificationsManager,
		UserFactory $userFactory,
		MessageLocalizer $messageLocalizer,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->connectionProvider = $connectionProvider;
		$this->dataFactory = $dataFactory;
		$this->hookRunner = $hookRunner;
		$this->messageLocalizer = $messageLocalizer;
		$this->notificationsManager = $notificationsManager;
		$this->options = $options;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param-taint $dbname tainted
	 */
	public function newInstance( string $dbname ): self {
		// Get connection for the CreateWiki database
		$this->cwdb = $this->connectionProvider->getPrimaryDatabase( 'virtual-createwiki' );

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
				$smallestClusters = array_keys( $clusterSizes, min( $clusterSizes ) );
				$this->cluster = $smallestClusters[array_rand( $smallestClusters )];

				// Make sure we set the new database in sectionsByDB early
				// so that if the cluster is empty it is populated so that a new
				// database can be created on an empty cluster.
				$lbFactoryConf['sectionsByDB'][$dbname] = $this->cluster;
				$lbFactoryMulti = new LBFactoryMulti( $lbFactoryConf );

				$lbs = $lbFactoryMulti->getAllMainLBs();
				$this->lb = $lbs[$this->cluster];
				$newDbw = $this->lb->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );
			} else {
				// DB doesn't exist, and there are no clusters
				$newDbw = $this->cwdb;
			}
		} else {
			// DB exists
			$newDbw = $this->connectionProvider->getPrimaryDatabase( $dbname );
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
			$this->dbw = $this->lb->getConnection( DB_PRIMARY, [], $this->dbname );
			return;
		}

		// If we aren't using DatabaseClusters, we don't have an LB
		// So we just connect to $this->dbname using the main
		// database configuration.
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( $this->dbname );
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

		$checkErrors = $this->checkDatabaseName( $this->dbname, forRename: false );

		if ( $checkErrors ) {
			return $checkErrors;
		}

		$this->doCreateDatabase();

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
			$this->dbw->sourceFile( $sqlfile );
		}

		$this->hookRunner->onCreateWikiCreation( $this->dbname, $private );

		DeferredUpdates::addCallableUpdate(
			function () use ( $requester, $extra ) {
				$this->recache();

				$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/setContainersAccess.php',
					[ '--wiki', $this->dbname ]
				)->limits( $limits )->execute();

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/populateMainPage.php',
					[ '--wiki', $this->dbname ]
				)->limits( $limits )->execute();

				if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
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

		$domain = $this->options->get( ConfigNames::Subdomain );
		$subdomain = substr(
			$this->dbname, 0,
			-strlen( $this->options->get( ConfigNames::DatabaseSuffix ) )
		);

		$notificationData = [
			'type' => 'wiki-creation',
			'extra' => [
				'wiki-url' => 'https://' . $subdomain . '.' . $domain,
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

		if ( $actor !== '' ) {
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

		$error = $this->checkDatabaseName( dbname: $newDatabaseName, forRename: true );

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

	public function checkDatabaseName(
		string $dbname,
		bool $forRename
	): ?string {
		$suffix = $this->options->get( ConfigNames::DatabaseSuffix );
		$suffixed = substr( $dbname, -strlen( $suffix ) ) === $suffix;
		if ( !$suffixed ) {
			return $this->messageLocalizer->msg(
				'createwiki-error-notsuffixed', $suffix
			)->parse();
		}

		if ( !$forRename && $this->exists() ) {
			return $this->messageLocalizer->msg( 'createwiki-error-dbexists' )->parse();
		}

		if ( !ctype_alnum( $dbname ) ) {
			return $this->messageLocalizer->msg( 'createwiki-error-notalnum' )->parse();
		}

		if ( strtolower( $dbname ) !== $dbname ) {
			return $this->messageLocalizer->msg( 'createwiki-error-notlowercase' )->parse();
		}

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

		$logDBConn = $this->connectionProvider->getPrimaryDatabase( 'virtual-createwiki-central' );

		$logEntry = new ManualLogEntry( $log, $action );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'CreateWiki' ) );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );
		$logID = $logEntry->insert( $logDBConn );
		$logEntry->publish( $logID );
	}

	private function compileTables(): void {
		$cTables = [];

		$this->hookRunner->onCreateWikiTables( $cTables );

		$cTables['cw_wikis'] = 'wiki_dbname';

		$this->tables = $cTables;
	}

	private function recache(): void {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-createwiki-central' );
		$data = $this->dataFactory->newInstance( $dbr->getDomainID() );

		$data->resetDatabaseLists( isNewChanges: true );
	}
}
