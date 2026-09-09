<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\UserBlockedError;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\RequestWiki\RequestWikiWizardForm;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Stats\StatsFactory;
use function array_diff_key;
use function array_filter;
use function explode;
use function in_array;
use function strlen;
use function trim;
use function version_compare;
use const MW_VERSION;

class SpecialRequestWiki extends FormSpecialPage {

	private array $extraFields = [];
	private ?array $restValidationFormFields = null;

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly CreateWikiValidator $validator,
		private readonly StatsFactory $statsFactory,
		private readonly WikiRequestManager $wikiRequestManager,
	) {
		if ( version_compare( MW_VERSION, '1.46', '>=' ) ) {
			parent::__construct( 'RequestWiki' );
		} else {
			parent::__construct( 'RequestWiki', 'requestwiki' );
		}
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->requireNamedUser( 'requestwiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		$requiresConfirmedEmail = $this->getConfig()->get( ConfigNames::RequestWikiConfirmEmail );
		if ( $requiresConfirmedEmail && !$this->getUser()->isEmailConfirmed() ) {
			throw new ErrorPageError( 'requestwiki', 'requestwiki-error-emailnotconfirmed' );
		}

		$this->checkPermissions();

		$this->getOutput()->addModuleStyles( [
			'ext.createwiki.requestwiki.styles',
			'ext.createwiki.requestwiki.wizard.styles',
		] );

		$this->getOutput()->addModules( [
			'ext.createwiki.requestwiki.wizard',
		] );

		if ( $this->getForm()->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$formDescriptor = [
			'wizard-intro' => [
				'type' => 'info',
				'raw' => true,
				'default' => $this->msg( 'requestwiki-wizard-intro' )->parseAsBlock(),
				'section' => 'intro',
			],
			'subdomain' => [
				'type' => 'textwithbutton',
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonclass' => 'cdx-button',
				'buttonid' => 'inline-subdomain',
				'buttondefault' => '.' . $this->getConfig()->get( ConfigNames::Subdomain ),
				'label-message' => 'requestwiki-label-subdomain',
				'placeholder-message' => 'requestwiki-placeholder-subdomain',
				'help-message' => 'createwiki-help-subdomain',
				'required' => true,
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'validation-callback' => [ $this->validator, 'validateSubdomain' ],
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L4
				'maxlength' => 64 - strlen( $this->getConfig()->get( ConfigNames::DatabaseSuffix ) ),
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

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'help-message' => 'createwiki-help-category',
				'required' => true,
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'options' => $this->getConfig()->get( ConfigNames::Categories ),
				'section' => 'basics',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'help-message' => 'createwiki-help-private',
				'section' => 'options',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::ShowBiographicalOption ) ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio',
				'help-message' => 'createwiki-help-bio',
				'section' => 'options',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::Purposes ) ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'required' => true,
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'options' => $this->getConfig()->get( ConfigNames::Purposes ),
				'section' => 'options',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 10,
			'minlength' => $this->getConfig()->get( ConfigNames::RequestWikiMinimumLength ) ?: false,
			'maxlength' => 4096,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'useeditfont' => true,
			'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
			'validation-callback' => [ $this->validator, 'validateReason' ],
			'section' => 'details',
		];

		if ( $this->getConfig()->get( ConfigNames::RequestWikiConfirmAgreement ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'cssclass' => RequestWikiWizardForm::REST_VALIDATE_CLASS,
				'validation-callback' => [ $this->validator, 'validateAgreement' ],
				'required' => true,
				'section' => 'agreement',
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
		$this->extraFields = array_filter(
			array_diff_key( $formDescriptor, $baseFormDescriptor ),
			static function ( array $properties ): bool {
				return ( $properties['save'] ?? null ) !== false;
			}
		);

		foreach ( $formDescriptor as &$fieldProperties ) {
			$fieldProperties['section'] ??= 'additional';
		}

		unset( $fieldProperties );
		return $formDescriptor;
	}

	/** @return ?array{required: bool, callback: ?callable, type: string} */
	public function getRestValidationInfo( string $field ): ?array {
		$this->restValidationFormFields ??= $this->getFormFields();
		$formDescriptor = $this->restValidationFormFields;
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

	public function isDuplicateRequest( string $sitename ): bool {
		return $this->wikiRequestManager->isDuplicateRequest( $sitename );
	}

	/** @inheritDoc */
	protected function getForm(): RequestWikiWizardForm {
		$form = new RequestWikiWizardForm(
			$this->getFormFields(),
			$this->getContext(),
			$this->getMessagePrefix()
		);

		$form->setSubmitCallback( [ $this, 'onSubmit' ] );

		$headerMsg = $this->msg( $this->getMessagePrefix() . '-text' );
		if ( !$headerMsg->isDisabled() ) {
			$form->addHeaderHtml( $headerMsg->parseAsBlock() );
		}

		$form->addPreHtml( $this->preHtml() );
		$form->addPostHtml( $this->postHtml() );
		$form->setTitle( $this->getPageTitle() );

		$this->alterForm( $form );
		return $form;
	}

	/** @inheritDoc */
	public function onSubmit( array $data ): Status {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();

		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if ( $this->getUser()->pingLimiter( 'requestwiki' ) ) {
			$this->statsFactory->getCounter( 'requestwiki_throttled_total' )->increment();
			return Status::newFatal( 'actionthrottledtext' );
		}

		if ( $this->isDuplicateRequest( $data['sitename'] ) ) {
			return Status::newFatal( 'requestwiki-error-patient' );
		}

		$extraData = [];
		foreach ( $this->extraFields as $field => $_ ) {
			if ( isset( $data[$field] ) ) {
				$extraData[$field] = $data[$field];
			}
		}

		$this->wikiRequestManager->createNewRequestAndLog( $data, $extraData, $this->getUser() );
		return Status::newGood();
	}

	/** @inheritDoc */
	public function onSuccess(): void {
		$requestId = (string)$this->wikiRequestManager->getId();
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', $requestId );

		$this->getOutput()->redirect( $requestLink->getFullURL() );
		$this->statsFactory->getCounter( 'requestwiki_requests_total' )->increment();
	}

	/** @throws UserBlockedError */
	public function checkPermissions(): void {
		parent::checkPermissions();

		$user = $this->getUser();
		$block = $user->getBlock();
		if ( $block && ( $block->isSitewide() || $block->appliesToRight( 'requestwiki' ) ) ) {
			throw new UserBlockedError( $block, $user );
		}

		$this->checkReadOnly();
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'requestwiki';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}
}
