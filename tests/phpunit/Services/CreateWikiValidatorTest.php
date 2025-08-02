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
use PHPUnit\Framework\MockObject\MockObject;
use function is_bool;
use function is_string;

/**
 * @group CreateWiki
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Services\CreateWikiValidator
 */
class CreateWikiValidatorTest extends MediaWikiIntegrationTestCase {

	private CreateWikiValidator $validator;
	private MockObject $messageLocalizerMock;
	private MockObject $messageMock;

	protected function setUp(): void {
		parent::setUp();
		$this->messageLocalizerMock = $this->createMock( MessageLocalizer::class );
		$this->messageMock = $this->createMock( Message::class );

		$options = $this->createMock( ServiceOptions::class );
		$options->method( 'get' )->willReturnMap( [
			[ ConfigNames::DatabaseSuffix, 'db' ],
			[ ConfigNames::Subdomain, 'example.org' ],
			[ ConfigNames::DisallowedSubdomains, [ 'badsub' ] ],
			[ ConfigNames::RequestWikiMinimumLength, 10 ],
			[ MainConfigNames::LocalDatabases, [ 'existdb' ] ],
		] );

		$options->method( 'assertRequiredOptions' )->willReturn( null );
		'@phan-var ServiceOptions $options';

		$messageLocalizer = $this->messageLocalizerMock;
		'@phan-var MessageLocalizer $messageLocalizer';
		$this->validator = new CreateWikiValidator(
			$messageLocalizer,
			$options
		);
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( CreateWikiValidator::class, $this->validator );
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
		$this->assertEquals( 'sub', $this->validator->getValidSubdomain( 'sub.example.org.example.org' ) );
		$this->assertEquals( 'sub', $this->validator->getValidSubdomain( 'sub.example.org' ) );
		$this->assertEquals( 'sub', $this->validator->getValidSubdomain( 'sub' ) );
	}

	/**
	 * @covers ::getValidUrl
	 */
	public function testGetValidUrl(): void {
		$this->assertEquals( 'https://test.example.org', $this->validator->getValidUrl( 'testdb' ) );
	}

