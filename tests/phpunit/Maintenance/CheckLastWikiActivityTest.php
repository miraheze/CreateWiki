<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Title\Title;
use Miraheze\CreateWiki\Maintenance\CheckLastWikiActivity;
use Wikimedia\TestingAccessWrapper;
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
		$mockObject = $this->maintenance;
		'@phan-var TestingAccessWrapper $mockObject';
		$this->assertInstanceOf( CheckLastWikiActivity::class, $mockObject->object );
	}

	/**
	 * @covers ::execute
	 * @covers ::getTimestamp
	 */
	public function testExecuteWithRevisionOnly(): void {
		ConvertibleTimestamp::setFakeTime( (string)20250405060708 );
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
		ConvertibleTimestamp::setFakeTime( (string)20250505060708 );
		$editStatus = $this->editPage(
			Title::newFromText( 'TestPageLogging' ),
			'Initial revision'
		);

		$newRevision = $editStatus->getNewRevision();
		if ( $newRevision === null ) {
			$this->fail( 'Could not get new revision' );
			return;
		}

		ConvertibleTimestamp::setFakeTime( (string)20250505060710 );
		$this->deletePage(
			$this->getServiceContainer()->getWikiPageFactory()->newFromTitle(
				$newRevision->getPage()
			)
		);

		$this->maintenance->execute();
		$this->expectOutputRegex( '/^20250505060710$/' );
	}
}
