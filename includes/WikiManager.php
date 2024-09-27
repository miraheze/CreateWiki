<?php

namespace Miraheze\CreateWiki;

use Exception;
use ExtensionRegistry;
use FatalError;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MessageLocalizer;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use RuntimeException;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Rdbms\ILoadBalancer;

class WikiManager {

	private CreateWikiDataFactory $dataFactory;
	private CreateWikiHookRunner $hookRunner;
	private CreateWikiNotificationsManager $notificationsManager;

	private Config $config;
	private IConnectionProvider $connectionProvider;
	private MessageLocalizer $messageLocalizer;
	private UserFactory $userFactory;
	private DBConnRef $dbw;
	private DBConnRef $cwdb;

	private ?ILoadBalancer $lb = null;

	private string $dbname;
	private array $tables = [];

	public bool $exists;
	public ?string $cluster = null;

	public function __construct(
		string $dbname,
		CreateWikiHookRunner $hookRunner
	) {
		$services = MediaWikiServices::getInstance();
		$this->config = $services->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->connectionProvider = $services->getConnectionProvider();
		$this->dataFactory = $services->get( 'CreateWikiDataFactory' );
		$this->hookRunner = $hookRunner;
		$this->messageLocalizer = RequestContext::getMain();
		$this->notificationsManager = $services->get( 'CreateWiki.NotificationsManager' );
		$this->userFactory = $services->getUserFactory();

		// Get connection for the CreateWiki database
		$createWikiDBName = $this->config->get( 'CreateWikiDatabase' );
		$this->cwdb = $this->connectionProvider->getPrimaryDatabase( $createWikiDBName );

		// Check if the database exists in the cw_wikis table
		$check = $this->cwdb->selectRow(
			'cw_wikis',
			'wiki_dbname',
			[ 'wiki_dbname' => $dbname ],
			__METHOD__
		);

		$hasClusters = $this->config->get( 'CreateWikiDatabaseClusters' );

		if ( !$check ) {
			if ( $hasClusters ) {
				// DB doesn't exist, and we have clusters
				$lbFactory = $this->connectionProvider;
				'@phan-var ILBFactory $lbFactory';
				$lbs = $lbFactory->getAllMainLBs();

				// Calculate the size of each cluster
				$clusterSizes = [];
				foreach ( $hasClusters as $cluster ) {
					$clusterSizes[$cluster] = $this->cwdb->selectRowCount(
						'cw_wikis',
						'*',
						[ 'wiki_dbcluster' => $cluster ]
					);
				}

				// Pick the cluster with the least number of databases
				$smallestClusters = array_keys( $clusterSizes, min( $clusterSizes ) );
				$this->cluster = $smallestClusters[array_rand( $smallestClusters )];

				// Select a database in the chosen cluster
				$clusterDBRow = $this->cwdb->selectRow(
					'cw_wikis',
					'wiki_dbname',
					[ 'wiki_dbcluster' => $this->cluster ]
				);

				if ( !$clusterDBRow ) {
					// Handle case where no database exists in the chosen cluster
					throw new RuntimeException( 'No databases found in the selected cluster: ' . $this->cluster );
				}

				$clusterDB = $clusterDBRow->wiki_dbname;
				$this->lb = $lbs[$this->cluster];
				$newDbw = $this->lb->getConnection( DB_PRIMARY, [], $clusterDB );
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
	}

	public function doCreateDatabase(): ?string {
		if ( $this->exists ) {
			throw new FatalError( "Wiki '{$this->dbname}' already exists." );
		}

		$checkErrors = $this->checkDatabaseName( $this->dbname );

		if ( $checkErrors ) {
			return $checkErrors;
		}

		try {
			$dbCollation = $this->config->get( 'CreateWikiCollation' );
			$dbQuotes = $this->dbw->addIdentifierQuotes( $this->dbname );
			$this->dbw->query( "CREATE DATABASE {$dbQuotes} {$dbCollation};" );
		} catch ( Exception $e ) {
			throw new FatalError( "Wiki '{$this->dbname}' already exists." );
		}

		if ( $this->lb ) {
			$this->dbw = $this->lb->getConnection( DB_PRIMARY, [], $this->dbname );
		} else {
			$this->dbw = $this->connectionProvider->getPrimaryDatabase( $this->dbname );
		}

		return null;
	}

	public function create(
		string $siteName,
		string $language,
		bool $private,
		string $category,
		string $requester,
		string $actor,
		string $reason
	): ?string {
		if ( $this->doCreateDatabase() ) {
			return $this->doCreateDatabase();
		}

		$this->cwdb->insert(
			'cw_wikis',
			[
				'wiki_dbname' => $this->dbname,
				'wiki_dbcluster' => $this->cluster,
				'wiki_sitename' => $siteName,
				'wiki_language' => $language,
				'wiki_private' => (int)$private,
				'wiki_creation' => $this->dbw->timestamp(),
				'wiki_category' => $category,
			]
		);

		$this->doAfterCreate( $siteName, $private, $requester, $actor, $reason );

		return null;
	}

	public function doAfterCreate(
		string $siteName,
		bool $private,
		string $requester,
		string $actor,
		string $reason,
		bool $notify = true,
		bool $centralAuth = true
	): void {
		foreach ( $this->config->get( 'CreateWikiSQLfiles' ) as $sqlfile ) {
			$this->dbw->sourceFile( $sqlfile );
		}

		$this->hookRunner->onCreateWikiCreation( $this->dbname, $private );

		DeferredUpdates::addCallableUpdate(
			function () use ( $requester, $centralAuth ) {
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

				if ( $centralAuth ) {
					if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
						Shell::makeScriptCommand(
							MW_INSTALL_PATH . '/extensions/CentralAuth/maintenance/createLocalAccount.php',
							[
								$requester,
								'--wiki', $this->dbname
							]
						)->limits( $limits )->execute();
					}

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
			},
			DeferredUpdates::POSTSEND,
			$this->cwdb
		);

		if ( $notify ) {
			$notificationData = [
				'type' => 'wiki-creation',
				'extra' => [
					'wiki-url' => 'https://' . substr( $this->dbname, 0, -strlen( $this->config->get( 'CreateWikiDatabaseSuffix' ) ) ) . ".{$this->config->get( 'CreateWikiSubdomain' )}",
					'sitename' => $siteName,
				],
				'subject' => $this->messageLocalizer->msg(
					'createwiki-email-subject', $siteName
				)->inContentLanguage()->text(),
				'body' => [
					'html' => nl2br( $this->messageLocalizer->msg(
						'createwiki-email-body'
					)->inContentLanguage()->text() ),
					'text' => $this->messageLocalizer->msg(
						'createwiki-email-body'
					)->inContentLanguage()->text(),
				],
			];

			$this->notificationsManager->sendNotification( $notificationData, [ $requester ] );

			$this->logEntry( 'farmer', 'createwiki', $actor, $reason, [ '4::wiki' => $this->dbname ] );
		}
	}

	public function delete( bool $force = false ): ?string {
		$this->compileTables();

		$row = $this->cwdb->selectRow(
			'cw_wikis',
			'*',
			[ 'wiki_dbname' => $this->dbname ]
		);

		$deletionDate = $row->wiki_deleted_timestamp;
		$unixDeletion = (int)wfTimestamp( TS_UNIX, $deletionDate );
		$unixNow = (int)wfTimestamp( TS_UNIX, $this->dbw->timestamp() );

		$deletedWiki = (bool)$row->wiki_deleted && (bool)$row->wiki_deleted_timestamp;

		// Return error if: wiki is not deleted, force is not used & wiki
		if ( !$force && ( !$deletedWiki || ( $unixNow - $unixDeletion ) < ( (int)$this->config->get( 'CreateWikiStateDays' )['deleted'] * 86400 ) ) ) {
			return "Wiki {$this->dbname} can not be deleted yet.";
		}

		// @phan-suppress-next-line SecurityCheck-PathTraversal
		$data = $this->dataFactory->newInstance( $this->dbname );
		$data->deleteWikiData( $this->dbname );

		foreach ( $this->tables as $table => $selector ) {
			// @phan-suppress-next-line SecurityCheck-SQLInjection
			$this->cwdb->delete(
				$table,
				[ $selector => $this->dbname ]
			);
		}

		$this->recache();

		$this->hookRunner->onCreateWikiDeletion( $this->cwdb, $this->dbname );

		return null;
	}

	public function rename( string $newDatabaseName ): ?string {
		// NOTE: $this->dbname is the old database name

		$this->compileTables();

		$error = $this->checkDatabaseName( $newDatabaseName, true );

		if ( $error ) {
			return "Can not rename {$this->dbname} to {$newDatabaseName} because: {$error}";
		}

		foreach ( $this->tables as $table => $selector ) {
			// @phan-suppress-next-line SecurityCheck-SQLInjection
			$this->cwdb->update(
				$table,
				[ $selector => $newDatabaseName ],
				[ $selector => $this->dbname ]
			);
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
		bool $rename = false
	): ?string {
		$suffix = $this->config->get( 'CreateWikiDatabaseSuffix' );
		$suffixed = substr( $dbname, -strlen( $suffix ) ) === $suffix;
		if ( !$suffixed ) {
			return $this->messageLocalizer->msg(
				'createwiki-error-notsuffixed', $suffix
			)->parse();
		}

		if ( !$rename && $this->exists ) {
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

		$logDBConn = $this->connectionProvider->getPrimaryDatabase(
			$this->config->get( 'CreateWikiGlobalWiki' )
		);

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
		$data = $this->dataFactory->newInstance(
			$this->config->get( 'CreateWikiGlobalWiki' )
		);

		$data->resetDatabaseLists( isNewChanges: true );
	}
}