	/**
	 * @covers ::validateAgreement
	 * @dataProvider provideValidateAgreementData
	 */
	public function testValidateAgreement(
		bool $agreement,
		bool|string $expected
	): void {
		$this->messageMock->method( 'parse' )->willReturn( 'error' );
		$this->messageLocalizerMock->method( 'msg' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'createwiki-error-agreement' )
			->willReturn( $this->messageMock );

		$result = $this->validator->validateAgreement( $agreement );
		if ( $expected === true ) {
			$this->assertTrue( $result );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public static function provideValidateAgreementData(): Generator {
		yield 'agreement false' => [ false, 'error' ];
		yield 'agreement true' => [ true, true ];
	}

	/**
	 * @covers ::validateComment
	 * @dataProvider provideValidateCommentData
	 */
	public function testValidateComment(
		string $comment,
		array $data,
		bool|string $expected
	): void {
		$this->messageMock->method( 'parse' )->willReturn( 'error' );
		$this->messageLocalizerMock->method( 'msg' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'htmlform-required' )
			->willReturn( $this->messageMock );

		$result = $this->validator->validateComment( $comment, $data );
		if ( $expected === true ) {
			$this->assertTrue( $result );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public static function provideValidateCommentData(): Generator {
		yield 'submit-comment empty' => [ '', [ 'submit-comment' => true ], 'error' ];
		yield 'submit-comment whitespace' => [ '   ', [ 'submit-comment' => true ], 'error' ];
		yield 'submit-comment valid' => [ 'Valid comment', [ 'submit-comment' => true ], true ];
		yield 'no submit-comment empty' => [ '', [], true ];
		yield 'no submit-comment whitespace' => [ '   ', [], true ];
		yield 'no submit-comment valid' => [ 'Some comment', [], true ];
	}

	/**
	 * @covers ::validateDatabaseEntry
	 * @dataProvider provideValidateDatabaseEntryData
	 */
	public function testValidateDatabaseEntry(
		string $dbname,
		bool|string $expected
	): void {
		$this->messageMock->method( 'parse' )->willReturn( 'parsed' );
		$this->messageMock->method( 'numParams' )->willReturn( $this->messageMock );
		$this->messageLocalizerMock->method( 'msg' )->willReturn( $this->messageMock );

		$result = $this->validator->validateDatabaseEntry( $dbname );
		if ( $expected === true ) {
			$this->assertTrue( $result );
		} elseif ( $expected === 'parsed' ) {
			// @phan-suppress-next-line PhanPossiblyNonClassMethodCall
			$this->assertIsString( $result->parse() );
		} else {
			$this->assertIsString( $result );
		}
	}

	public static function provideValidateDatabaseEntryData(): Generator {
		yield 'empty dbname' => [ '', 'parsed' ];
		yield 'whitespace dbname' => [ '   ', 'parsed' ];
		yield 'not suffixed' => [ 'dbname', 'error' ];
		yield 'database exists' => [ 'existdb', 'error' ];
		yield 'not alnum' => [ '!validdb', 'error' ];
		yield 'not lowercase' => [ 'Validdb', 'error' ];
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
		$this->messageMock->method( 'parse' )->willReturn( 'parsed' );
		$this->messageMock->method( 'numParams' )->willReturn( $this->messageMock );
		$this->messageLocalizerMock->method( 'msg' )->willReturn( $this->messageMock );

		$result = $this->validator->validateDatabaseName( $dbname, $exists );
		if ( $expected === null ) {
			$this->assertNull( $result );
		} else {
			$this->assertIsString( $result );
		}
	}

	public static function provideValidateDatabaseNameData(): Generator {
		yield 'not suffixed' => [ 'dbname', false, 'error' ];
		yield 'database exists' => [ 'validdb', true, 'error' ];
		yield 'not alnum' => [ '!validdb', false, 'error' ];
		yield 'not lowercase' => [ 'Validdb', false, 'error' ];
		yield 'valid dbname' => [ 'validdb', false, null ];
	}

	/**
	 * @covers ::isDisallowedRegex
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
			$this->messageMock->method( 'parse' )->willReturn( $expected );
			$this->messageMock->method( 'numParams' )->willReturn( $this->messageMock );
			$this->messageLocalizerMock->method( 'msg' )->willReturn( $this->messageMock );
		}
		$result = $this->validator->validateReason( $reason, $data );
		if ( is_bool( $expected ) ) {
			$this->assertSame( $expected, $result );
		} elseif ( $expected === 'parsed' ) {
			// @phan-suppress-next-line PhanPossiblyNonClassMethodCall
			$this->assertIsString( $result->parse() );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public static function provideValidateReasonData(): Generator {
		yield 'not submitting edit via edit-reason' => [ 'any reason', [ 'edit-reason' => 'test' ], true ];
		yield 'empty reason with submit-edit' => [ '', [ 'submit-edit' => true ], 'parsed' ];
		yield 'short reason with submit-edit' => [ 'short', [ 'submit-edit' => true ], 'parsed' ];
		yield 'valid reason with submit-edit' => [ 'this is a valid reason', [ 'submit-edit' => true ], true ];
		yield 'whitespace reason with submit-edit' => [ '   ', [ 'submit-edit' => true ], 'parsed' ];
		yield 'valid reason without submit-edit or edit keys' => [ 'this is valid reason', [], true ];
		yield 'short reason without submit-edit or edit keys' => [ 'short', [], 'parsed' ];
	}

	/**
	 * @covers ::validateStatusComment
	 * @dataProvider provideValidateStatusCommentData
	 */
	public function testValidateStatusComment(
		string $comment,
		array $data,
		bool|string $expected
	): void {
		$this->messageMock->method( 'parse' )->willReturn( 'error' );
		$this->messageLocalizerMock->method( 'msg' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'htmlform-required' )
			->willReturn( $this->messageMock );

		$result = $this->validator->validateStatusComment( $comment, $data );
		if ( $expected === true ) {
			$this->assertTrue( $result );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public static function provideValidateStatusCommentData(): Generator {
		yield 'submit-handle empty' => [ '', [ 'submit-handle' => true ], 'error' ];
		yield 'submit-handle whitespace' => [ '   ', [ 'submit-handle' => true ], 'error' ];
		yield 'submit-handle valid' => [ 'Valid status comment', [ 'submit-handle' => true ], true ];
		yield 'no submit-handle empty' => [ '', [], true ];
		yield 'no submit-handle whitespace' => [ '   ', [], true ];
		yield 'no submit-handle valid' => [ 'Some comment', [], true ];
	}

	/**
	 * @covers ::getDisallowedSubdomains
	 * @covers ::validateSubdomain
	 * @dataProvider provideValidateSubdomainData
	 */
	public function testValidateSubdomain(
		string $subdomain,
		array $data,
		bool|string $expected
	): void {
		$this->messageMock->method( 'parse' )->willReturn( 'error' );
		$this->messageMock->method( 'numParams' )->willReturn( $this->messageMock );
		$this->messageLocalizerMock->method( 'msg' )->willReturn( $this->messageMock );

		$result = $this->validator->validateSubdomain( $subdomain, $data );
		if ( $expected === true ) {
			$this->assertTrue( $result );
		} else {
			$this->assertInstanceOf( Message::class, $result );
		}
	}

	public static function provideValidateSubdomainData(): Generator {
		yield 'not submitting edit via edit-url' => [ 'anything', [ 'edit-url' => 'test' ], true ];
		yield 'empty subdomain with submit-edit' => [ '', [ 'submit-edit' => true ], 'error' ];
		yield 'database exists with submit-edit' => [ 'exist.example.org', [ 'submit-edit' => true ], 'error' ];
		yield 'non alnum subdomain with submit-edit' => [ 'sub#', [ 'submit-edit' => true ], 'error' ];
		yield 'disallowed subdomain with submit-edit' => [ 'badsub', [ 'submit-edit' => true ], 'error' ];
		yield 'valid subdomain with submit-edit' => [ 'validsub', [ 'submit-edit' => true ], true ];
		yield 'uppercase disallowed subdomain with submit-edit' => [ 'BADSUB', [ 'submit-edit' => true ], 'error' ];
		yield 'subdomain with config domain included with submit-edit' => [
			'sub.example.org', [ 'submit-edit' => true ], true
		];
		yield 'subdomain with spaces with submit-edit' => [ '   ', [ 'submit-edit' => true ], 'error' ];
		yield 'valid subdomain without submit-edit or edit keys' => [ 'validsub', [], true ];
		yield 'empty subdomain without submit-edit or edit keys' => [ '', [], 'error' ];
		yield 'database exists without submit-edit or edit keys' => [ 'exist.example.org', [], 'error' ];
		yield 'non alnum subdomain without submit-edit or edit keys' => [ 'sub#', [], 'error' ];
		yield 'disallowed subdomain without submit-edit or edit keys' => [ 'badsub', [], 'error' ];
	}
}
