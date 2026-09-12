<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Html\Html;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiParsedMessageCache;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use function array_diff_key;
use function array_filter;
use function explode;
use function in_array;
use function strlen;
use function trim;

class RequestWikiFormDescriptorBuilder {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::Categories,
		ConfigNames::DatabaseSuffix,
		ConfigNames::Purposes,
		ConfigNames::RequestWikiConfirmAgreement,
		ConfigNames::RequestWikiMinimumLength,
		ConfigNames::ShowBiographicalOption,
		ConfigNames::Subdomain,
		ConfigNames::UsePrivateWikis,
	];

	public function __construct(
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly CreateWikiParsedMessageCache $parsedMessageCache,
		private readonly CreateWikiValidator $validator,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/** @return array{descriptor: array, extraFields: array} */
	public function build(): array {
		$formDescriptor = [
			'wizard-intro' => [
				'type' => 'info',
				'raw' => true,
				'default' => $this->parsedMessageCache->parseAsBlock(
					$this->messageLocalizer->msg( 'requestwiki-wizard-intro' )
				),
				'section' => 'intro',
			],
			'subdomain' => [
				'type' => 'textwithbutton',
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'ext-createwiki-inline-subdomain',
				'buttondefault' => '.' . $this->options->get( ConfigNames::Subdomain ),
				'label-message' => 'requestwiki-label-subdomain',
				'placeholder-message' => 'requestwiki-placeholder-subdomain',
				'help-message' => 'createwiki-help-subdomain',
				'required' => true,
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'validation-callback' => [ $this->validator, 'validateSubdomain' ],
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L4
				'maxlength' => 64 - strlen( $this->options->get( ConfigNames::DatabaseSuffix ) ),
				'section' => 'basics',
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'help-message' => 'createwiki-help-sitename',
				'required' => true,
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L7
				'maxlength' => 128,
				'section' => 'basics',
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'requestwiki-label-language',
				'default' => 'en',
				'section' => 'basics',
			],
		];

		if ( $this->options->get( ConfigNames::Categories ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'help-message' => 'createwiki-help-category',
				'required' => true,
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'options' => $this->options->get( ConfigNames::Categories ),
				'section' => 'basics',
			];
		}

		if ( $this->options->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'help-message' => 'createwiki-help-private',
				'section' => 'options',
			];
		}

		if ( $this->options->get( ConfigNames::ShowBiographicalOption ) ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio',
				'help-message' => 'createwiki-help-bio',
				'section' => 'options',
			];
		}

		if ( $this->options->get( ConfigNames::Purposes ) ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'required' => true,
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'options' => $this->options->get( ConfigNames::Purposes ),
				'section' => 'options',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 10,
			'minlength' => $this->options->get( ConfigNames::RequestWikiMinimumLength ) ?: false,
			'maxlength' => 4096,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'useeditfont' => true,
			'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
			'validation-callback' => [ $this->validator, 'validateReason' ],
			'section' => 'details',
		];

		$formDescriptor['wizard-review'] = [
			'type' => 'info',
			'raw' => true,
			'default' => Html::element(
				'h3',
				[ 'class' => 'ext-createwiki-wizard-review-heading' ],
				$this->messageLocalizer->msg( 'requestwiki-wizard-review-heading' )->text()
			) . Html::element( 'div', [ 'class' => 'ext-createwiki-wizard-review' ] ),
			'section' => 'review',
		];

		if ( $this->options->get( ConfigNames::RequestWikiConfirmAgreement ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'validation-callback' => [ $this->validator, 'validateAgreement' ],
				'required' => true,
				'section' => 'review',
			];
		}

		// We store the original formDescriptor here so we
		// can find any extra fields added via hook. We do this
		// so we can store to the extraFields property and differentiate
		// if we should store via cw_extra in onSubmit().
		$baseFormDescriptor = $formDescriptor;

		$this->hookRunner->onRequestWikiFormDescriptorModify( $formDescriptor );

		// We get all the keys from $formDescriptor that are absent from $baseFormDescriptor,
		// then filter out any fields where the 'save' property is set to false.
		$extraFields = array_filter(
			array_diff_key( $formDescriptor, $baseFormDescriptor ),
			static function ( array $properties ): bool {
				return ( $properties['save'] ?? null ) !== false;
			}
		);

		foreach ( $formDescriptor as &$fieldProperties ) {
			$fieldProperties['section'] ??= 'additional';
		}

		unset( $fieldProperties );
		return [ 'descriptor' => $formDescriptor, 'extraFields' => $extraFields ];
	}

	/** @return ?array{required: bool, callback: ?callable, type: string} */
	public function getRestValidationInfo( array $formDescriptor, string $field ): ?array {
		if ( !isset( $formDescriptor[$field] ) ) {
			return null;
		}

		$fieldDescriptor = $formDescriptor[$field];
		$cssClasses = explode( ' ', trim( $fieldDescriptor['cssclass'] ?? '' ) );
		if ( !in_array( RequestWikiWizardForm::REST_VALIDATE_CLASS, $cssClasses, true ) ) {
			return null;
		}

		return [
			'required' => (bool)( $fieldDescriptor['required'] ?? false ),
			'callback' => $fieldDescriptor['validation-callback'] ?? null,
			'type' => $fieldDescriptor['type'] ?? '',
		];
	}
}
