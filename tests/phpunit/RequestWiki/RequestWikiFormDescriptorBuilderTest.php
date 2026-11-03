<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
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

	private function newBuilder(): RequestWikiFormDescriptorBuilder {
		$services = $this->getServiceContainer();
		return new RequestWikiFormDescriptorBuilder(
			$services->get( 'CreateWikiHookRunner' ),
			$services->get( 'CreateWikiParsedMessageCache' ),
			$services->get( 'CreateWikiValidator' ),
			RequestContext::getMain(),
			new ServiceOptions(
				RequestWikiFormDescriptorBuilder::CONSTRUCTOR_OPTIONS,
				$services->get( 'CreateWikiConfig' )
			)
		);
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$this->assertInstanceOf( RequestWikiFormDescriptorBuilder::class, $this->newBuilder() );
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

		$built = $this->newBuilder()->build();
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

		$built = $this->newBuilder()->build();
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

		$built = $this->newBuilder()->build();
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
		$builder = $this->newBuilder();
		$built = $builder->build();
		$info = $builder->getRestValidationInfo( $built['descriptor'], 'subdomain' );

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

		$builder = $this->newBuilder();
		$built = $builder->build();
		$info = $builder->getRestValidationInfo( $built['descriptor'], 'category' );

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

		$builder = $this->newBuilder();
		$built = $builder->build();
		$info = $builder->getRestValidationInfo( $built['descriptor'], 'agreement' );

		$this->assertIsArray( $info );
		$this->assertSame( 'check', $info['type'] );
		$this->assertIsCallable( $info['callback'] );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForFieldWithoutMarkerClass(): void {
		$builder = $this->newBuilder();
		$built = $builder->build();
		$this->assertNull( $builder->getRestValidationInfo( $built['descriptor'], 'sitename' ) );
		$this->assertNull( $builder->getRestValidationInfo( $built['descriptor'], 'language' ) );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForUnknownField(): void {
		$builder = $this->newBuilder();
		$built = $builder->build();
		$this->assertNull( $builder->getRestValidationInfo( $built['descriptor'], 'not-a-real-field' ) );
	}

	/**
	 * @covers ::getRestValidationInfo
	 */
	public function testGetRestValidationInfoForConditionallyAbsentField(): void {
		$this->overrideConfigValues( [
			ConfigNames::Categories => [],
		] );

		$builder = $this->newBuilder();
		$built = $builder->build();
		$this->assertNull( $builder->getRestValidationInfo( $built['descriptor'], 'category' ) );
	}
}
