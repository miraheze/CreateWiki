<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Maintenance\ManageInactiveWikis;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function date;
use function strtotime;
use function version_compare;
use const MW_INSTALL_PATH;
use const MW_VERSION;
use const NS_MAIN;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\ManageInactiveWikis
 */
class ManageInactiveWikisTest extends MaintenanceBaseTestCase {

	private RemoteWikiFactory $remoteWikiFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

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

		if ( $db === null ) {
			return;
		}

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `activetest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `inactivetest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `closuretest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `closureinactivetest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `closureinactiveineligibletest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `removaltest`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `removalineligibletest`.* TO 'wikiuser'@'localhost';" );
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
		$mockObject = $this->maintenance;
		'@phan-var TestingAccessWrapper $mockObject';
		$this->assertInstanceOf( ManageInactiveWikis::class, $mockObject->object );
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

		$remoteWiki = $this->remoteWikiFactory->newInstance( 'activetest' );
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
		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$this->insertRemoteLogging( 'inactivetest' );

		// Now simulate that the last activity occurred 14 days ago (beyond the inactive threshold of 10 days).
		$oldTime = date( 'YmdHis', strtotime( '-14 days' ) );
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
	 * @covers ::notifyBureaucrats
	 */
	public function testExecuteClosedWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'closuretest' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$this->insertRemoteLogging( 'closuretest' );

		// Simulate an edit that happened 16 days ago, which is older than inactive (10 days)
		// plus closed (5 days) thresholds (i.e. older than 15 days).
		$oldTime = date( 'YmdHis', strtotime( '-16 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'closuretest' );

		// Mark the wiki as inactive so that it records an inactive timestamp.
		$remoteWiki = $this->remoteWikiFactory->newInstance( 'closuretest' );
		$remoteWiki->markInactive();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^closuretest has been closed\. Last activity:/' );
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 * @covers ::handleInactiveWiki
	 * @covers ::notifyBureaucrats
	 */
	public function testExecuteClosedWikiAlreadyInactive(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'closureinactivetest' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$this->insertRemoteLogging( 'closureinactivetest' );

		// Now simulate that the last activity occurred 11 days ago (beyond the inactive threshold of 10 days).
		$oldTime = date( 'YmdHis', strtotime( '-11 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'closureinactivetest' );

		// Mark the wiki as inactive so that it records an inactive timestamp.
		// We wark it inactive 6 days ago (more than the closed threshold).
		$oldTime = date( 'YmdHis', strtotime( '-6 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );

		$remoteWiki = $this->remoteWikiFactory->newInstance( 'closureinactivetest' );
		$remoteWiki->markInactive();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^closureinactivetest was marked as inactive on .* and is now closed\. Last activity:/'
		);
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 * @covers ::handleInactiveWiki
	 */
	public function testExecuteClosedWikiAlreadyInactiveIneligible(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'closureinactiveineligibletest' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$this->insertRemoteLogging( 'closureinactiveineligibletest' );

		// Now simulate that the last activity occurred 11 days ago (beyond the inactive threshold of 10 days).
		$oldTime = date( 'YmdHis', strtotime( '-11 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'closureinactiveineligibletest' );

		// Mark the wiki as inactive so that it records an inactive timestamp.
		// We wark it inactive 4 days ago (less than the closed threshold).
		$oldTime = date( 'YmdHis', strtotime( '-4 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );

		$remoteWiki = $this->remoteWikiFactory->newInstance( 'closureinactiveineligibletest' );
		$remoteWiki->markInactive();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^closureinactiveineligibletest was marked as inactive on .* ' .
			'but is not yet eligible for closure\. Last activity:/'
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
		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$this->insertRemoteLogging( 'removaltest' );

		// Simulate an edit that happened 16 days ago, which is older than inactive (10 days)
		// plus closed (5 days) thresholds (i.e. older than 15 days).
		$oldTime = date( 'YmdHis', strtotime( '-16 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'removaltest' );

		// Mark the wiki as closed so that it records a closed timestamp.
		// This will also be 16 days ago which means closed timestamp
		// plus removal days is greater then 7 which is the removal threshold.
		$remoteWiki = $this->remoteWikiFactory->newInstance( 'removaltest' );
		$remoteWiki->markClosed();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^removaltest is eligible for removal and now has been\. It was closed on .*\. Last activity:/'
		);
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 * @covers ::handleClosedWiki
	 */
	public function testExecuteRemovedWikiIneligible(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->createWiki( 'removalineligibletest' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$this->insertRemoteLogging( 'removalineligibletest' );

		// Simulate an edit that happened 16 days ago, which is older than inactive (10 days)
		// plus closed (5 days) thresholds (i.e. older than 15 days).
		$oldTime = date( 'YmdHis', strtotime( '-16 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );
		$this->insertRemoteLogging( 'removalineligibletest' );

		// Mark the wiki as closed so that it records a closed timestamp.
		// We mark as closed 6 days ago (less then the removal threshold),
		// so it is not yet eligible for removal.
		$oldTime = date( 'YmdHis', strtotime( '-6 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );

		$remoteWiki = $this->remoteWikiFactory->newInstance( 'removalineligibletest' );
		$remoteWiki->markClosed();
		$remoteWiki->commit();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^removalineligibletest was closed on .* but is not yet eligible for deletion\. ' .
			'It may have been manually closed\. Last activity:/'
		);
	}

	private function createWiki( string $dbname ): void {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		ConvertibleTimestamp::setFakeTime( (string)20200101000000 );
		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
		'@phan-var WikiManagerFactory $wikiManagerFactory';

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
	}

	private function insertRemoteLogging( string $dbname ): void {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		'@phan-var CreateWikiDatabaseUtils $databaseUtils';
		$dbw = $databaseUtils->getRemoteWikiPrimaryDB( $dbname );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'logging' )
			->row( [
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
