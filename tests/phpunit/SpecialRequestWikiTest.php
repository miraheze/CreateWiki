<?php

namespace Miraheze\CreateWiki\Tests;

use DerivativeContext;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RequestWiki\SpecialRequestWiki;
use User;
use UserNotLoggedIn;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CreateWiki
 * @group Database
 * @group Medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\SpecialRequestWiki
 */
class SpecialRequestWikiTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers ::__construct
	 */
	public function testConstructor() {
		$hookRunner = $this->createMock( CreateWikiHookRunner::class );
		$specialRequestWiki = new SpecialRequestWiki( $hookRunner );

		$this->assertInstanceOf( SpecialRequestWiki::class, $specialRequestWiki );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteNotLoggedIn() {
		$hookRunner = $this->createMock( CreateWikiHookRunner::class );

		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			new SpecialRequestWiki( $hookRunner )
		);

		$testContext = new DerivativeContext( $specialRequestWiki->getContext() );

		$anon = $this->createMock( User::class );
		$anon->method( 'isRegistered' )->willReturn( false );

		$testContext->setUser( $anon );
		$specialRequestWiki->setContext( $testContext );

		$this->expectException( UserNotLoggedIn::class );
		$specialRequestWiki->execute( '' );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecuteLoggedIn() {
		$this->setGroupPermissions( 'user', 'requestwiki', true );

		$hookRunner = $this->createMock( CreateWikiHookRunner::class );

		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			new SpecialRequestWiki( $hookRunner )
		);

		$testContext = new DerivativeContext( $specialRequestWiki->getContext() );

		$testContext->setUser( $this->getTestUser()->getUser() );
		$specialRequestWiki->setContext( $testContext );

		$this->assertNull( $specialRequestWiki->execute( '' ) );
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields() {
		$hookRunner = $this->createMock( CreateWikiHookRunner::class );

		$specialRequestWiki = TestingAccessWrapper::newFromObject(
			new SpecialRequestWiki( $hookRunner )
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
			[
				[
					'reason' => 'Test onSubmit()',
					'subdomain' => 'example',
					'sitename' => 'Example Wiki',
					'language' => 'en',
					'category' => 'uncategorised',
				],
				false,
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

		$hookRunner = $this->createMock( CreateWikiHookRunner::class );
		$specialRequestWiki = new SpecialRequestWiki( $hookRunner );

		$submitData = $specialRequestWiki->onSubmit( $formData );

		$this->assertSame( $expected, $submitData );
	}
}
