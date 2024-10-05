<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki;

use ErrorPageError;
use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RequestWiki\SpecialRequestWiki;
use SpecialPageTestBase;
use UserNotLoggedIn;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\SpecialRequestWiki
 */
class SpecialRequestWikiTest extends SpecialPageTestBase {

	use TempUserTestTrait;

	private SpecialRequestWiki $specialRequestWiki;

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialRequestWiki {
		$services = $this->getServiceContainer();
		return new SpecialRequestWiki(
			$services->getConnectionProvider(),
			$this->createMock( CreateWikiHookRunner::class )
		);
	}

	protected function setUp(): void {
		parent::setUp();

		// T12639
		$this->disableAutoCreateTempUser();

		$this->setMwGlobals( 'wgCreateWikiGlobalWiki', WikiMap::getCurrentWikiId() );

		$this->specialRequestWiki = $this->newSpecialPage();
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( SpecialRequestWiki::class, $this->specialRequestWiki );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute() {
		$performer = $this->getTestUser()->getAuthority();
		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx', $performer );
		$this->assertStringContainsString( '(requestwiki-text)', $html );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteNotLoggedIn() {
		$this->expectException( UserNotLoggedIn::class );
		$this->executeSpecialPage();
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedInEmailConfirmed(): void {
		$this->setGroupPermissions( 'user', 'requestwiki', true );

		$user = $this->getTestUser()->getUser();
		$user->setEmail( 'test@example.com' );
		$user->setEmailAuthenticationTimestamp( wfTimestamp() );

		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			$this->specialRequestWiki
		);

		$testContext = new DerivativeContext( $specialRequestWiki->getContext() );

		$testContext->setUser( $user );
		$testContext->setTitle( SpecialPage::getTitleFor( 'RequestWiki' ) );

		$specialRequestWiki->setContext( $testContext );

		$this->assertNull( $specialRequestWiki->execute( '' ) );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedInEmailNotConfirmed(): void {
		$this->setGroupPermissions( 'user', 'requestwiki', true );

		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			$this->specialRequestWiki
		);

		$testContext = new DerivativeContext( $specialRequestWiki->getContext() );

		$testContext->setUser( $this->getTestUser()->getUser() );
		$testContext->setTitle( SpecialPage::getTitleFor( 'RequestWiki' ) );

		$specialRequestWiki->setContext( $testContext );
		$this->expectException( ErrorPageError::class );
		$this->expectExceptionMessageMatches(
			'/Your email is not confirmed. To request wikis, please ' .
			'\[\[Special:ChangeEmail\|confirm an email\]\] first./'
		);

		$specialRequestWiki->execute( '' );
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields(): void {
		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			$this->specialRequestWiki
		);

		$this->assertArrayHasKey( 'subdomain', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'sitename', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'language', $specialRequestWiki->getFormFields() );
	}

	/**
	 * @dataProvider onSubmitDataProvider
	 * @covers ::onSubmit
	 * @param array $formData
	 */
	public function testOnSubmit( array $formData ): void {
		$context = new DerivativeContext( $this->specialRequestWiki->getContext() );
		$user = $this->getMutableTestUser()->getUser();

		$context->setUser( $user );
		$this->setSessionUser( $user, $user->getRequest() );

		$request = new FauxRequest(
			[ 'wpEditToken' => $user->getEditToken() ],
			true
		);

		$context->setRequest( $request );

		$specialRequestWiki = TestingAccessWrapper::newFromObject( $this->specialRequestWiki );
		$specialRequestWiki->setContext( $context );

		$this->setMwGlobals( 'wgCreateWikiSubdomain', 'example.com' );

		$status = $specialRequestWiki->onSubmit( $formData );
		$this->assertInstanceOf( Status::class, $status );
		$this->assertStatusGood( $status );
	}

	/**
	 * Data provider for testOnSubmit
	 *
	 * @return Generator
	 */
	public function onSubmitDataProvider(): Generator {
		yield 'valid data' => [
			[
				'reason' => 'Test onSubmit()',
				'subdomain' => 'example',
				'sitename' => 'Example Wiki',
				'language' => 'en',
				'category' => 'uncategorised',
			],
		];
	}

	private function setSessionUser( User $user, WebRequest $request ): void {
		RequestContext::getMain()->setUser( $user );
		RequestContext::getMain()->setRequest( $request );
		TestingAccessWrapper::newFromObject( $user )->mRequest = $request;
		$request->getSession()->setUser( $user );
	}
}
