<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki\Rest;

use Generator;
use MediaWiki\Block\SystemBlock;
use MediaWiki\MainConfigNames;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Session\Token;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\Rest\RequestWikiValidateFieldHandler;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\Rest\RequestWikiValidateFieldHandler
 */
class RequestWikiValidateFieldHandlerTest extends MediaWikiIntegrationTestCase {

	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			ConfigNames::Categories => [ 'test' => 'test' ],
			ConfigNames::DatabaseSuffix => 'wiki',
			ConfigNames::DisallowedSubdomains => [ 'badsub' ],
			ConfigNames::EnableRESTAPI => true,
			ConfigNames::Purposes => [ 'test' => 'test' ],
			ConfigNames::RequestWikiConfirmAgreement => true,
			ConfigNames::RequestWikiMinimumLength => 10,
			ConfigNames::Subdomain => 'example.org',
			MainConfigNames::LocalDatabases => [ 'existwiki' ],
		] );
	}

	private function newHandler(): RequestWikiValidateFieldHandler {
		$services = $this->getServiceContainer();
		return new RequestWikiValidateFieldHandler(
			$services->get( 'CreateWikiRestUtils' ),
			$services->get( 'CreateWikiValidator' ),
			$services->getSpecialPageFactory()
		);
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( RequestWikiValidateFieldHandler::class, $this->newHandler() );
	}

	/**
	 * @covers ::needsWriteAccess
	 */
	public function testNeedsWriteAccess(): void {
		$this->assertFalse( $this->newHandler()->needsWriteAccess() );
	}

	/**
	 * @covers ::run
	 * @covers ::validateField
	 * @covers ::getBodyParamSettings
	 * @dataProvider provideRunData
	 */
	public function testRun( string $field, string $value, bool $expectedValid ): void {
		$data = $this->executeHandlerAndGetBodyData(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			[ 'field' => $field, 'value' => $value, 'token' => '' ],
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertSame( $expectedValid, $data['valid'] );
		if ( !$expectedValid ) {
			$this->assertArrayHasKey( 'message', $data );
			$this->assertIsString( $data['message'] );
		}
	}

	public static function provideRunData(): Generator {
		yield 'valid subdomain' => [ 'subdomain', 'validsub', true ];
		yield 'disallowed subdomain' => [ 'subdomain', 'badsub', false ];
		yield 'empty subdomain' => [ 'subdomain', '', false ];
		yield 'database exists subdomain' => [ 'subdomain', 'exist', false ];
		yield 'valid reason' => [ 'reason', 'this is a valid reason', true ];
		yield 'short reason' => [ 'reason', 'short', false ];
		yield 'empty reason' => [ 'reason', '', false ];
		yield 'empty category' => [ 'category', '', false ];
		yield 'whitespace category' => [ 'category', '   ', false ];
		yield 'filled category' => [ 'category', 'somecategory', true ];
		yield 'empty purpose' => [ 'purpose', '', false ];
		yield 'filled purpose' => [ 'purpose', 'somepurpose', true ];
		yield 'agreement unchecked' => [ 'agreement', '', false ];
		yield 'agreement checked' => [ 'agreement', '1', true ];
		yield 'sitename is not rest-validated' => [ 'sitename', '', true ];
		yield 'unrecognised field defaults to valid' => [ 'somethingelse', 'anything', true ];
	}

	/**
	 * @covers ::run
	 */
	public function testRunRejectsAnonymousUser(): void {
		$response = $this->executeHandler(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			[ 'field' => 'subdomain', 'value' => 'validsub', 'token' => '' ],
			$this->mockAnonUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertSame( 403, $response->getStatusCode() );
	}

	/**
	 * @covers ::run
	 */
	public function testRunRejectsBlockedUser(): void {
		$user = $this->getTestUser()->getUserIdentity();
		$blockTargetFactory = $this->getServiceContainer()->getBlockTargetFactory();
		$blockTarget = $blockTargetFactory->newFromUser( $user );
		$block = new SystemBlock( [
			'target' => $blockTarget,
			'by' => $this->getTestSysop()->getUser(),
			'reason' => 'test block',
			'systemBlock' => 'test',
		] );

		$authority = $this->mockUserAuthorityWithBlock( $user, $block );

		$response = $this->executeHandler(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			[ 'field' => 'subdomain', 'value' => 'validsub', 'token' => '' ],
			$authority,
			$this->getSession( true )
		);

		$this->assertSame( 403, $response->getStatusCode() );
	}

	/**
	 * @covers ::validate
	 */
	public function testRunRequiresCsrfTokenWhenSessionIsNotSafe(): void {
		$session = $this->createNoOpMock( Session::class,
			[ 'getProvider', 'isPersistent', 'hasToken', 'getToken', 'getUser' ] );
		$sessionProvider = $this->createNoOpMock( SessionProvider::class, [ 'safeAgainstCsrf' ] );
		$sessionProvider->method( 'safeAgainstCsrf' )->willReturn( false );
		$session->method( 'getProvider' )->willReturn( $sessionProvider );
		$session->method( 'isPersistent' )->willReturn( true );
		$session->method( 'hasToken' )->willReturn( false );
		$session->method( 'getToken' )->willReturn( new Token( 'token', '' ) );

		$user = $this->createNoOpMock( User::class, [ 'isAnon' ] );
		$user->method( 'isAnon' )->willReturn( false );
		$session->method( 'getUser' )->willReturn( $user );

		try {
			$this->executeHandler(
				$this->newHandler(),
				new RequestData( [ 'method' => 'POST' ] ),
				[],
				[],
				[],
				[ 'field' => 'subdomain', 'value' => 'validsub', 'token' => '' ],
				$this->mockRegisteredUltimateAuthority(),
				$session
			);
			$this->fail( 'Expected a LocalizedHttpException to be thrown' );
		} catch ( LocalizedHttpException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
		}
	}
}
