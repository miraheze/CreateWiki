<?php

namespace Miraheze\CreateWiki\RequestWiki;

use ErrorPageError;
use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use RuntimeException;
use Status;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestWiki extends FormSpecialPage {

	private IConnectionProvider $connectionProvider;
	private CreateWikiHookRunner $hookRunner;

	private array $extraFields = [];

	public function __construct(
		IConnectionProvider $connectionProvider,
		CreateWikiHookRunner $hookRunner
	) {
		parent::__construct( 'RequestWiki', 'requestwiki' );

		$this->connectionProvider = $connectionProvider;
		$this->hookRunner = $hookRunner;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( ConfigNames::GlobalWiki ) ) ) {
			throw new ErrorPageError( 'createwiki-wikinotglobalwiki', 'createwiki-wikinotglobalwiki' );
		}

		$request = $this->getRequest();
		$out = $this->getOutput();

		$this->requireLogin( 'requestwiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$this->checkExecutePermissions( $this->getUser() );

		if (
			$this->getConfig()->get( ConfigNames::RequestWikiConfirmEmail ) &&
			!$this->getUser()->isEmailConfirmed()
		) {
			throw new ErrorPageError( 'requestwiki', 'requestwiki-error-emailnotconfirmed' );
		}

		$out->addModules( [ 'mediawiki.special.userrights' ] );
		$out->addModuleStyles( [ 'mediawiki.notification.convertmessagebox.styles' ] );

		$out->addWikiMsg( 'requestwiki-header' );

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
				'help-message' => 'createwiki-help-purpose',
				'options' => $this->getConfig()->get( ConfigNames::Purposes ),
			];
		}

		$formDescriptor['guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-label-guidance' ),
		];

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 6,
			'minlength' => $this->getConfig()->get( ConfigNames::RequestWikiMinimumLength ) ?: false,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'validation-callback' => [ $this, 'isValidReason' ],
		];

		$formDescriptor['post-reason-guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-label-guidance-post' ),
		];

		if (
			ExtensionRegistry::getInstance()->isLoaded( 'WikiDiscover' ) &&
			$this->getConfig()->get( 'WikiDiscoverUseDescriptions' ) &&
			$this->getConfig()->get( ConfigNames::RequestWikiUseDescriptions )
		) {
			$formDescriptor['public-description'] = [
				'type' => 'textarea',
				'rows' => 2,
				'maxlength' => $this->getConfig()->get( 'WikiDiscoverDescriptionMaxLength' ) ?? false,
				'label-message' => 'requestwiki-label-public-description',
				'help-message' => 'requestwiki-help-public-description',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			];
		}

		if ( $this->getConfig()->get( ConfigNames::RequestWikiConfirmAgreement ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'help-message' => 'requestwiki-help-agreement',
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

		// We get all the keys from $formDescriptor whose keys are
		// absent from $baseFormDescriptor.
		$this->extraFields = array_diff_key( $formDescriptor, $baseFormDescriptor );

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

		$dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->getConfig()->get( ConfigNames::GlobalWiki )
		);

		$duplicate = $dbw->newSelectQueryBuilder()
			->table( 'cw_requests' )
			->field( '*' )
			->where( [
				'cw_comment' => $data['reason'],
				'cw_status' => 'inreview',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( (bool)$duplicate ) {
			return Status::newFatal( 'requestwiki-error-patient' );
		}

		$subdomain = strtolower( $data['subdomain'] );
		$dbname = $subdomain . $this->getConfig()->get( ConfigNames::DatabaseSuffix );
		$url = $subdomain . '.' . $this->getConfig()->get( ConfigNames::Subdomain );

		$comment = $data['reason'];
		if ( $this->getConfig()->get( ConfigNames::Purposes ) && ( $data['purpose'] ?? '' ) ) {
			$comment = implode( "\n", [ 'Purpose: ' . $data['purpose'], $data['reason'] ] );
		}

		$extraData = [];
		foreach ( $this->extraFields as $field => $value ) {
			if ( $data[$field] ?? false ) {
				$extraData[$field] = $data[$field];
			}
		}

		$jsonExtra = json_encode( $extraData );
		if ( $jsonExtra === false ) {
			throw new RuntimeException( 'Can not set invalid JSON data to cw_extra.' );
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'cw_requests' )
			->ignore()
			->row( [
				'cw_comment' => $comment,
				'cw_dbname' => $dbname,
				'cw_language' => $data['language'],
				'cw_private' => $data['private'] ?? 0,
				'cw_status' => 'inreview',
				'cw_sitename' => $data['sitename'],
				'cw_timestamp' => $dbw->timestamp(),
				'cw_url' => $url,
				'cw_user' => $this->getUser()->getId(),
				'cw_category' => $data['category'] ?? '',
				'cw_visibility' => 0,
				'cw_bio' => $data['bio'] ?? 0,
				'cw_extra' => $jsonExtra,
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = (string)$dbw->insertId();
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', $requestID );

		$logEntry = new ManualLogEntry( 'farmer', 'requestwiki' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle() );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::sitename' => $data['sitename'],
				'5::language' => $data['language'],
				'6::private' => (int)( $data['private'] ?? 0 ),
				'7::id' => "#{$requestID}",
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		// On successful submission, redirect them to their request
		header( 'Location: ' . $requestLink->getFullURL() );

		return Status::newGood();
	}

	public function isValidReason( ?string $reason ): bool|Message {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required' );
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

		$disallowedSubdomains = CreateWikiRegexConstraint::regexFromArrayOrString(
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
