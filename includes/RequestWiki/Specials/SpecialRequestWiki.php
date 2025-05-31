<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use ErrorPageError;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use UserBlockedError;
use function array_diff_key;
use function array_filter;
use function strlen;

class SpecialRequestWiki extends FormSpecialPage {

	private array $extraFields = [];

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly CreateWikiValidator $validator,
		private readonly WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( 'RequestWiki', 'requestwiki' );
	}

	/**
	 * @param ?string $par
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
			'ext.createwiki.requestwiki.oouiform.styles',
		] );

		$form = $this->getForm();
		if ( $form && $form->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$formDescriptor = [
			'subdomain' => [
				'type' => 'textwithbutton',
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'inline-subdomain',
				'buttondefault' => '.' . $this->getConfig()->get( ConfigNames::Subdomain ),
				'label-message' => 'requestwiki-label-subdomain',
				'placeholder-message' => 'requestwiki-placeholder-subdomain',
				'help-message' => 'createwiki-help-subdomain',
				'required' => true,
				'validation-callback' => [ $this->validator, 'validateSubdomain' ],
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L4
				'maxlength' => 64 - strlen( $this->getConfig()->get( ConfigNames::DatabaseSuffix ) ),
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'help-message' => 'createwiki-help-sitename',
				'required' => true,
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_requests.sql#L7
				'maxlength' => 128,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'requestwiki-label-language',
				'default' => 'en',
			],
		];

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'help-message' => 'createwiki-help-category',
				'options' => $this->getConfig()->get( ConfigNames::Categories ),
				'default' => 'uncategorised',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'help-message' => 'createwiki-help-private',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::ShowBiographicalOption ) ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio',
				'help-message' => 'createwiki-help-bio',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::Purposes ) ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'required' => true,
				'options' => $this->getConfig()->get( ConfigNames::Purposes ),
			];
		}

		$formDescriptor['guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-info-guidance' ),
		];

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 10,
			'minlength' => $this->getConfig()->get( ConfigNames::RequestWikiMinimumLength ) ?: false,
			'maxlength' => 4096,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'useeditfont' => true,
			'validation-callback' => [ $this->validator, 'validateReason' ],
		];

		$formDescriptor['post-reason-guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-info-guidance-post' ),
		];

		if ( $this->getConfig()->get( ConfigNames::RequestWikiConfirmAgreement ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'validation-callback' => [ $this->validator, 'validateAgreement' ],
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

		return $formDescriptor;
	}

	/** @inheritDoc */
	public function onSubmit( array $data ): Status {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();

		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if ( $this->getUser()->pingLimiter( 'requestwiki' ) ) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		if ( $this->wikiRequestManager->isDuplicateRequest( $data['sitename'] ) ) {
			return Status::newFatal( 'requestwiki-error-patient' );
		}

		$extraData = [];
		foreach ( $this->extraFields as $field => $value ) {
			if ( isset( $data[$field] ) ) {
				$extraData[$field] = $data[$field];
			}
		}

		$this->wikiRequestManager->createNewRequestAndLog( $data, $extraData, $this->getUser() );

		$requestID = (string)$this->wikiRequestManager->getID();
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', $requestID );

		// On successful submission, redirect them to their request
		$this->getOutput()->redirect( $requestLink->getFullURL() );

		return Status::newGood();
	}

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
}
