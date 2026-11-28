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
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormDescriptorBuilder;
use Miraheze\CreateWiki\RequestWiki\Rest\RequestWikiValidateFieldsHandler;
use Miraheze\CreateWiki\Services\WikiRequestManager;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\Rest\RequestWikiValidateFieldsHandler
 */
class RequestWikiValidateFieldsHandlerTest extends MediaWikiIntegrationTestCase {

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

	private function newHandler(): RequestWikiValidateFieldsHandler {
		$services = $this->getServiceContainer();
		return new RequestWikiValidateFieldsHandler(
			$services->get( 'CreateWikiRestUtils' ),
			$services->get( 'CreateWikiValidator' ),
			$services->get( 'RequestWikiFormDescriptorBuilder' ),
			$services->getUserFactory(),
			$services->get( 'WikiRequestManager' )
		);
	}

	private function newHandlerWithFormDescriptorBuilder(
		RequestWikiFormDescriptorBuilder $formDescriptorBuilder
	): RequestWikiValidateFieldsHandler {
		$services = $this->getServiceContainer();
		return new RequestWikiValidateFieldsHandler(
			$services->get( 'CreateWikiRestUtils' ),
			$services->get( 'CreateWikiValidator' ),
			$formDescriptorBuilder,
			$services->getUserFactory(),
			$services->get( 'WikiRequestManager' )
		);
	}

	private function newHandlerWithUserFactory(
		UserFactory $userFactory
	): RequestWikiValidateFieldsHandler {
		$services = $this->getServiceContainer();
		return new RequestWikiValidateFieldsHandler(
			$services->get( 'CreateWikiRestUtils' ),
			$services->get( 'CreateWikiValidator' ),
			$services->get( 'RequestWikiFormDescriptorBuilder' ),
			$userFactory,
			$services->get( 'WikiRequestManager' )
		);
	}

	private function newHandlerWithWikiRequestManager(
		WikiRequestManager $wikiRequestManager
	): RequestWikiValidateFieldsHandler {
		$services = $this->getServiceContainer();
		return new RequestWikiValidateFieldsHandler(
			$services->get( 'CreateWikiRestUtils' ),
			$services->get( 'CreateWikiValidator' ),
			$services->get( 'RequestWikiFormDescriptorBuilder' ),
			$services->getUserFactory(),
			$wikiRequestManager
		);
	}

	/** @return array{checks: array, token: string} */
	private function singleCheckBody( string $field, string $value ): array {
		return [
			'checks' => [ [ 'field' => $field, 'value' => $value ] ],
			'token' => '',
		];
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( RequestWikiValidateFieldsHandler::class, $this->newHandler() );
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
			$this->singleCheckBody( $field, $value ),
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertArrayHasKey( $field, $data['results'] );
		$this->assertSame( $expectedValid, $data['results'][$field]['valid'] );
		if ( !$expectedValid ) {
			$this->assertArrayHasKey( 'message', $data['results'][$field] );
			$this->assertIsString( $data['results'][$field]['message'] );
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
	public function testRunWhenNotThrottled(): void {
		$data = $this->executeHandlerAndGetBodyData(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			$this->singleCheckBody( 'throttled', '' ),
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertArrayNotHasKey( 'throttled', $data['results'] );
	}

	/**
	 * @covers ::run
	 */
	public function testRunWhenNotDuplicate(): void {
		$data = $this->executeHandlerAndGetBodyData(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			$this->singleCheckBody( 'duplicate', 'A Brand New Sitename' ),
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertArrayNotHasKey( 'duplicate', $data['results'] );
	}

	/**
	 * @covers ::run
	 */
	public function testRunBatchesMultipleChecksInOneRequest(): void {
		$data = $this->executeHandlerAndGetBodyData(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			[
				'checks' => [
					[ 'field' => 'subdomain', 'value' => 'validsub' ],
					[ 'field' => 'category', 'value' => '' ],
					[ 'field' => 'reason', 'value' => 'this is a valid reason' ],
				],
				'token' => '',
			],
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertTrue( $data['results']['subdomain']['valid'] );
		// @phan-suppress-next-line PhanTypeArraySuspiciousNull,PhanTypeInvalidDimOffset
		$this->assertFalse( $data['results']['category']['valid'] );
		// @phan-suppress-next-line PhanTypeArraySuspiciousNull,PhanTypeInvalidDimOffset
		$this->assertTrue( $data['results']['reason']['valid'] );
	}

	/**
	 * @covers ::run
	 */
	public function testRunIgnoresMalformedChecks(): void {
		$data = $this->executeHandlerAndGetBodyData(
			$this->newHandler(),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			[
				'checks' => [
					[ 'field' => 'subdomain' ],
					'not-an-array',
					[ 'field' => 'reason', 'value' => 'this is a valid reason' ],
				],
				'token' => '',
			],
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertArrayNotHasKey( 'subdomain', $data['results'] );
		$this->assertTrue( $data['results']['reason']['valid'] );
	}

	/**
	 * @covers ::run
	 */
	public function testRunRejectsThrottledUser(): void {
		$user = $this->createMock( User::class );
		// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
		$user->method( 'pingLimiter' )->with( 'requestwiki', 0 )->willReturn( true );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromAuthority' )->willReturn( $user );

		$response = $this->executeHandler(
			$this->newHandlerWithUserFactory( $userFactory ),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			$this->singleCheckBody( 'throttled', '' ),
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertSame( 429, $response->getStatusCode() );
	}

	/**
	 * @covers ::run
	 */
	public function testRunRejectsDuplicateRequest(): void {
		$wikiRequestManager = $this->createMock( WikiRequestManager::class );
		$wikiRequestManager->method( 'isDuplicateRequest' )
			// @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
			->with( 'An Existing Sitename' )
			->willReturn( true );

		$response = $this->executeHandler(
			$this->newHandlerWithWikiRequestManager( $wikiRequestManager ),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			$this->singleCheckBody( 'duplicate', 'An Existing Sitename' ),
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertSame( 403, $response->getStatusCode() );
	}

	/**
	 * @covers ::validateField
	 */
	public function testValidateFieldWhenNotRequiredAndHasNoCallback(): void {
		$formDescriptorBuilder = $this->createMock( RequestWikiFormDescriptorBuilder::class );
		$formDescriptorBuilder->method( 'build' )->willReturn( [ 'descriptor' => [], 'extraFields' => [] ] );
		$formDescriptorBuilder->method( 'getRestValidationInfo' )->willReturn( [
			'required' => false,
			'callback' => null,
			'type' => 'text',
		] );

		$data = $this->executeHandlerAndGetBodyData(
			$this->newHandlerWithFormDescriptorBuilder( $formDescriptorBuilder ),
			new RequestData( [ 'method' => 'POST' ] ),
			[],
			[],
			[],
			$this->singleCheckBody( 'optionalfield', '' ),
			$this->mockRegisteredUltimateAuthority(),
			$this->getSession( true )
		);

		$this->assertTrue( $data['results']['optionalfield']['valid'] );
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
			$this->singleCheckBody( 'subdomain', 'validsub' ),
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
			$this->singleCheckBody( 'subdomain', 'validsub' ),
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
				$this->singleCheckBody( 'subdomain', 'validsub' ),
				$this->mockRegisteredUltimateAuthority(),
				$session
			);

			$this->fail( 'Expected a LocalizedHttpException to be thrown' );
		} catch ( LocalizedHttpException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
		}
	}
}
