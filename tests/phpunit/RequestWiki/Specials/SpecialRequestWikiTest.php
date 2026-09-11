<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki\Specials;

use Generator;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\Specials\SpecialRequestWiki;
use SpecialPageTestBase;
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
			$services->get( 'CreateWikiParsedMessageCache' ),
			$services->get( 'CreateWikiValidator' ),
			$services->getStatsFactory(),
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
	 * @covers ::getForm
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
	 * @covers ::execute
	 * @covers ::onSuccess
	 */
	public function testExecuteCallsOnSuccessAfterValidSubmission(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [ 'test' => 'test' ],
			ConfigNames::DisallowedSubdomains => [ 'none' ],
			ConfigNames::Subdomain => 'example.org',
		] );

		$context = RequestContext::getMain();
		$context->setRequest( new FauxRequest( [
			'wpsubdomain' => 'example',
			'wpsitename' => 'Example Wiki',
			'wplanguage' => 'en',
			'wpcategory' => 'test',
			'wpreason' => 'Test onSuccess() via execute()',
		] ) );

		$user = $this->getServiceContainer()->getUserFactory()->newFromAuthority(
			$this->getTestUserAuthorityWithConfirmedEmail()
		);

		$context->setUser( $user );
		$this->executeSpecialPage( '', null, null, null, false, $context );

		$expectedUrl = SpecialPage::getTitleFor( 'RequestWikiQueue', '1' )->getFullURL();
		$this->assertSame( $expectedUrl, $context->getOutput()->getRedirect() );
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [ 'test' => 'test' ],
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
		$this->assertArrayHasKey( 'language', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'private', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'purpose', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'reason', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'sitename', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'subdomain', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'wizard-intro', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'wizard-review', $specialRequestWiki->getFormFields() );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForFieldWithCallback(): void {
		$info = $this->specialRequestWiki->getRestValidationInfo( 'subdomain' );

		$this->assertIsArray( $info );
		$this->assertTrue( $info['required'] );
		$this->assertIsCallable( $info['callback'] );
		$this->assertSame( 'textwithbutton', $info['type'] );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForRequiredFieldWithoutCallback(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [ 'test' => 'test' ],
		] );

		$info = $this->specialRequestWiki->getRestValidationInfo( 'category' );

		$this->assertIsArray( $info );
		$this->assertTrue( $info['required'] );
		$this->assertNull( $info['callback'] );
		$this->assertSame( 'select', $info['type'] );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForCheckboxField(): void {
		$this->overrideConfigValues( [
			ConfigNames::RequestWikiConfirmAgreement => true,
		] );

		$info = $this->specialRequestWiki->getRestValidationInfo( 'agreement' );

		$this->assertIsArray( $info );
		$this->assertSame( 'check', $info['type'] );
		$this->assertIsCallable( $info['callback'] );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForFieldWithoutMarkerClass(): void {
		$this->assertNull( $this->specialRequestWiki->getRestValidationInfo( 'sitename' ) );
		$this->assertNull( $this->specialRequestWiki->getRestValidationInfo( 'language' ) );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForUnknownField(): void {
		$this->assertNull( $this->specialRequestWiki->getRestValidationInfo( 'not-a-real-field' ) );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForConditionallyAbsentField(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [],
		] );

		$this->assertNull( $this->specialRequestWiki->getRestValidationInfo( 'category' ) );
	}

	/**
	 * @covers ::isDuplicateRequest
	 * @covers ::onSubmit
	 * @covers ::onSuccess
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

		if ( $extraData['throttled'] ) {
			$this->overrideConfigValue( MainConfigNames::RateLimits, [
				'requestwiki' => [
					'user' => [ 0, 60 ],
					'newbie' => [ 0, 60 ],
					'ip' => [ 0, 60 ],
				],
			] );
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
			$specialRequestWiki->onSuccess();

			$expectedUrl = SpecialPage::getTitleFor( 'RequestWikiQueue', '1' )->getFullURL();
			$this->assertSame( $expectedUrl, $context->getOutput()->getRedirect() );
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
				'category' => 'test',
			],
			[
				'duplicate' => false,
				'throttled' => false,
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
				'category' => 'test',
			],
			[
				'duplicate' => true,
				'throttled' => false,
				'token' => true,
			],
			null,
		];

		yield 'throttled data' => [
			[
				'reason' => 'Test onSubmit()',
				'subdomain' => 'example',
				'sitename' => 'Example Wiki',
				'language' => 'en',
				'category' => 'test',
			],
			[
				'duplicate' => false,
				'throttled' => true,
				'token' => true,
			],
			'requestwiki-throttled',
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
				'throttled' => false,
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

	/**
	 * @covers ::getRestriction
	 */
	public function testGetRestriction(): void {
		$this->assertSame( 'requestwiki', $this->specialRequestWiki->getRestriction() );
	}

	/**
	 * @covers ::doesWrites
	 */
	public function testDoesWrites(): void {
		$this->assertTrue( $this->specialRequestWiki->doesWrites() );
	}

	private function getTestUserAuthorityWithConfirmedEmail(): Authority {
		$user = $this->getTestUser()->getUser();
		$user->setEmail( 'test@example.org' );
		$user->setEmailAuthenticationTimestamp( wfTimestamp() );
		return $user;
	}
}
