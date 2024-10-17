<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use Generator;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Maintenance\ManageInactiveWikisV2;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\ManageInactiveWikisV2
 */
class ManageInactiveWikisV2Test extends MaintenanceBaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		$db = $this->getServiceContainer()->getDatabaseFactory()->create( 'mysql', [
			'host' => $GLOBALS['wgDBserver'],
			'user' => 'root',
		] );

		$db->begin();
		$db->query( "GRANT ALL PRIVILEGES ON `activeWikiDbName`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `closedWikiDbName`.* TO 'wikiuser'@'localhost';" );
		$db->query( "GRANT ALL PRIVILEGES ON `inactiveWikiDbName`.* TO 'wikiuser'@'localhost';" );
		$db->query( "FLUSH PRIVILEGES;" );
		$db->commit();
	}

	public function addDBDataOnce(): void {
		$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_wikis' )
			->ignore()
			->row( [
				'wiki_dbname' => 'wikidb',
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

	protected function getMaintenanceClass(): string {
		return ManageInactiveWikisV2::class;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct(): void {
		$this->assertInstanceOf( ManageInactiveWikisV2::class, $this->maintenance );
	}

	/**
	 * @covers ::execute
	 * @dataProvider provideExecuteData
	 */
	public function testExecute( bool $writeOption, array $config ): void {
		$this->overrideConfigValues( $config );

		if ( $writeOption ) {
			$this->maintenance->setOption( 'write', true );
		}

		$this->assertNull( $this->maintenance->execute() );
	}

	/**
	 * Data provider for testExecute.
	 *
	 * @return Generator
	 */
	public function provideExecuteData(): Generator {
		yield 'with write option' => [
			true,
			[
				ConfigNames::EnableManageInactiveWikis => true,
				ConfigNames::StateDays => [
					'inactive' => 30,
					'closed' => 60,
					'removed' => 90,
				],
			],
		];

		yield 'without write option' => [
			false,
			[
				ConfigNames::EnableManageInactiveWikis => true,
				ConfigNames::StateDays => [
					'inactive' => 30,
					'closed' => 60,
					'removed' => 90,
				],
			],
		];
	}

	/**
	 * @covers ::checkLastActivity
	 * @dataProvider provideCheckLastActivityData
	 */
	public function testCheckLastActivity( string $dbName, bool $expectedResult ): void {
		$this->createWiki( $dbName );
		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( $dbName );

		$result = $this->maintenance->checkLastActivity(
			$dbName, $remoteWiki
		);

		$this->assertSame( $expectedResult, $result );
	}

	/**
	 * Data provider for testCheckLastActivity.
	 *
	 * @return Generator
	 */
	public function provideCheckLastActivityData(): Generator {
		yield 'active wiki' => [ 'activeWikiDbName', true ];
		yield 'inactive wiki' => [ 'inactiveWikiDbName', false ];
	}

	/**
	 * @covers ::handleInactiveWiki
	 * @dataProvider provideHandleInactiveWikiData
	 */
	public function testHandleInactiveWiki( string $dbName, bool $canWrite ): void {
		$this->createWiki( $dbName );
		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( $dbName );

		$output = $this->maintenance->handleInactiveWiki(
			$dbName, $remoteWiki, 60, $canWrite
		);

		$this->assertNull( $output );
	}

	/**
	 * Data provider for testHandleInactiveWiki.
	 *
	 * @return Generator
	 */
	public function provideHandleInactiveWikiData(): Generator {
		yield 'can write' => [ 'inactiveWikiDbName', true ];
		yield 'cannot write' => [ 'inactiveWikiDbName', false ];
	}

	/**
	 * @covers ::handleClosedWiki
	 * @dataProvider provideHandleClosedWikiData
	 */
	public function testHandleClosedWiki( string $dbName, bool $canWrite ): void {
		$this->createWiki( $dbName );
		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( $dbName );

		$output = $this->maintenance->handleClosedWiki(
			$dbName, $remoteWiki, 90, $canWrite
		);

		$this->assertNull( $output );
	}

	/**
	 * Data provider for testHandleClosedWiki.
	 *
	 * @return Generator
	 */
	public function provideHandleClosedWikiData(): Generator {
		yield 'can write' => [ 'closedWikiDbName', true ];
		yield 'cannot write' => [ 'closedWikiDbName', false ];
	}

	/**
	 * @covers ::notify
	 */
	public function testNotify(): void {
		$dbName = 'testWikiDbName';

		$notificationManagerMock = $this->createMock( CreateWikiNotificationsManager::class );
		$notificationManagerMock->expects( $this->once() )
			->method( 'notifyBureaucrats' )
			->with(
				$this->arrayHasKey( 'type' ),
				$dbName
			);

		$serviceContainer = $this->getServiceContainer();
		$serviceContainer->redefineService(
			'CreateWiki.NotificationsManager',
			static function () use ( $notificationManagerMock ) {
				return $notificationManagerMock;
			}
		);

		$output = $this->maintenance->notify( $dbName );
		$this->assertNull( $output );
	}

	/**
	 * @param string $dbname
	 */
	private function createWiki( string $dbname ): void {
		$testUser = $this->getTestUser()->getUser();
		$testSysop = $this->getTestSysop()->getUser();

		$wikiManager = $this->getServiceContainer()->get( 'WikiManagerFactory' )
			->newInstance( $dbname );
		if ( $wikiManager->exists() ) {
			return;
		}
		$wikiManager->create(
			'TestWiki', 'en', false, 'uncategorised',
			$testUser->getName(), $testSysop->getName(),
			'Test', []
		);
	}
}
