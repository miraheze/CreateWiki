<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\Title;
use Miraheze\CreateWiki\Maintenance\CheckLastWikiActivity;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\CheckLastWikiActivity
 */
class CheckLastWikiActivityTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass(): string {
		return CheckLastWikiActivity::class;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( CheckLastWikiActivity::class, $this->maintenance->object );
	}

	/**
	 * @covers ::execute
	 * @covers ::getTimestamp
	 */
	public function testExecuteWithRevisionOnly(): void {
		ConvertibleTimestamp::setFakeTime( '20250405060708' );
		$this->editPage(
			Title::newFromText( 'TestPageRevisionOnly' ),
			'Initial revision'
		);

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^20250405060708$/' );
	}

	/**
	 * @covers ::execute
	 * @covers ::getTimestamp
	 */
	public function testExecuteWithLoggingEventLater(): void {
		ConvertibleTimestamp::setFakeTime( '20250505060708' );
		$editStatus = $this->editPage(
			Title::newFromText( 'TestPageLogging' ),
			'Initial revision'
		);

		ConvertibleTimestamp::setFakeTime( '20250505060710' );
		$this->deletePage(
			$this->getServiceContainer()->getWikiPageFactory()->newFromTitle(
				$editStatus->getNewRevision()->getPage()
			)
		);

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^20250505060710$/' );
	}
}
