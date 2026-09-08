<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki;

use MediaWiki\Context\RequestContext;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\RequestWiki\RequestWikiWizardForm;

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

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'stepone' ],
		], 'requestwiki' );

		$this->assertInstanceOf( RequestWikiWizardForm::class, $form );
	}

	/**
	 * @covers ::getButtons
	 */
	public function testGetButtonsReturnsEmptyString(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'stepone' ],
		], 'requestwiki' );

		$this->assertSame( '', $form->getButtons() );
	}

	/**
	 * @covers ::getBody
	 */
	public function testGetBodyRendersWizardMarkupForSectionedFields(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'label' => 'Field 1', 'section' => 'stepone' ],
			'field2' => [ 'type' => 'text', 'label' => 'Field 2', 'section' => 'steptwo' ],
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
	 */
	public function testGetBodyFallsBackToParentWhenNoFieldHasSection(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'label' => 'Field 1' ],
		], 'requestwiki' );

		$html = $form->getBody();
		$this->assertStringNotContainsString( 'ext-createwiki-wizard', $html );
	}

	/**
	 * @covers ::getBody
	 */
	public function testGetBodyIncludesInlineStyleToPreventFlash(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'stepone' ],
		], 'requestwiki' );

		$html = $form->getBody();

		$this->assertStringContainsString( '<style>', $html );
		$this->assertStringContainsString( 'ext-createwiki-wizard-back', $html );
		$this->assertStringContainsString( 'display:none', $html );
	}

	/**
	 * @covers ::getBody
	 */
	public function testGetBodyIncludesFormHeaderHtml(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'stepone' ],
		], 'requestwiki' );

		$form->addHeaderHtml( '<p>Custom header content</p>' );

		$html = $form->getBody();
		$this->assertStringContainsString( 'Custom header content', $html );
	}

	/**
	 * @covers ::getBody
	 */
	public function testGetBodyIncludesSubtitleWhenMessageExists(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'details' ],
		], 'requestwiki' );

		$html = $form->getBody();
		$this->assertStringContainsString( 'ext-createwiki-wizard-card-subtitle', $html );
	}

	/**
	 * @covers ::getBody
	 */
	public function testGetBodyOmitsSubtitleWhenMessageDoesNotExist(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'nonexistentsectionxyz' ],
		], 'requestwiki' );

		$html = $form->getBody();
		$this->assertStringNotContainsString( 'ext-createwiki-wizard-card-subtitle', $html );
	}

	/**
	 * @covers ::getBody
	 */
	public function testGetBodyOmitsSubtitleWhenMessagePrefixIsEmpty(): void {
		$form = $this->newForm( [
			'field1' => [ 'type' => 'text', 'section' => 'details' ],
		], '' );

		$html = $form->getBody();
		$this->assertStringNotContainsString( 'ext-createwiki-wizard-card-subtitle', $html );
	}
}
