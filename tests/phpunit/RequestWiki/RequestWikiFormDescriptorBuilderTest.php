<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki;

use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormDescriptorBuilder;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\RequestWikiFormDescriptorBuilder
 */
class RequestWikiFormDescriptorBuilderTest extends MediaWikiIntegrationTestCase {

	private RequestWikiFormDescriptorBuilder $builder;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->builder = $services->get( 'RequestWikiFormDescriptorBuilder' );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( RequestWikiFormDescriptorBuilder::class, $this->builder );
	}

	/**
	 * @covers ::build
	 */
	public function testBuild(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [ 'test' => 'test' ],
			ConfigNames::Purposes => [ 'test' => 'test' ],
			ConfigNames::RequestWikiConfirmAgreement => true,
			ConfigNames::ShowBiographicalOption => true,
			ConfigNames::UsePrivateWikis => true,
		] );

		$built = $this->builder->build();
		$this->assertArrayHasKey( 'descriptor', $built );
		$this->assertArrayHasKey( 'extraFields', $built );

		$descriptor = $built['descriptor'];
		$this->assertArrayHasKey( 'agreement', $descriptor );
		$this->assertArrayHasKey( 'bio', $descriptor );
		$this->assertArrayHasKey( 'category', $descriptor );
		$this->assertArrayHasKey( 'language', $descriptor );
		$this->assertArrayHasKey( 'private', $descriptor );
		$this->assertArrayHasKey( 'purpose', $descriptor );
		$this->assertArrayHasKey( 'reason', $descriptor );
		$this->assertArrayHasKey( 'sitename', $descriptor );
		$this->assertArrayHasKey( 'subdomain', $descriptor );
		$this->assertArrayHasKey( 'wizard-intro', $descriptor );
		$this->assertArrayHasKey( 'wizard-review', $descriptor );
	}

	/**
	 * @covers ::build
	 */
	public function testBuildWithoutOptionalConfig(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [],
			ConfigNames::Purposes => [],
			ConfigNames::RequestWikiConfirmAgreement => false,
			ConfigNames::ShowBiographicalOption => false,
			ConfigNames::UsePrivateWikis => false,
		] );

		$built = $this->builder->build();
		$descriptor = $built['descriptor'];

		$this->assertArrayNotHasKey( 'agreement', $descriptor );
		$this->assertArrayNotHasKey( 'bio', $descriptor );
		$this->assertArrayNotHasKey( 'category', $descriptor );
		$this->assertArrayNotHasKey( 'private', $descriptor );
		$this->assertArrayNotHasKey( 'purpose', $descriptor );
	}

	/**
	 * @covers ::build
	 */
	public function testBuildAssignsDefaultSectionAndTracksExtraFields(): void {
		$this->setTemporaryHook( 'RequestWikiFormDescriptorModify', static function ( array &$formDescriptor ): void {
			$formDescriptor['extra-field'] = [
				'type' => 'text',
				'label' => 'Extra field',
			];
			$formDescriptor['unsaved-extra-field'] = [
				'type' => 'text',
				'label' => 'Unsaved extra field',
				'save' => false,
			];
		} );

		$built = $this->builder->build();
		$descriptor = $built['descriptor'];

		$this->assertArrayHasKey( 'extra-field', $descriptor );
		$this->assertSame( 'additional', $descriptor['extra-field']['section'] );

		$this->assertArrayHasKey( 'extra-field', $built['extraFields'] );
		$this->assertArrayNotHasKey( 'unsaved-extra-field', $built['extraFields'] );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForFieldWithCallback(): void {
		$built = $this->builder->build();
		$info = $this->builder->getRestValidationInfo( $built['descriptor'], 'subdomain' );

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

		$built = $this->builder->build();
		$info = $this->builder->getRestValidationInfo( $built['descriptor'], 'category' );

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

		$built = $this->builder->build();
		$info = $this->builder->getRestValidationInfo( $built['descriptor'], 'agreement' );

		$this->assertIsArray( $info );
		$this->assertSame( 'check', $info['type'] );
		$this->assertIsCallable( $info['callback'] );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForFieldWithoutMarkerClass(): void {
		$built = $this->builder->build();
		$this->assertNull( $this->builder->getRestValidationInfo( $built['descriptor'], 'sitename' ) );
		$this->assertNull( $this->builder->getRestValidationInfo( $built['descriptor'], 'language' ) );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForUnknownField(): void {
		$built = $this->builder->build();
		$this->assertNull( $this->builder->getRestValidationInfo( $built['descriptor'], 'not-a-real-field' ) );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForConditionallyAbsentField(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [],
		] );

		$built = $this->builder->build();
		$this->assertNull( $this->builder->getRestValidationInfo( $built['descriptor'], 'category' ) );
	}
}
