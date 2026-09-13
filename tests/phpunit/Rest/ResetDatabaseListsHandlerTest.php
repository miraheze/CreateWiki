<?php

namespace Miraheze\CreateWiki\Tests\Rest;

use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Rest\CacheRestUtils;
use Miraheze\CreateWiki\Rest\ResetDatabaseListsHandler;
use Miraheze\CreateWiki\Services\CreateWikiDataStore;
use function json_encode;

/**
 * @group CreateWiki
 * @coversDefaultClass \Miraheze\CreateWiki\Rest\ResetDatabaseListsHandler
 */
class ResetDatabaseListsHandlerTest extends MediaWikiIntegrationTestCase {

	use HandlerTestTrait;

	private function newRequest( array $body ): RequestData {
		return new RequestData( [
			'method' => 'POST',
			'bodyContents' => json_encode( $body ),
			'headers' => [ 'Content-Type' => 'application/json' ],
		] );
	}

	/**
	 * @covers ::run
	 * @covers ::getBodyParamSettings
	 */
	public function testDisabledReturns404(): void {
		$restUtils = $this->createMock( CacheRestUtils::class );
		$restUtils->method( 'isRestEnabled' )->willReturn( false );

		$dataStore = $this->createMock( CreateWikiDataStore::class );
		$handler = new ResetDatabaseListsHandler( $restUtils, $dataStore );

		$response = $this->executeHandler(
			$handler,
			$this->newRequest( [ 'key' => 'x', 'name' => 'databases' ] )
		);

		$this->assertSame( 404, $response->getStatusCode() );
	}

	/**
	 * @covers ::run
	 */
	public function testThrottledReturns429(): void {
		$restUtils = $this->createMock( CacheRestUtils::class );
		$restUtils->method( 'isRestEnabled' )->willReturn( true );
		$restUtils->method( 'isThrottled' )->willReturn( true );

		$dataStore = $this->createMock( CreateWikiDataStore::class );
		$handler = new ResetDatabaseListsHandler( $restUtils, $dataStore );

		$response = $this->executeHandler(
			$handler,
			$this->newRequest( [ 'key' => 'x', 'name' => 'databases' ] )
		);

		$this->assertSame( 429, $response->getStatusCode() );
	}

	/**
	 * @covers ::run
	 */
	public function testInvalidKeyReturns403(): void {
		$restUtils = $this->createMock( CacheRestUtils::class );
		$restUtils->method( 'isRestEnabled' )->willReturn( true );
		$restUtils->method( 'isThrottled' )->willReturn( false );
		$restUtils->method( 'isValidKey' )->willReturn( false );
		$restUtils->expects( $this->once() )->method( 'recordFailure' );

		$dataStore = $this->createMock( CreateWikiDataStore::class );
		$handler = new ResetDatabaseListsHandler( $restUtils, $dataStore );

		$response = $this->executeHandler(
			$handler,
			$this->newRequest( [ 'key' => 'wrong', 'name' => 'databases' ] )
		);

		$this->assertSame( 403, $response->getStatusCode() );
	}

	/**
	 * @covers ::run
	 */
	public function testValidPayloadAppliesDatabaseList(): void {
		$restUtils = $this->createMock( CacheRestUtils::class );
		$restUtils->method( 'isRestEnabled' )->willReturn( true );
		$restUtils->method( 'isThrottled' )->willReturn( false );
		$restUtils->method( 'isValidKey' )->willReturn( true );

		$list = [ 'mtime' => 1, 'databases' => [] ];

		$dataStore = $this->createMock( CreateWikiDataStore::class );
		$dataStore->expects( $this->once() )
			->method( 'applyDatabaseList' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'databases', $list );
		$dataStore->expects( $this->never() )->method( 'resetDatabaseLists' );

		$handler = new ResetDatabaseListsHandler( $restUtils, $dataStore );

		$response = $this->executeHandler(
			$handler,
			$this->newRequest( [
				'key' => 'correct',
				'name' => 'databases',
				'data' => json_encode( $list ),
			] )
		);

		$this->assertSame( 204, $response->getStatusCode() );
	}

	/**
	 * @covers ::run
	 */
	public function testNoPayloadFallsBackToDatabaseQuery(): void {
		$restUtils = $this->createMock( CacheRestUtils::class );
		$restUtils->method( 'isRestEnabled' )->willReturn( true );
		$restUtils->method( 'isThrottled' )->willReturn( false );
		$restUtils->method( 'isValidKey' )->willReturn( true );

		$dataStore = $this->createMock( CreateWikiDataStore::class );
		$dataStore->expects( $this->never() )->method( 'applyDatabaseList' );
		$dataStore->expects( $this->once() )
			->method( 'resetDatabaseLists' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( false, false )
			->willReturn( true );

		$handler = new ResetDatabaseListsHandler( $restUtils, $dataStore );

		$response = $this->executeHandler(
			$handler,
			$this->newRequest( [ 'key' => 'correct', 'name' => 'databases' ] )
		);

		$this->assertSame( 204, $response->getStatusCode() );
	}
}
