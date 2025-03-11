<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Maintenance\ManageInactiveWikis;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\ManageInactiveWikis
 */
class ManageInactiveWikisTest extends MaintenanceBaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		$sqlPath = '/maintenance/tables-generated.sql';
		if ( version_compare( MW_VERSION, '1.44', '>=' ) ) {
			$sqlPath = '/sql/mysql/tables-generated.sql';
		}

		$this->overrideConfigValues( [
			ConfigNames::DatabaseSuffix => 'test',
			ConfigNames::EnableManageInactiveWikis => true,
			ConfigNames::StateDays => [
				'inactive' => 10,
				'closed' => 5,
				'removed' => 7,
			],
			ConfigNames::SQLFiles => [
				MW_INSTALL_PATH . $sqlPath,
			],
			ConfigNames::UseClosedWikis => true,
			ConfigNames::UseInactiveWikis => true,
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-createwiki' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
		] );

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $this->getConfVar( MainConfigNames::DBserver ),
			'user' => 'root',
		] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `activetest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `inactivetest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `closuretest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `removaltest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	protected function getMaintenanceClass(): string {
		return ManageInactiveWikis::class;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( ManageInactiveWikis::class, $this->maintenance->object );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteDisabled(): void {
		// Disable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, false );

		$this->expectCallToFatalError();
		$this->expectOutputRegex(
			'/Enable \$wgCreateWikiEnableManageInactiveWikis to run this script\./'
		);

		$this->maintenance->execute();
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 */
	public function testExecuteActiveWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'activetest' );

		// Set the fake time to now and simulate a recent edit on the wiki.
		$now = date( 'YmdHis' );
		ConvertibleTimestamp::setFakeTime( $now );
		$this->insertRemoteLogging( 'activetest' );

		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$remoteWiki = $remoteWikiFactory->newInstance( 'activetest' );

		$remoteWiki->markInactive();
		$remoteWiki->commit();

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^activetest has been marked as active\./' );
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 */
	public function testExecuteInactiveWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'inactivetest' );

		// Simulate an old creation date by setting the fake time to an earlier date and making an initial edit.
		ConvertibleTimestamp::setFakeTime( '20200101000000' );
		$this->insertRemoteLogging( 'inactivetest' );

		// Now simulate that the last activity occurred 15 days ago (beyond the inactive threshold of 10 days).
		$oldTime = date( 'YmdHis', strtotime( '-15 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'inactivetest' );

		// Do not mark the wiki as inactive yet.
		// Enable write mode so that the script can update the wiki's state.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^inactivetest was marked as inactive\. Last activity:/' );
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 * @covers ::handleInactiveWiki
	 * @covers ::notifyBureaucrats
	 */
	public function testExecuteClosedWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'closuretest' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( '20200101000000' );
		$this->insertRemoteLogging( 'closuretest' );

		// Simulate an edit that happened 16 days ago, which is older than inactive (10 days)
		// plus closed (5 days) thresholds (i.e. older than 15 days).
		$oldTime = date( 'YmdHis', strtotime( '-16 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'closuretest' );

		// Mark the wiki as inactive so that it records an inactive timestamp.
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$remoteWiki = $remoteWikiFactory->newInstance( 'closuretest' );

		$remoteWiki->markInactive();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^closuretest (has been closed|was marked as inactive on .* and is now closed)\./'
		);
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 * @covers ::handleClosedWiki
	 */
	public function testExecuteRemovedWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'removaltest' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( '20200101000000' );
		$this->insertRemoteLogging( 'removaltest' );

		// Simulate an edit that happened 16 days ago, which is older than inactive (10 days)
		// plus closed (5 days) thresholds (i.e. older than 15 days).
		$oldTime = date( 'YmdHis', strtotime( '-16 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'removaltest' );

		// Mark the wiki as closed so that it records a closed timestamp.
		// This will also be 16 days ago which means closed timestamp
		// plus removal days is greater then 7 which is the removal threshold.
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$remoteWiki = $remoteWikiFactory->newInstance( 'removaltest' );

		$remoteWiki->markClosed();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^closuretest (has been closed|was marked as inactive on .* and is now closed)\./'
		);
	}

	private function createWiki( string $dbname ): void {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		ConvertibleTimestamp::setFakeTime( '20200101000000' );
		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
		$wikiManager = $wikiManagerFactory->newInstance( $dbname );

		$wikiManager->create(
			sitename: 'TestWiki',
			language: 'en',
			private: false,
			category: 'uncategorised',
			requester: $testUser->getName(),
			actor: $testSysop->getName(),
			reason: 'Test',
			extra: []
		);

		/* $databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbw = $databaseUtils->getGlobalPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'cw_wikis' )
			->set( [ 'wiki_creation' => '20200101000000' ] )
			->where( [ 'wiki_dbname' => $dbname ] )
			->caller( __METHOD__ )
			->execute(); */

		// $this->db->selectDomain( $dbname );
	}

	private function insertRemoteLogging( string $dbname ): void {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbw = $databaseUtils->getRemoteWikiPrimaryDB( $dbname );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'logging' )
			->rows( [
				'log_type' => 'test',
				'log_action' => 'test',
				'log_actor' => $this->getTestUser()->getUser()->getActorId(),
				'log_params' => 'test',
				'log_timestamp' => $dbw->timestamp(),
				'log_namespace' => NS_MAIN,
				'log_title' => 'Test',
				'log_comment_id' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
