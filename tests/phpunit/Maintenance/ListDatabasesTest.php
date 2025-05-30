<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Miraheze\CreateWiki\Maintenance\ListDatabases;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CreateWiki
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Maintenance\ListDatabases
 */
class ListDatabasesTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass(): string {
		return ListDatabases::class;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$mockObject = $this->maintenance;
		'@phan-var TestingAccessWrapper $mockObject';
		$this->assertInstanceOf( ListDatabases::class, $mockObject->object );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute(): void {
		$this->maintenance->execute();
		$this->expectOutputRegex( '/wikidb$/' );
	}
}
