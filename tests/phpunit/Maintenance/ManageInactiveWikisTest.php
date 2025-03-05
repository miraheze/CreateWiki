<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\Title;
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

		$this->overrideConfigValues( [
			ConfigNames::EnableManageInactiveWikis => true,
			ConfigNames::StateDays => [
				'inactive' => 10,
				'closed' => 5,
				'removed' => 7,
			],
			ConfigNames::UseClosedWikis => true,
			ConfigNames::UseInactiveWikis => true,
			MainConfigNames::VirtualDomainsMapping => [
				'virtual-createwiki' => [ 'db' => WikiMap::getCurrentWikiId() ],
			],
		] );
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
		$this->insertWikiRow( 'TestWikiActive' );

		// Set the fake time to now and simulate a recent edit on the wiki.
		$now = date( 'YmdHis' );
		ConvertibleTimestamp::setFakeTime( $now );
		$this->editPage(
			Title::newFromText( 'TestWikiActive/Main_Page' ),
			'Recent activity'
		);

		$this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( 'TestWikiActive' )
			->markInactive();

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^TestWikiActive has been marked as active\./' );
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 */
	public function testExecuteInactiveWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->insertWikiRow( 'TestWikiInactive' );

		// Simulate an old creation date by setting the fake time to an earlier date and making an initial edit.
		ConvertibleTimestamp::setFakeTime( '20200101000000' );
		$this->editPage(
			Title::newFromText( 'TestWikiInactive/Main_Page' ),
			'Initial content'
		);

		// Now simulate that the last activity occurred 15 days ago (beyond the inactive threshold of 10 days).
		$oldTime = date( 'YmdHis', strtotime( '-15 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );

		// Do not mark the wiki as inactive yet.
		// Enable write mode so that the script can update the wiki's state.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^TestWikiInactive was marked as inactive\. Last activity:/' );
	}

	/**
	 * @covers ::execute
	 * @covers ::checkLastActivity
	 * @covers ::handleInactiveWiki
	 */
	public function testExecuteClosedWiki(): void {
		// Enable the maintenance script.
		$this->overrideConfigValue( ConfigNames::EnableManageInactiveWikis, true );
		$this->insertWikiRow( 'TestWikiClosure' );

		// Set an old creation date.
		ConvertibleTimestamp::setFakeTime( '20200101000000' );
		$this->editPage(
			Title::newFromText( 'TestWikiClosure/Main_Page' ),
			'Initial content'
		);

		// Simulate an edit that happened 16 days ago, which is older than inactive (10 days)
		// plus closed (5 days) thresholds (i.e. older than 15 days).
		$oldTime = date( 'YmdHis', strtotime( '-16 days' ) );
		ConvertibleTimestamp::setFakeTime( $oldTime );

		// Mark the wiki as inactive so that it records an inactive timestamp.
		$remoteWiki = $this->getServiceContainer()
			->get( 'RemoteWikiFactory' )
			->newInstance( 'TestWikiClosure' );
		$remoteWiki->markInactive();

		// Return the fake time to now for evaluation.
		ConvertibleTimestamp::setFakeTime( date( 'YmdHis' ) );

		// Enable write mode.
		$this->maintenance->setOption( 'write', true );

		$this->maintenance->execute();
		$this->expectOutputRegex(
			'/^TestWikiClosure (has been closed|was marked as inactive on .* and is now closed)\./'
		);
	}

	protected function insertWikiRow( string $dbname ): void {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbw = $databaseUtils->getGlobalPrimaryDB();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->ignore()
			->row( [
				'wiki_dbname' => $dbname,
				'wiki_dbcluster' => 'c1',
				'wiki_sitename' => 'TestWiki',
				'wiki_language' => 'en',
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'uncategorised',
				'wiki_closed' => 0,
				'wiki_deleted' => 0,
				'wiki_locked' => 0,
				'wiki_inactive' => 0,
				'wiki_inactive_exempt' => 0,
				'wiki_url' => 'http://127.0.0.1:9412',
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
