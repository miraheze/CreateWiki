<?php

namespace Miraheze\CreateWiki\Tests;

use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RequestWiki\SpecialRequestWiki;

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
	public function testExecute() {
		$hookRunner = $this->createMock( CreateWikiHookRunner::class );
		$specialRequestWiki = new SpecialRequestWiki( $hookRunner );

		$this->assertNull( $specialRequestWiki->execute( '' ) );
	}

	/**
	 * @covers ::getFormFields
	 */
	public function testGetFormFields() {
		$hookRunner = $this->createMock( CreateWikiHookRunner::class );
		$specialRequestWiki = new SpecialRequestWiki( $hookRunner );

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
		$hookRunner = $this->createMock( CreateWikiHookRunner::class );
		$specialRequestWiki = new SpecialRequestWiki( $hookRunner );

		$submitData = $specialRequestWiki->onSubmit( $formData );

		$this->assertSame( $expected, $submitData );
	}
}
