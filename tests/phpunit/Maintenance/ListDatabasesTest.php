<?php

namespace Miraheze\CreateWiki\Tests\Maintenance;

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Miraheze\CreateWiki\Maintenance\ListDatabases;

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
		$this->assertInstanceOf( ListDatabases::class, $this->maintenance->object );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute(): void {
		$this->maintenance->execute();
		$this->expectOutputRegex( '/^wikidb$/' );
	}
}
