<?php

namespace Miraheze\CreateWiki;

use Exception;
use ExtensionRegistry;
use FatalError;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class WikiManager {

	private CreateWikiDataFactory $dataFactory;
	private CreateWikiHookRunner $hookRunner;

	private Config $config;
	private IConnectionProvider $connectionProvider;
	private IDatabase $dbw;
	private IDatabase $cwdb;

	private ?ILoadBalancer $lb = null;

	private string $dbname;
	private array $tables = [];

	public bool $exists;
	public ?string $cluster = null;

	public function __construct( string $dbname, CreateWikiHookRunner $hookRunner ) {
		$services = MediaWikiServices::getInstance();
		$this->config = $services->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->connectionProvider = $services->getConnectionProvider();
		$this->dataFactory = $services->get( 'CreateWikiDataFactory' );

		$this->hookRunner = $hookRunner;

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
				$lbs = $this->connectionProvider->getAllMainLBs();

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
				$newDbw = $this->lb->getPrimaryDatabase( $clusterDB );
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

	public function doCreateDatabase() {
		$wiki = $this->dbname;

		if ( $this->exists ) {
			throw new FatalError( "Wiki '{$wiki}' already exists." );
		}

		$checkErrors = $this->checkDatabaseName( $wiki );

		if ( $checkErrors ) {
			return $checkErrors;
		}

		try {
			$dbCollation = $this->config->get( 'CreateWikiCollation' );
			$dbQuotes = $this->dbw->addIdentifierQuotes( $wiki );
			$this->dbw->query( "CREATE DATABASE {$dbQuotes} {$dbCollation};" );
		} catch ( Exception $e ) {
			throw new FatalError( "Wiki '{$wiki}' already exists." );
		}

		if ( $this->lb ) {
			$this->dbw = $this->lb->getConnection( DB_PRIMARY, [], $wiki );
		} else {
			$this->dbw = $this->lbFactory->getMainLB( $wiki )
				->getMaintenanceConnectionRef( DB_PRIMARY, [], $wiki );
		}
	}

	public function create(
		string $siteName,
		string $language,
		bool $private,
		string $category,
		string $requester,
		string $actor,
		string $reason
	) {
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
				'wiki_category' => $category
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
	) {
		$wiki = $this->dbname;

		foreach ( $this->config->get( 'CreateWikiSQLfiles' ) as $sqlfile ) {
			$this->dbw->sourceFile( $sqlfile );
		}

		$this->hookRunner->onCreateWikiCreation( $wiki, $private );

		DeferredUpdates::addCallableUpdate(
			function () use ( $wiki, $requester, $centralAuth ) {
				$this->recache();

				$scriptOptions = [];
				if ( version_compare( MW_VERSION, '1.40', '>=' ) ) {
					$scriptOptions = [ 'wrapper' => MW_INSTALL_PATH . '/maintenance/run.php' ];
				}

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/setContainersAccess.php',
					[
						'--wiki', $wiki
					],
					$scriptOptions
				)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/populateMainPage.php',
					[
						'--wiki', $wiki
					],
					$scriptOptions
				)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();

				if ( $centralAuth ) {
					if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
						Shell::makeScriptCommand(
							MW_INSTALL_PATH . '/extensions/CentralAuth/maintenance/createLocalAccount.php',
							[
								$requester,
								'--wiki', $wiki
							],
							$scriptOptions
						)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();
					}

					Shell::makeScriptCommand(
						MW_INSTALL_PATH . '/maintenance/createAndPromote.php',
						[
							$requester,
							'--bureaucrat',
							'--interface-admin',
							'--sysop',
							'--force',
							'--wiki', $wiki
						],
						$scriptOptions
					)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();
				}
			},
			DeferredUpdates::POSTSEND,
			$this->cwdb
		);

		if ( $notify ) {
			$notificationData = [
				'type' => 'wiki-creation',
				'extra' => [
					'wiki-url' => 'https://' . substr( $wiki, 0, -strlen( $this->config->get( 'CreateWikiDatabaseSuffix' ) ) ) . ".{$this->config->get( 'CreateWikiSubdomain' )}",
					'sitename' => $siteName,
				],
				'subject' => wfMessage( 'createwiki-email-subject', $siteName )->inContentLanguage()->text(),
				'body' => [
					'html' => nl2br( wfMessage( 'createwiki-email-body' )->inContentLanguage()->text() ),
					'text' => wfMessage( 'createwiki-email-body' )->inContentLanguage()->text(),
				],
			];

			MediaWikiServices::getInstance()->get( 'CreateWiki.NotificationsManager' )
				->sendNotification( $notificationData, [ $requester ] );

			$this->logEntry( 'farmer', 'createwiki', $actor, $reason, [ '4::wiki' => $wiki ] );
		}
	}

	public function delete( bool $force = false ) {
		$this->compileTables();

		$wiki = $this->dbname;

		$row = $this->cwdb->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $wiki
			]
		);

		$deletionDate = $row->wiki_deleted_timestamp;
		$unixDeletion = (int)wfTimestamp( TS_UNIX, $deletionDate );
		$unixNow = (int)wfTimestamp( TS_UNIX, $this->dbw->timestamp() );

		$deletedWiki = (bool)$row->wiki_deleted && (bool)$row->wiki_deleted_timestamp;

		// Return error if: wiki is not deleted, force is not used & wiki
		if ( !$force && ( !$deletedWiki || ( $unixNow - $unixDeletion ) < ( (int)$this->config->get( 'CreateWikiStateDays' )['deleted'] * 86400 ) ) ) {
			return "Wiki {$wiki} can not be deleted yet.";
		}

		// @phan-suppress-next-line SecurityCheck-PathTraversal
		$data = $this->dataFactory->newInstance( $wiki );
		$data->deleteWikiData( $wiki );

		foreach ( $this->tables as $table => $selector ) {
			// @phan-suppress-next-line SecurityCheck-SQLInjection
			$this->cwdb->delete(
				$table,
				[
					$selector => $wiki
				]
			);
		}

		$this->recache();

		$this->hookRunner->onCreateWikiDeletion( $this->cwdb, $wiki );

		return null;
	}

	public function rename( string $new ) {
		$this->compileTables();

		$old = $this->dbname;

		$error = $this->checkDatabaseName( $new, true );

		if ( $error ) {
			return "Can not rename {$old} to {$new} because: {$error}";
		}

		foreach ( (array)$this->tables as $table => $selector ) {
			// @phan-suppress-next-line SecurityCheck-SQLInjection
			$this->cwdb->update(
				$table,
				[
					$selector => $new
				],
				[
					$selector => $old
				]
			);
		}

		/**
		 * Since the wiki at $new likely won't be cached yet, this will also
		 * run resetWikiData() on it since it has no mtime, so that it will
		 * generate the new cache file for it as well.
		 */
		$data = $this->dataFactory->newInstance( $new );
		$data->deleteWikiData( $old );

		$this->recache();

		$this->hookRunner->onCreateWikiRename( $this->cwdb, $old, $new );

		return null;
	}

	private function compileTables() {
		$cTables = [];

		$this->hookRunner->onCreateWikiTables( $cTables );

		$cTables['cw_wikis'] = 'wiki_dbname';

		$this->tables = $cTables;
	}

	public function checkDatabaseName( string $dbname, bool $rename = false ) {
		$suffixed = false;
		foreach ( $this->config->get( 'Conf' )->suffixes as $suffix ) {
			if ( substr( $dbname, -strlen( $suffix ) ) === $suffix ) {
				$suffixed = true;
				break;
			}
		}

		$error = false;

		if ( !$suffixed ) {
			$error = 'notsuffixed';
		} elseif ( !$rename && $this->exists ) {
			$error = 'dbexists';
		} elseif ( !ctype_alnum( $dbname ) ) {
			$error = 'notalnum';
		} elseif ( strtolower( $dbname ) !== $dbname ) {
			$error = 'notlowercase';
		}

		return ( $error ) ? wfMessage( 'createwiki-error-' . $error )->parse() : false;
	}

	private function logEntry( string $log, string $action, string $actor, string $reason, array $params ) {
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$user = $userFactory->newFromName( $actor );

		if ( !$user ) {
			return;
		}

		$logDBConn = $this->lbFactory->getMainLB( $this->config->get( 'CreateWikiGlobalWiki' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $this->config->get( 'CreateWikiGlobalWiki' ) );

		$logEntry = new ManualLogEntry( $log, $action );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'CreateWiki' ) );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );
		$logID = $logEntry->insert( $logDBConn );
		$logEntry->publish( $logID );
	}

	private function recache() {
		$data = $this->dataFactory->newInstance(
			$this->config->get( 'CreateWikiGlobalWiki' )
		);

		$data->resetDatabaseLists( isNewChanges: true );
	}
}
