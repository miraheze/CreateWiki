<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

class WikiManager {
	private $dbname = null;
	private $dbw = null;
	private $cwdb = null;
	private $exists = null;
	private $tables = [];

	public function __construct( string $dbname ) {
		global $wgCreateWikiDatabase, $wgCreateWikiDatabaseClusters;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$check = $dbw->selectRow(
			'cw_wikis',
			'wiki_dbname',
			[
				'wiki_dbname' => $dbname
			],
			__METHOD__
		);

		if ( !$check && $wgCreateWikiDatabaseClusters ) {
			// DB doesn't exist and we have clusters
			$lbs = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->getAllMainLBs();

			foreach ( $wgCreateWikiDatabaseClusters as $cluster ) {
				$count = $dbw->selectRowCount(
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
			$newDbw = $lbs[$candidateArray[$rand]]->getConnection( DB_MASTER );

		} elseif ( !$check && !$wgCreateWikiDatabaseClusters ) {
			// DB doesn't exist and we don't have clusters
			$newDbw = $dbw;
		} else {
			// DB exists
			$newDbw = wfGetDB( DB_MASTER, [], $dbname );
		}

		$this->dbname = $dbname;
		$this->dbw = $newDbw;
		$this->cwdb = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );
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
		global $IP, $wgCreateWikiGlobalWiki, $wgCreateWikiSQLfiles;

		$wiki = $this->dbname;

		if ( $this->exists ) {
			throw new FatalError( "Wiki '{$wiki}' already exists." );
		}

		$checkErrors = $this->checkDatabaseName( $wiki );

		if ( $checkErrors ) {
			return $checkErrors;
		}

		try {
			$this->dbw->query( 'CREATE DATABASE ' . $this->dbw->addIdentifierQuotes( $wiki ) . ';' );
		} catch ( Exception $e ) {
			throw new FatalError( "Wiki '{$wiki}' already exists." );
		}

		$this->cwdb->insert(
			'cw_wikis',
			[
				'wiki_dbname' => $wiki,
				'wiki_sitename' => $siteName,
				'wiki_language' => $language,
				'wiki_private' => (int)$private,
				'wiki_creation' => $this->dbw->timestamp(),
				'wiki_category' => $category
			]
		);

		$this->recacheJson();

		$newDbw = wfGetDB( DB_MASTER, [], $wiki );

		foreach ( $wgCreateWikiSQLfiles as $sqlfile ) {
			$newDbw->sourceFile( $sqlfile );
		}

		Hooks::run( 'CreateWikiCreation', [ $wiki, $private ] );

		Shell::makeScriptCommand(
			"$IP/extensions/CreateWiki/maintenance/populateMainPage.php",
			[
				'--wiki', $wiki
			]
		)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();

		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			Shell::makeScriptCommand(
				"$IP/extensions/CentralAuth/maintenance/createLocalAccount.php",
				[
					$requester,
					'--wiki', $wiki
				]
			)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();
		}

		Shell::makeScriptCommand(
			"$IP/maintenance/createAndPromote.php",
			[
				$requester,
				'--bureaucrat',
				'--sysop',
				'--force',
				'--wiki', $wiki
			]
		)->limits( [ 'memory' => 0, 'filesize' => 0 ] )->execute();

		$this->notificationsTrigger( 'creation', $wiki, [ 'siteName' => $siteName ], $requester );

		$this->logEntry( 'farmer', 'createwiki', $actor, $reason, [ '4::wiki' => $wiki ] );

		return null;
	}

	public function delete( bool $force = false ) {
		global $wgCreateWikiStateDays;

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
		if ( ( !$deletedWiki || !$force ) && ( $unixNow - $unixDeletion ) < ( (int)$wgCreateWikiStateDays['deleted'] * 86400 ) ) {
			return "Wiki {$wiki} can not be deleted yet.";
		}

		foreach ( $this->tables as $table => $selector ) {
			$this->cwdb->delete(
				$table,
				[
					$selector => $wiki
				]
			);
		}

		$this->recacheJson();

		Hooks::run( 'CreateWikiDeletion', [ $this->cwdb, $wiki ] );

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

		$this->recacheJson();

		Hooks::run( 'CreateWikiRename', [ $this->cwdb, $old, $new ] );

		return null;
	}

