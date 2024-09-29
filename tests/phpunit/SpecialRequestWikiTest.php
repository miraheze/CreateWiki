<?php

namespace Miraheze\CreateWiki\Tests;

use ErrorPageError;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\WikiMap\WikiMap;
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
	protected function newSpecialPage() {
		$services = $this->getServiceContainer();
		return new SpecialRequestWiki(
			$services->getConnectionProvider()
		);
	}

	protected function setUp(): void {
		parent::setUp();

		// T12639
		$this->disableAutoCreateTempUser();

		$this->specialRequestWiki = $this->newSpecialPage();
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor() {
		$this->assertInstanceOf( SpecialRequestWiki::class, $this->specialRequestWiki );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteNotLoggedIn() {
		$this->setMwGlobals( 'wgCreateWikiGlobalWiki', WikiMap::getCurrentWikiId() );
		$this->expectException( UserNotLoggedIn::class );
		$this->specialRequestWiki->execute( '' );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedInEmailConfirmed() {
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
	public function testExecuteLoggedInEmailNotConfirmed() {
		$this->setMwGlobals( 'wgCreateWikiGlobalWiki', WikiMap::getCurrentWikiId() );
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
			'/Your email is not confirmed. To request wikis, please \[\[Special:ChangeEmail\|confirm an email\]\] first./'
		);

		$specialRequestWiki->execute( '' );
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields() {
		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			$this->specialRequestWiki
		);

		$this->assertArrayHasKey( 'subdomain', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'sitename', $specialRequestWiki->getFormFields() );
		$this->assertArrayHasKey( 'language', $specialRequestWiki->getFormFields() );
	}

	/**
	 * Data provider for testOnSubmit
	 *
	 * @return array
	 */
	public function onSubmitDataProvider() {
		return [
			[
				[
					'reason' => 'Test onSubmit()',
					'subdomain' => 'example',
					'sitename' => 'Example Wiki',
					'language' => 'en',
					'category' => 'uncategorised',
				],
				true,
			],
		];
	}

	/**
	 * @dataProvider onSubmitDataProvider
	 * @covers ::onSubmit
	 * @param array $formData
	 * @param bool $expected
	 */
	public function testOnSubmit( $formData, $expected ) {
		$this->setMwGlobals( 'wgCreateWikiSubdomain', 'miraheze.org' );

		$submitData = $this->specialRequestWiki->onSubmit( $formData );
		$this->assertSame( $expected, $submitData );
	}
}
