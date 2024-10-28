<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki\Specials;

use ErrorPageError;
use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\Status\Status;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki;
use SpecialPageTestBase;
use UserNotLoggedIn;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki
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

		$this->overrideConfigValue(
			ConfigNames::GlobalWiki, WikiMap::getCurrentWikiId()
		);

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
	public function testExecuteNotLoggedIn(): void {
		$this->expectException( UserNotLoggedIn::class );
		$this->executeSpecialPage();
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedInEmailConfirmed(): void {
		$performer = $this->getTestUserAuthorityWithConfirmedEmail();
		[ $html, ] = $this->executeSpecialPage( '', null, 'qqx', $performer );
		$this->assertStringContainsString( '(requestwiki-text)', $html );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedInEmailNotConfirmed(): void {
		$this->expectException( ErrorPageError::class );
		$this->expectExceptionMessageMatches(
			'/Your email is not confirmed. To request wikis, please ' .
			'\[\[Special:ChangeEmail\|confirm an email\]\] first./'
		);

		$performer = $this->getTestUser()->getAuthority();
		$this->executeSpecialPage( '', null, 'en', $performer );
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

		$this->overrideConfigValue(
			ConfigNames::Subdomain, 'example.com'
		);

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

	private function getTestUserAuthorityWithConfirmedEmail(): Authority {
		$user = $this->getTestUser()->getUser();
		$user->setEmail( 'test@example.com' );
		$user->setEmailAuthenticationTimestamp( wfTimestamp() );
		return $user;
	}
}
