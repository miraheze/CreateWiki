<?php

namespace Miraheze\CreateWiki\Tests\Unit\Jobs;

use MediaWikiUnitTestCase;
use Miraheze\CreateWiki\Jobs\CacheUpdateJob;
use Miraheze\CreateWiki\Services\CacheUpdate;

/**
 * @group CreateWiki
 * @coversDefaultClass \Miraheze\CreateWiki\Jobs\CacheUpdateJob
 */
class CacheUpdateJobTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 * @covers ::run
	 */
	public function testRunWithData(): void {
		$cacheUpdate = $this->createMock( CacheUpdate::class );
		$cacheUpdate->expects( $this->once() )
			->method( 'executeNow' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'databases', '{"mtime":1}' )
			->willReturn( true );

		$job = new CacheUpdateJob(
			[ 'name' => 'databases', 'data' => '{"mtime":1}' ],
			$cacheUpdate
		);

		$this->assertTrue( $job->run() );
	}

	/**
	 * @covers ::__construct
	 * @covers ::run
	 */
	public function testRunWithoutData(): void {
		$cacheUpdate = $this->createMock( CacheUpdate::class );
		$cacheUpdate->expects( $this->once() )
			->method( 'executeNow' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'databases', null )
			->willReturn( false );

		$job = new CacheUpdateJob( [ 'name' => 'databases' ], $cacheUpdate );
		$this->assertFalse( $job->run() );
	}
}
