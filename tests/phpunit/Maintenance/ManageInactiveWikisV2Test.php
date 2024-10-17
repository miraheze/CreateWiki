<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use Generator;
use MaintenanceBaseTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Maintenance\ManageInactiveWikisV2;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use ReflectionClass;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\ManageInactiveWikisV2
 */
class ManageInactiveWikisV2Test extends MaintenanceBaseTestCase {

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
		$this->setMwGlobals( $config );

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
		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( $dbName );

		$result = $this->invokeMethod(
			$this->maintenance,
			'checkLastActivity',
			[ $dbName, $remoteWiki ]
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
		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( $dbName );

		$output = $this->invokeMethod(
			$this->maintenance,
			'handleInactiveWiki',
			[ $dbName, $remoteWiki, 60, $canWrite ]
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
		$remoteWiki = $this->getServiceContainer()->get( 'RemoteWikiFactory' )
			->newInstance( $dbName );

		$output = $this->invokeMethod(
			$this->maintenance,
			'handleClosedWiki',
			[ $dbName, $remoteWiki, 90, $canWrite ]
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

		$output = $this->invokeMethod( $this->maintenance, 'notify', [ $dbName ] );
		$this->assertNull( $output );
	}

	/**
	 * Utility method to invoke protected/private methods.
	 *
	 * @param object $object
	 * @param string $methodName
	 * @param array $parameters
	 * @return mixed
	 */
	private function invokeMethod(
		object $object,
		string $methodName,
		array $parameters
	): mixed {
		$reflection = new ReflectionClass( $object );
		$method = $reflection->getMethod( $methodName );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $parameters );
	}
}
