<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki;

use MediaWiki\Context\RequestContext;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\RequestWiki\RequestWikiWizardForm;
use function html_entity_decode;
use function json_decode;
use function preg_match;

/**
 * @group CreateWiki
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\RequestWikiWizardForm
 */
class RequestWikiWizardFormTest extends MediaWikiIntegrationTestCase {

	private function newForm(
		array $descriptor,
		string $messagePrefix
	): RequestWikiWizardForm {
		$form = new RequestWikiWizardForm(
			$descriptor,
			RequestContext::getMain(),
			$messagePrefix
		);

		$form->prepareForm();
		return $form;
	}

	/** @return array<string, string> Decoded contents of the rendered data-field-labels attribute. */
	private function extractFieldLabels( string $html ): array {
		$this->assertMatchesRegularExpression( '/data-field-labels="([^"]*)"/', $html );
		preg_match( '/data-field-labels="([^"]*)"/', $html, $matches );
		return (array)json_decode( html_entity_decode( $matches[1] ), true );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$this->assertInstanceOf( RequestWikiWizardForm::class, $form );
	}

	/**
	 * @covers ::getButtons
	 */
	public function testGetButtonsReturnsEmptyString(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$this->assertSame( '', $form->getButtons() );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyRendersWizardMarkupForSectionedFields(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'label' => 'Field 1',
				'section' => 'stepone',
			],
			'field2' => [
				'type' => 'text',
				'label' => 'Field 2',
				'section' => 'steptwo',
			],
		], 'requestwiki' );

		$html = $form->getBody();

		$this->assertStringContainsString( 'ext-createwiki-wizard', $html );
		$this->assertStringContainsString( 'data-step-count="2"', $html );
		$this->assertStringContainsString( 'data-step="stepone"', $html );
		$this->assertStringContainsString( 'data-step="steptwo"', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-dot', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-card-title', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-step-count', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-back', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-next', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-submit', $html );
		$this->assertStringContainsString( 'mw-section-stepone', $html );
		$this->assertStringContainsString( 'mw-section-steptwo', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyIncludesSubmitNameAndId(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$form->setSubmitName( 'mysubmitname' );
		$form->setSubmitID( 'mysubmitid' );

		$html = $form->getBody();

		$this->assertStringContainsString( 'mysubmitname', $html );
		$this->assertStringContainsString( 'mysubmitid', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyFallsBackToParentWhenNoFieldHasSection(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'label' => 'Field 1',
			],
		], 'requestwiki' );

		$html = $form->getBody();
		$this->assertStringNotContainsString( 'ext-createwiki-wizard', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyIncludesInlineStyleToPreventFlash(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$html = $form->getBody();

		$this->assertStringContainsString( '<style>', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-back', $html );
		$this->assertStringContainsString( 'display:none', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyIncludesFormHeaderHtml(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$form->addHeaderHtml( '<p>Custom header content</p>' );

		$html = $form->getBody();
		$this->assertStringContainsString( 'Custom header content', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyIncludesSubtitleWhenMessageExists(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'details',
			],
		], 'requestwiki' );

		$html = $form->getBody();
		$this->assertStringContainsString( 'ext-createwiki-wizard-card-subtitle', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyOmitsSubtitleWhenMessageDoesNotExist(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'nonexistentsectionxyz',
			],
		], 'requestwiki' );

		$html = $form->getBody();
		$this->assertStringNotContainsString( 'ext-createwiki-wizard-card-subtitle', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getStepSubtitle
	 * @covers ::getWizardDots
	 * @covers ::getWizardNav
	 */
	public function testGetBodyOmitsSubtitleWhenMessagePrefixIsEmpty(): void {
		$form = $this->newForm( [
			'field1' => [
				'type' => 'text',
				'section' => 'details',
			],
		], '' );

		$html = $form->getBody();
		$this->assertStringNotContainsString( 'ext-createwiki-wizard-card-subtitle', $html );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getReviewFieldLabels
	 */
	public function testGetBodyFieldLabelsFallsBackToFieldLabelWhenNoReviewLabelExists(): void {
		$form = $this->newForm( [
			'customhookfield' => [
				'type' => 'text',
				'label' => 'Custom Hook Field',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$labels = $this->extractFieldLabels( $form->getBody() );
		$this->assertSame( 'Custom Hook Field', $labels['wpcustomhookfield'] );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getReviewFieldLabels
	 */
	public function testGetBodyFieldLabelsPrefersDedicatedReviewLabelOverFieldLabel(): void {
		$form = $this->newForm( [
			'subdomain' => [
				'type' => 'text',
				'label' => 'Please enter your desired subdomain here',
				'section' => 'stepone',
			],
		], 'requestwiki' );

		$labels = $this->extractFieldLabels( $form->getBody() );
		$this->assertSame( 'Subdomain', $labels['wpsubdomain'] );
	}

	/**
	 * @covers ::getBody
	 * @covers ::getReviewFieldLabels
	 */
	public function testGetBodyFieldLabelsExcludePseudoFields(): void {
		$form = $this->newForm( [
			'wizard-intro' => [
				'type' => 'info',
				'raw' => true,
				'default' => 'Intro text',
				'section' => 'intro',
			],
			'field1' => [
				'type' => 'text',
				'label' => 'Field 1',
				'section' => 'stepone',
			],
			'wizard-review' => [
				'type' => 'info',
				'raw' => true,
				'default' => 'Review text',
				'section' => 'agreement',
			],
		], 'requestwiki' );

		$labels = $this->extractFieldLabels( $form->getBody() );

		$this->assertArrayNotHasKey( 'wpwizard-intro', $labels );
		$this->assertArrayNotHasKey( 'wpwizard-review', $labels );
		$this->assertArrayHasKey( 'wpfield1', $labels );
	}
}
