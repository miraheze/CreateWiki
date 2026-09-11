<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\OOUIHTMLForm;
use MediaWiki\Logger\LoggerFactory;
use OOUI\ButtonInputWidget;
use function count;
use function is_array;
use function json_encode;
use function strip_tags;
use function trim;

class RequestWikiWizardForm extends OOUIHTMLForm {

	public const string REST_VALIDATE_CLASS = 'ext-createwiki-wizard-rest-validate';

	/** @var bool Override default value from HTMLForm */
	protected $mSubSectionBeforeFields = false;

	/** @inheritDoc */
	public function getButtons() {
		return '';
	}

	/** @return string */
	public function getBody() {
		$stepKeys = [];
		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				LoggerFactory::getInstance( 'CreateWiki' )->debug(
					'Encountered a field not attached to a section: {key}',
					[ 'key' => $key ]
				);
				continue;
			}

			$stepKeys[] = $key;
		}

		if ( !$stepKeys ) {
			return parent::getBody();
		}

		$total = count( $stepKeys );
		$pagesHtml = '';

		foreach ( $stepKeys as $index => $key ) {
			$label = $this->getLegend( $key );
			$subtitle = $this->getStepSubtitle( $key );

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection(
					$this->mFieldTree[$key],
					'',
					"mw-section-$key-"
				) .
				$this->getFooterHtml( $key );

			$stepCount = $this->msg( 'requestwiki-wizard-step-number' )
				->numParams( $index + 1, $total )
				->text();

			$pagesHtml .= Html::rawElement(
				'div',
				[ 'class' => 'ext-createwiki-wizard-step', 'data-step' => $key ],
				Html::rawElement(
					'div',
					[ 'class' => 'ext-createwiki-wizard-card' ],
					Html::element( 'p', [ 'class' => 'ext-createwiki-wizard-step-count' ], $stepCount ) .
					Html::element( 'h2', [ 'class' => 'ext-createwiki-wizard-card-title' ], $label ) .
					$subtitle .
					Html::rawElement(
						'div',
						[ 'class' => 'ext-createwiki-wizard-card-body', 'id' => "mw-section-$key" ],
						$content
					)
				)
			);
		}

		return $this->formatFormHeader() . Html::rawElement(
			'div',
			[
				'class' => 'ext-createwiki-wizard',
				'data-step-count' => (string)$total,
				'data-field-labels' => json_encode( $this->getReviewFieldLabels() ),
			],
			$this->getWizardDots( $total ) .
			Html::rawElement( 'div', [ 'class' => 'ext-createwiki-wizard-pages' ], $pagesHtml ) .
			$this->getWizardNav()
		);
	}

	/** @return array<string, string> Map of form field name to its review summary label. */
	private function getReviewFieldLabels(): array {
		$labels = [];
		foreach ( $this->mFlatFields as $fieldName => $field ) {
			if ( $fieldName === 'wizard-intro' || $fieldName === 'wizard-review' ) {
				continue;
			}

			$reviewLabelMsg = $this->msg( "requestwiki-wizard-review-label-$fieldName" );
			if ( !$reviewLabelMsg->isDisabled() ) {
				$labels["wp$fieldName"] = $reviewLabelMsg->text();
				continue;
			}

			$labels["wp$fieldName"] = trim( strip_tags( $field->getLabel() ) );
		}

		return $labels;
	}

	private function getStepSubtitle( string $key ): string {
		if ( !$this->mMessagePrefix ) {
			return '';
		}

		$subtitleMsg = $this->msg( "{$this->mMessagePrefix}-$key-subtitle" );
		if ( !$subtitleMsg->exists() ) {
			return '';
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-createwiki-wizard-card-subtitle' ],
			$subtitleMsg->parseAsBlock()
		);
	}

	private function getWizardDots( int $total ): string {
		$dots = '';
		for ( $i = 0; $i < $total; $i++ ) {
			$classes = [ 'ext-createwiki-wizard-dot' ];
			if ( $i === 0 ) {
				$classes[] = 'ext-createwiki-wizard-dot--current';
			}

			$dots .= Html::element( 'span', [ 'class' => $classes ] );
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-createwiki-wizard-dots', 'aria-hidden' => 'true' ],
			$dots
		);
	}

	private function getWizardNav(): string {
		$back = new ButtonInputWidget( [
			'classes' => [ 'ext-createwiki-wizard-back' ],
			'type' => 'button',
			'label' => $this->msg( 'requestwiki-wizard-back' )->text(),
		] );

		$returnToReview = new ButtonInputWidget( [
			'classes' => [ 'ext-createwiki-wizard-return-review' ],
			'type' => 'button',
			'label' => $this->msg( 'requestwiki-wizard-review-return' )->text(),
			'framed' => false,
			'flags' => [ 'progressive' ],
		] );

		$next = new ButtonInputWidget( [
			'classes' => [ 'ext-createwiki-wizard-next' ],
			'type' => 'button',
			'label' => $this->msg( 'requestwiki-wizard-start' )->text(),
			'flags' => [ 'primary', 'progressive' ],
		] );

		$submitAttribs = [
			'classes' => [ 'ext-createwiki-wizard-submit' ],
			'type' => 'submit',
			'label' => $this->getSubmitText(),
			'value' => $this->getSubmitText(),
			'flags' => $this->mSubmitFlags,
		];

		if ( $this->mSubmitName !== null ) {
			$submitAttribs['name'] = $this->mSubmitName;
		}

		if ( $this->mSubmitID !== null ) {
			$submitAttribs['id'] = $this->mSubmitID;
		}

		$submit = new ButtonInputWidget( $submitAttribs );
		return Html::rawElement(
			'div',
			[ 'class' => 'ext-createwiki-wizard-nav' ],
			(string)$back . (string)$returnToReview . (string)$next . (string)$submit
		);
	}
}
