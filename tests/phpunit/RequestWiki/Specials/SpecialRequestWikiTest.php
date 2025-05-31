<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki\Specials;

use ErrorPageError;
use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Status\Status;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki;
use SpecialPageTestBase;
use UserNotLoggedIn;
use Wikimedia\TestingAccessWrapper;
use function wfTimestamp;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki
 */
class SpecialRequestWikiTest extends SpecialPageTestBase {

	use TempUserTestTrait;

	private SpecialRequestWiki $specialRequestWiki;

	/** @inheritDoc */
	protected function newSpecialPage(): SpecialRequestWiki {
		$services = $this->getServiceContainer();
		return new SpecialRequestWiki(
			$services->get( 'CreateWikiDatabaseUtils' ),
			$services->get( 'CreateWikiHookRunner' ),
			$services->get( 'CreateWikiValidator' ),
			$services->get( 'WikiRequestManager' )
		);
	}

	protected function setUp(): void {
		parent::setUp();

		// T12639
		$this->disableAutoCreateTempUser();

		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-createwiki-central' => [ 'db' => WikiMap::getCurrentWikiId() ],
		] );

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
		$this->overrideConfigValues( [
			ConfigNames::Categories => [ 'uncategorised' => 'uncategorised' ],
			ConfigNames::Purposes => [ 'test' => 'test' ],
			ConfigNames::RequestWikiConfirmAgreement => true,
			ConfigNames::ShowBiographicalOption => true,
			ConfigNames::UsePrivateWikis => true,
		] );

		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			$this->specialRequestWiki
		);

		$this->assertArrayHasKey( 'agreement', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'bio', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'category', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'guidance', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'language', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'post-reason-guidance', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'private', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'purpose', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'reason', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'sitename', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'subdomain', $specialRequestWiki->getFormFields() );
	}

	/**
	 * @covers ::onSubmit
	 * @dataProvider onSubmitDataProvider
	 */
	public function testOnSubmit(
		array $formData,
		array $extraData,
		?string $expectedError
	): void {
		$context = new DerivativeContext( $this->specialRequestWiki->getContext() );
		$user = $this->getMutableTestUser()->getUser();
		$context->setUser( $user );

		$data = [];
		if ( $extraData['token'] ) {
			$data = [ 'wpEditToken' => $context->getCsrfTokenSet()->getToken()->toString() ];
		}

		$request = new FauxRequest( $data, true );
		$context->setRequest( $request );

		$specialRequestWiki = TestingAccessWrapper::newFromObject( $this->specialRequestWiki );
		$specialRequestWiki->setContext( $context );

		$this->overrideConfigValue(
			ConfigNames::Subdomain, 'example.org'
		);

		$status = $specialRequestWiki->onSubmit( $formData );
		$this->assertInstanceOf( Status::class, $status );
		if ( !$expectedError ) {
			$this->assertStatusGood( $status );
		} else {
			$this->assertStatusError( $expectedError, $status );
		}

		if ( $extraData['duplicate'] ) {
			$status = $specialRequestWiki->onSubmit( $formData );
			$this->assertInstanceOf( Status::class, $status );
			$this->assertStatusError( 'requestwiki-error-patient', $status );
		}
	}

	public static function onSubmitDataProvider(): Generator {
		yield 'valid data' => [
			[
				'reason' => 'Test onSubmit()',
				'subdomain' => 'example',
				'sitename' => 'Example Wiki',
				'language' => 'en',
				'category' => 'uncategorised',
			],
			[
				'duplicate' => false,
				'token' => true,
			],
			null,
		];

		yield 'duplicate data' => [
			[
				'reason' => 'Test onSubmit()',
				'subdomain' => 'example',
				'sitename' => 'Example Wiki',
				'language' => 'en',
				'category' => 'uncategorised',
			],
			[
				'duplicate' => true,
				'token' => true,
			],
			null,
		];

		yield 'session failure' => [
			[
				'reason' => '',
				'subdomain' => '',
				'sitename' => '',
				'language' => '',
				'category' => '',
			],
			[
				'duplicate' => false,
				'token' => false,
			],
			'sessionfailure',
		];
	}

	/**
	 * @covers ::getDisplayFormat
	 */
	public function testGetDisplayFormat(): void {
		$specialRequestWiki = TestingAccessWrapper::newFromObject( $this->specialRequestWiki );
		$this->assertSame( 'ooui', $specialRequestWiki->getDisplayFormat() );
	}

	/**
	 * @covers ::getGroupName
	 */
	public function testGetGroupName(): void {
		$specialRequestWiki = TestingAccessWrapper::newFromObject( $this->specialRequestWiki );
		$this->assertSame( 'wiki', $specialRequestWiki->getGroupName() );
	}

	private function getTestUserAuthorityWithConfirmedEmail(): Authority {
		$user = $this->getTestUser()->getUser();
		$user->setEmail( 'test@example.org' );
		$user->setEmailAuthenticationTimestamp( wfTimestamp() );
		return $user;
	}
}
