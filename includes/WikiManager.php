<?php

namespace Miraheze\CreateWiki;

use DeferredUpdates;
use Exception;
use ExtensionRegistry;
use FatalError;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Title;

class WikiManager {
	private $config;
	private $lbFactory;
	private $cluster;
	private $dbname;
	private $dbw;
	private $cwdb;
	private $lb = false;
	private $tables = [];
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public $exists;

	public function __construct( string $dbname, CreateWikiHookRunner $hookRunner = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner ?? MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );
		$this->lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$this->cwdb = $this->lbFactory->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $this->config->get( 'CreateWikiDatabase' ) );

		$check = $this->cwdb->selectRow(
			'cw_wikis',
			'wiki_dbname',
			[
				'wiki_dbname' => $dbname
			],
			__METHOD__
		);

		if ( !$check && $this->config->get( 'CreateWikiDatabaseClusters' ) ) {
			// DB doesn't exist and we have clusters
			$lbs = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getAllMainLBs();

			$clusterSize = [];
			foreach ( $this->config->get( 'CreateWikiDatabaseClusters' ) as $cluster ) {
				$count = $this->cwdb->selectRowCount(
					'cw_wikis',
					'*',
					[
						'wiki_dbcluster' => $cluster
					]
				);

				$clusterSize[$cluster] = $count;
			}

			$candidateArray = array_keys( $clusterSize, min( $clusterSize ) );
			$rand = rand( 0, count( $candidateArray ) - 1 );
			$this->cluster = $candidateArray[$rand];
			$clusterDB = $this->cwdb->selectRow(
				'cw_wikis',
				'wiki_dbname',
				[
					'wiki_dbcluster' => $this->cluster
				]
			)->wiki_dbname;
			$this->lb = $lbs[$this->cluster];
			$newDbw = $lbs[$this->cluster]->getConnection( DB_PRIMARY, [], $clusterDB );

		} elseif ( !$check && !$this->config->get( 'CreateWikiDatabaseClusters' ) ) {
			// DB doesn't exist and we don't have clusters
			$newDbw = $this->cwdb;
		} else {
			// DB exists
			$newDbw = $this->lbFactory->getMainLB( $dbname )
				->getMaintenanceConnectionRef( DB_PRIMARY, [], $dbname );
		}

		$this->dbname = $dbname;
		$this->dbw = $newDbw;
		$this->exists = (bool)$check;
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

		$this->cwdb->insert(
			'cw_wikis',
			[
				'wiki_dbname' => $wiki,
				'wiki_dbcluster' => $this->cluster,
				'wiki_sitename' => $siteName,
				'wiki_language' => $language,
				'wiki_private' => (int)$private,
				'wiki_creation' => $this->dbw->timestamp(),
				'wiki_category' => $category
			]
		);

		foreach ( $this->config->get( 'CreateWikiSQLfiles' ) as $sqlfile ) {
			$this->dbw->sourceFile( $sqlfile );
		}

		$this->hookRunner->onCreateWikiCreation( $wiki, $private );

		DeferredUpdates::addCallableUpdate(
			function () use ( $wiki, $requester ) {
				$this->recacheJson();

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/setContainersAccess.php',
					[
						'--wiki', $wiki
					]
				)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/populateMainPage.php',
					[
						'--wiki', $wiki
					]
				)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();

				if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
					Shell::makeScriptCommand(
						MW_INSTALL_PATH . '/extensions/CentralAuth/maintenance/createLocalAccount.php',
						[
							$requester,
							'--wiki', $wiki
						]
					)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();
				}

				Shell::makeScriptCommand(
					MW_INSTALL_PATH . '/maintenance/createAndPromote.php',
					[
						$requester,
						'--bureaucrat',
						'--sysop',
						'--force',
						'--wiki', $wiki
					]
				)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();
			},
			DeferredUpdates::POSTSEND,
			$this->cwdb
		);

		$notificationData = [
			'type' => 'wiki-creation',
			'extra' => [
				'wiki-url' => 'https://' . substr( $wiki, 0, -4 ) . ".{$this->config->get( 'CreateWikiSubdomain' )}",
				'sitename' => $siteName,
			],
			'subject' => wfMessage( 'createwiki-email-subject', $siteName )->inContentLanguage()->text(),
			'body' => [
				'html' => wfMessage( 'createwiki-email-body' )->inContentLanguage()->text(),
				'text' => wfMessage( 'createwiki-email-body' )->inContentLanguage()->text(),
			],
		];

		MediaWikiServices::getInstance()->get( 'CreateWiki.NotificationsManager' )
			->sendNotification( $notificationData, [ $requester ] );

		$this->logEntry( 'farmer', 'createwiki', $actor, $reason, [ '4::wiki' => $wiki ] );

		return null;
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

		$deletedWiki = (bool)$row->wiki_deleted;

		// Return error if: wiki is not deleted, force is not used & wiki
		if ( !$force && ( !$deletedWiki || ( $unixNow - $unixDeletion ) < ( (int)$this->config->get( 'CreateWikiStateDays' )['deleted'] * 86400 ) ) ) {
			return "Wiki {$wiki} can not be deleted yet.";
		}

		foreach ( $this->tables as $table => $selector ) {
			// @phan-suppress-next-line SecurityCheck-SQLInjection
			$this->cwdb->delete(
				$table,
				[
					$selector => $wiki
				]
			);
		}

		// @phan-suppress-next-line SecurityCheck-PathTraversal
		$cWJ = new CreateWikiJson( $wiki, $this->hookRunner );

		$cWJ->resetWiki();

		$this->recacheJson();

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

		// @phan-suppress-next-line SecurityCheck-PathTraversal
		$cWJ = new CreateWikiJson( $old, $this->hookRunner );

		$cWJ->resetWiki();

		$this->recacheJson();

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

		$error = false;

		if ( !$rename && $this->exists ) {
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
		$logEntry->setTarget( Title::newMainPage() );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );
		$logID = $logEntry->insert( $logDBConn );
		$logEntry->publish( $logID );
	}

	private function recacheJson( $wiki = null ) {
		$cWJ = new CreateWikiJson( $wiki ?? $this->config->get( 'CreateWikiGlobalWiki' ), $this->hookRunner );
		$cWJ->resetDatabaseList();
		$cWJ->update();
	}
}
