<?php

namespace Miraheze\CreateWiki\Tests\Services;

use Generator;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWikiIntegrationTestCase;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiValidator;

/**
 * @group CreateWiki
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\CreateWikiValidator
 */
class CreateWikiValidatorTest extends MediaWikiIntegrationTestCase {

	private readonly CreateWikiValidator $validator;
	private readonly MessageLocalizer $messageLocalizer;

	protected function setUp(): void {
		parent::setUp();

		$this->messageLocalizer = $this->createMock( MessageLocalizer::class );

		$options = $this->createMock( ServiceOptions::class );
		$options->method( 'get' )->willReturnCallback( static function ( string $key ): mixed {
			switch ( $key ) {
				case ConfigNames::DatabaseSuffix:
					return 'db';
				case ConfigNames::Subdomain:
					return 'example.org';
				case ConfigNames::DisallowedSubdomains:
					return [ 'badsub' ];
				case ConfigNames::RequestWikiMinimumLength:
					return 10;
				case MainConfigNames::LocalDatabases:
					return [ 'existdb' ];
				default:
					return null;
			}
		} );

		$options->method( 'assertRequiredOptions' )->willReturn( null );
		$this->validator = new CreateWikiValidator(
			$this->messageLocalizer,
			$options
		);
	}

	/**
	 * @covers ::databaseExists
	 */
	public function testDatabaseExists(): void {
		$this->assertTrue( $this->validator->databaseExists( 'existdb' ) );
		$this->assertFalse( $this->validator->databaseExists( 'nonexistdb' ) );
	}

	/**
	 * @covers ::getValidSubdomain
	 */
	public function testGetValidSubdomain(): void {
		$this->assertEquals( 'sub', $this->validator->getValidSubdomain( 'sub.example.org' ) );
		$this->assertEquals( 'sub', $this->validator->getValidSubdomain( 'sub' ) );
	}

	/**
	 * @covers ::validateReason
	 * @dataProvider provideValidateReasonData
	 */
	public function testValidateReason(
		string $reason,
		array $data,
		bool|string $expected
	): void {
		// For cases where an error message is expected, simulate Message behavior.
		if ( is_string( $expected ) ) {
			$message = $this->createMock( Message::class );
			$message->method( 'parse' )->willReturn( $expected );
			$message->method( 'numParams' )->willReturn( $message );
			$this->messageLocalizer->method( 'msg' )->willReturn( $message );
		}
		$result = $this->validator->validateReason( $reason, $data );
		if ( is_bool( $expected ) ) {
			$this->assertSame( $expected, $result );
		} elseif ( $expected === 'parsed' ) {
			$this->assertIsString( $result->parse() );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public function provideValidateReasonData(): Generator {
		yield 'not submitting edit' => [ 'any reason', [ 'edit-reason' => 'test' ], true ];
		yield 'empty reason' => [ '', [ 'submit-edit' => true ], 'parsed' ];
		yield 'short reason' => [ 'short', [ 'submit-edit' => true ], 'parsed' ];
		yield 'valid reason' => [ 'this is a valid reason', [ 'submit-edit' => true ], true ];
	}

	/**
	 * @covers ::validateSubdomain
	 * @dataProvider provideValidateSubdomainData
	 */
	public function testValidateSubdomain(
		string $subdomain,
		array $data,
		bool|string $expected
	): void {
		$message = $this->createMock( Message::class );
		$message->method( 'parse' )->willReturn( 'error' );
		$message->method( 'numParams' )->willReturn( $message );
		$this->messageLocalizer->method( 'msg' )->willReturn( $message );

		$result = $this->validator->validateSubdomain( $subdomain, $data );
		if ( $expected === true ) {
			$this->assertTrue( $result );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public function provideValidateSubdomainData(): Generator {
		yield 'not submitting edit' => [ 'anything', [ 'edit-url' => 'test' ], true ];
		yield 'empty subdomain' => [ '', [ 'submit-edit' => true ], 'error' ];
		yield 'database exists' => [ 'exist.example.org', [ 'submit-edit' => true ], 'error' ];
		yield 'non alnum subdomain' => [ 'sub#', [ 'submit-edit' => true ], 'error' ];
		yield 'disallowed subdomain' => [ 'badsub', [ 'submit-edit' => true ], 'error' ];
		yield 'valid subdomain' => [ 'validsub', [ 'submit-edit' => true ], true ];
	}

	/**
	 * @covers ::validateDatabaseEntry
	 * @dataProvider provideValidateDatabaseEntryData
	 */
	public function testValidateDatabaseEntry(
		string $dbname,
		bool|string $expected
	): void {
		$message = $this->createMock( Message::class );
		$message->method( 'parse' )->willReturn( 'parsed' );
		$message->method( 'numParams' )->willReturn( $message );
		$this->messageLocalizer->method( 'msg' )->willReturn( $message );

		$result = $this->validator->validateDatabaseEntry( $dbname );
		if ( $expected === true ) {
			$this->assertNull( $result );
		} else {
			$this->assertIsString( $result->parse() );
		}
	}

	public function provideValidateDatabaseEntryData(): Generator {
		yield 'empty dbname' => [ '', 'parsed' ];
		yield 'valid dbname' => [ 'validdb', true ];
	}

	/**
	 * @covers ::validateDatabaseName
	 * @dataProvider provideValidateDatabaseNameData
	 */
	public function testValidateDatabaseName(
		string $dbname,
		bool $exists,
		?string $expected
	): void {
		$message = $this->createMock( Message::class );
		$message->method( 'parse' )->willReturn( 'parsed' );
		$message->method( 'numParams' )->willReturn( $message );
		$this->messageLocalizer->method( 'msg' )->willReturn( $message );

		$result = $this->validator->validateDatabaseName( $dbname, $exists );
		if ( $expected === null ) {
			$this->assertNull( $result );
		} else {
			$this->assertIsString( $result );
		}
	}

	public function provideValidateDatabaseNameData(): Generator {
		yield 'not suffixed' => [ 'dbname', false, 'error' ];
		yield 'database exists' => [ 'validdb', true, 'error' ];
		yield 'not alnum' => [ 'validdb!', false, 'error' ];
		yield 'not lowercase' => [ 'Validdb', false, 'error' ];
		yield 'valid dbname' => [ 'validdb', false, null ];
	}
}
