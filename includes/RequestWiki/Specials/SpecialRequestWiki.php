<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use ErrorPageError;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use UserBlockedError;

class SpecialRequestWiki extends FormSpecialPage {

	private CreateWikiDatabaseUtils $databaseUtils;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;

	private array $extraFields = [];

	public function __construct(
		CreateWikiDatabaseUtils $databaseUtils,
		CreateWikiHookRunner $hookRunner,
		WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( 'RequestWiki', 'requestwiki' );

		$this->databaseUtils = $databaseUtils;
		$this->hookRunner = $hookRunner;
		$this->wikiRequestManager = $wikiRequestManager;
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
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/**
	 * @inheritDoc
	 */
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
				'validation-callback' => [ $this, 'isValidSubdomain' ],
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'help-message' => 'createwiki-help-sitename',
				'required' => true,
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
			'validation-callback' => [ $this, 'isValidReason' ],
		];

		$formDescriptor['post-reason-guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-info-guidance-post' ),
		];

		if ( $this->getConfig()->get( ConfigNames::RequestWikiConfirmAgreement ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'required' => true,
				'validation-callback' => [ $this, 'isAgreementChecked' ],
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

	/**
	 * @inheritDoc
	 */
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

	public function isValidReason( ?string $reason ): bool|Message {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required' );
		}

		$minLength = $this->getConfig()->get( ConfigNames::RequestWikiMinimumLength );
		if ( $minLength && strlen( $reason ) < $minLength ) {
			// This will automatically call ->parse().
			return $this->msg( 'requestwiki-error-minlength' )->numParams(
				$minLength,
				strlen( $reason )
			);
		}

		$regexes = CreateWikiRegexConstraint::regexesFromMessage(
			'CreateWiki-disallowlist', '/', '/i'
		);

		foreach ( $regexes as $regex ) {
			preg_match( '/' . $regex . '/i', $reason, $output );

			if ( is_array( $output ) && count( $output ) >= 1 ) {
				return $this->msg( 'requestwiki-error-invalidcomment' );
			}
		}

		return true;
	}

	public function isValidSubdomain( ?string $subdomain ): bool|Message {
		if ( !$subdomain || ctype_space( $subdomain ) ) {
			return $this->msg( 'htmlform-required' );
		}

		$subdomain = strtolower( $subdomain );
		$configSubdomain = $this->getConfig()->get( ConfigNames::Subdomain );

		if ( strpos( $subdomain, $configSubdomain ) !== false ) {
			$subdomain = str_replace( '.' . $configSubdomain, '', $subdomain );
		}

		$disallowedSubdomains = CreateWikiRegexConstraint::regexFromArray(
			$this->getConfig()->get( ConfigNames::DisallowedSubdomains ), '/^(', ')+$/',
			ConfigNames::DisallowedSubdomains
		);

		$database = $subdomain . $this->getConfig()->get( ConfigNames::DatabaseSuffix );

		if ( in_array( $database, $this->getConfig()->get( MainConfigNames::LocalDatabases ) ) ) {
			return $this->msg( 'createwiki-error-subdomaintaken' );
		}

		if ( !ctype_alnum( $subdomain ) ) {
			return $this->msg( 'createwiki-error-notalnum' );
		}

		if ( preg_match( $disallowedSubdomains, $subdomain ) ) {
			return $this->msg( 'createwiki-error-disallowed' );
		}

		return true;
	}

	public function isAgreementChecked( bool $agreement ): bool|Message {
		if ( !$agreement ) {
			return $this->msg( 'createwiki-error-agreement' );
		}

		return true;
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

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