	private function compileTables() {
		$cTables = [];

		Hooks::run( 'CreateWikiTables', [ &$cTables ] );

		$cTables['cw_wikis'] = 'wiki_dbname';

		$this->tables = $cTables;
	}

	public function checkDatabaseName( string $dbname, bool $rename = false ) {
		global $wgConf;

		$suffixed = false;
		foreach( $wgConf->suffixes as $suffix ) {
			if ( substr( $dbname, -strlen( $suffix ) ) === $suffix ) {
				$suffixed = true;
				break;
			}
		}

		$error = false;

		if ( !$suffixed ) {
			$error = 'notsuffixed';
		} elseif( !ctype_alnum( $dbname ) ) {
			$error = 'notalnum';
		} elseif ( strtolower( $dbname ) !== $dbname ) {
			$error = 'notlowercase';
		}

		return ( $error ) ? wfMessage( 'createwiki-error-' . $error )->escaped() : false;
	}

	private function logEntry( string $log, string $action, string $actor, string $reason, array $params, string $loggingWiki = null ) {
		global $wgCreateWikiGlobalWiki;

		$logDBConn = wfGetDB( DB_MASTER, [], $loggingWiki ?? $wgCreateWikiGlobalWiki );

		$logEntry = new ManualLogEntry( $log, $action );
		$logEntry->setPerformer( User::newFromName( $actor ) );
		$logEntry->setTarget( Title::newFromID( 1 ) );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );
		$logID = $logEntry->insert( $logDBConn );
		$logEntry->publish( $logID );
	}

	public function notificationsTrigger( string $type, string $wiki, array $specialData, $receivers ) {
		global $wgCreateWikiUseEchoNotifications, $wgCreateWikiEmailNotifications, $wgPasswordSender, $wgSitename, $wgCreateWikiNotificationEmail, $wgCreateWikiSubdomain;

		switch ( $type ) {
			case 'creation':
				$echoType = 'wiki-creation';
				$echoExtra = [
					'wiki-url' => 'https://' . substr( $wiki, 0, -4 ) . ".{$wgCreateWikiSubdomain}",
					'sitename' => $specialData['siteName'],
					'notifyAgent' => true
				];
				$notifyServerAdministrators = false;
				break;
			case 'rename':
				$echoType = 'wiki-rename';
				$echoExtra = [
					'wiki-url' => 'https://' . substr( $wiki, 0, -4 ) . ".{$wgCreateWikiSubdomain}",
					'sitename' => $specialData['siteName'],
					'notifyAgent' => true
				];
				$notifyServerAdministrators = false; // temp
				break;
			case 'request-declined':
				$echoType = 'request-declined';
				$echoExtra = [
					'request-url' => SpecialPage::getTitleFor( 'RequestWikiQueue', $specialData['id'] )->getFullURL(),
					'reason' => $specialData['reason'],
					'notifyAgent' => true
				];
				break;
			default:
				$echoType = false;
				break;
		}

		if ( $wgCreateWikiUseEchoNotifications && $echoType ) {
			foreach ( (array)$receivers as $receiver ) {
				EchoEvent::create(
					[
						'type' => $echoType,
						'extra' => $echoExtra,
						'agent' => User::newFromName( $receiver )
					]
				);
			}
		}

		if ( $wgCreateWikiEmailNotifications && $type == 'creation' ) {
			$notifyEmails = [];

			foreach ( (array)$receivers as $receiver ) {
				$notifyEmails[] = MailAddress::newFromUser( User::newFromName( $receiver ) );
			}

			if ( $notifyServerAdministrators ) {
				$notifyEmails[] = new MailAddress( $wgCreateWikiNotificationEmail, 'Server Administrators' );
			}

			$from = new MailAddress( $wgPasswordSender, 'CreateWiki on ' . $wgSitename );
			$subject = wfMessage( 'createwiki-email-subject', $specialData['siteName'] )->inContentLanguage()->text();
			$body = wfMessage( 'createwiki-email-body' )->inContentLanguage()->text();

			UserMailer::send( $notifyEmails, $from, $subject, $body );
		}
	}

	private function recacheJson( $wiki = null ) {
		global $wgCreateWikiGlobalWiki;

		$cWJ = new CreateWikiJson( $wiki ?? $wgCreateWikiGlobalWiki );
		$cWJ->resetDatabaseList();
		$cWJ->update();
	}
}
