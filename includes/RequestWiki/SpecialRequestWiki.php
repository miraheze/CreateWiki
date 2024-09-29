<?php

namespace Miraheze\CreateWiki\RequestWiki;

use ErrorPageError;
use Exception;
use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Status;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestWiki extends FormSpecialPage {

	private IConnectionProvider $connectionProvider;

	public function __construct( IConnectionProvider $connectionProvider ) {
		parent::__construct( 'RequestWiki', 'requestwiki' );
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( 'CreateWikiGlobalWiki' ) ) ) {
			throw new ErrorPageError( 'createwiki-wikinotglobalwiki', 'createwiki-wikinotglobalwiki' );
		}

		$request = $this->getRequest();
		$out = $this->getOutput();

		$this->requireLogin( 'requestwiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$this->checkExecutePermissions( $this->getUser() );

		if ( $this->getConfig()->get( 'RequestWikiConfirmEmail' ) && !$this->getUser()->isEmailConfirmed() ) {
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
				'buttondefault' => '.' . $this->getConfig()->get( 'CreateWikiSubdomain' ),
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

		if ( $this->getConfig()->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'help-message' => 'createwiki-help-category',
				'options' => $this->getConfig()->get( 'CreateWikiCategories' ),
				'default' => 'uncategorised',
			];
		}

		if ( $this->getConfig()->get( 'CreateWikiUsePrivateWikis' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'help-message' => 'createwiki-help-private',
			];
		}

		if ( $this->getConfig()->get( 'CreateWikiShowBiographicalOption' ) ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio',
				'help-message' => 'createwiki-help-bio',
			];
		}

		if ( $this->getConfig()->get( 'CreateWikiPurposes' ) ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'help-message' => 'createwiki-help-purpose',
				'options' => $this->getConfig()->get( 'CreateWikiPurposes' ),
			];
		}

		$formDescriptor['guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-label-guidance' ),
		];

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 8,
			'minlength' => $this->getConfig()->get( 'RequestWikiMinimumLength' ) ?: false,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'validation-callback' => [ $this, 'isValidReason' ],
		];

		$formDescriptor['post-reason-guidance'] = [
			'type' => 'info',
			'default' => $this->msg( 'requestwiki-label-guidance-post' ),
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiDiscover' ) && $this->getConfig()->get( 'WikiDiscoverUseDescriptions' ) && $this->getConfig()->get( 'RequestWikiUseDescriptions' ) ) {
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

		if ( $this->getConfig()->get( 'RequestWikiConfirmAgreement' ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'help-message' => 'requestwiki-help-agreement',
				'required' => true,
				'validation-callback' => [ $this, 'isAgreementChecked' ],
			];
		}

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
			$this->getConfig()->get( 'CreateWikiGlobalWiki' )
		)

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
		$dbname = $subdomain . $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );
		$url = $subdomain . '.' . $this->getConfig()->get( 'CreateWikiSubdomain' );

		$comment = $data['reason'];
		if ( $this->getConfig()->get( 'CreateWikiPurposes' ) && ( $data['purpose'] ?? '' ) ) {
			$comment = implode( "\n", [ 'Purpose: ' . $data['purpose'], $data['reason'] ] );
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'import_requests' )
			->ignore()
			->row( [
				'cw_comment' => $comment,
				'cw_dbname' => $dbname,
				'cw_language' => $data['language'],
				'cw_private' => $data['private'] ?? 0,
				'cw_status' => 'inreview',
				'cw_sitename' => $data['sitename'],
				'cw_timestamp' => $dbw->timestamp(),
				'cw_url' => $this->url,
				'cw_user' => $this->requester->getId(),
				'cw_category' => $data['category'] ?? '',
				'cw_visibility' => 0,
				'cw_bio' => $data['bio'] ?? 0,
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
		$configSubdomain = $this->getConfig()->get( 'CreateWikiSubdomain' );

		if ( strpos( $subdomain, $configSubdomain ) !== false ) {
			$subdomain = str_replace( '.' . $configSubdomain, '', $subdomain );
		}

		$disallowedSubdomains = CreateWikiRegexConstraint::regexFromArrayOrString(
			$this->config->get( 'CreateWikiDisallowedSubdomains' ), '/^(', ')+$/',
			'CreateWikiDisallowedSubdomains'	
		);

		$database = $subdomain . $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );

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
