<?php

namespace Miraheze\CreateWiki\RequestWiki;

use ErrorPageError;
use Exception;
use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use StatusValue;

class SpecialRequestWiki extends FormSpecialPage {

	public function __construct() {
		parent::__construct( 'RequestWiki', 'requestwiki' );
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

		if ( !$this->getUser()->isEmailConfirmed() && $this->getConfig()->get( 'RequestWikiConfirmEmail' ) ) {
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
		$request = new WikiRequest( id: null );

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
				'validation-callback' => [ $request, 'parseSubdomain' ],
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
	public function onSubmit( array $formData ): bool {
		$request = new WikiRequest( id: null );
		$subdomain = strtolower( $formData['subdomain'] );
		$out = $this->getOutput();

		$request->dbname = $subdomain . $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );
		$request->url = $subdomain . '.' . $this->getConfig()->get( 'CreateWikiSubdomain' );
		$request->description = $formData['reason'];
		$request->sitename = $formData['sitename'];
		$request->language = $formData['language'];
		$request->private = $formData['private'] ?? 0;
		$request->requester = $this->getUser();
		$request->category = $formData['category'] ?? '';
		$request->purpose = $formData['purpose'] ?? '';
		$request->bio = $formData['bio'] ?? 0;

		try {
			$requestID = $request->save();
		} catch ( Exception $e ) {
			$out->addHTML(
				Html::warningBox(
					Html::element(
						'p',
						[],
						$this->msg( 'requestwiki-error-patient' )->plain()
					),
					'mw-notify-error'
				)
			);

			return false;
		}

		$idlink = $this->getLinkRenderer()->makeLink( Title::newFromText( 'Special:RequestWikiQueue/' . $requestID ), "#{$requestID}" );

		$farmerLogEntry = new ManualLogEntry( 'farmer', 'requestwiki' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getPageTitle() );
		$farmerLogEntry->setComment( $formData['reason'] );
		$farmerLogEntry->setParameters(
			[
				'4::sitename' => $formData['sitename'],
				'5::language' => $formData['language'],
				'6::private' => (int)( $formData['private'] ?? 0 ),
				'7::id' => "#{$requestID}",
			]
		);

		$farmerLogID = $farmerLogEntry->insert();
		$farmerLogEntry->publish( $farmerLogID );

		// On successful request, redirect them to their request
		header( 'Location: ' . FormSpecialPage::getTitleFor( 'RequestWikiQueue' )->getFullURL() . '/' . $requestID );

		return true;
	}

	public function isValidReason( ?string $reason ): bool|string {
		$regexes = CreateWikiRegexConstraint::regexesFromMessage(
			'CreateWiki-disallowlist', '/', '/i'
		);

		foreach ( $regexes as $regex ) {
			preg_match( '/' . $regex . '/i', $reason, $output );

			if ( is_array( $output ) && count( $output ) >= 1 ) {
				return $this->msg( 'requestwiki-error-invalidcomment' )->escaped();
			}
		}

		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	public function isAgreementChecked( bool $agreement ): bool|StatusValue {
		if ( !$agreement ) {
			return StatusValue::newFatal( 'createwiki-error-agreement' );
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
