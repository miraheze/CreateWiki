<?php

namespace Miraheze\CreateWiki\RequestWiki;

use Config;
use ErrorPageError;
use Exception;
use ExtensionRegistry;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\EntryPointUtils;
use Title;

class SpecialRequestWiki extends FormSpecialPage {

	/** @var Config */
	private $config;
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public function __construct( CreateWikiHookRunner $hookRunner ) {
		parent::__construct( 'RequestWiki', 'requestwiki' );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
	}

	public function execute( $par ) {
		if ( !EntryPointUtils::currentWikiIsGlobalWiki() ) {
			return $this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'createwiki-wikinotglobalwiki' )->escaped() )
			);
		}
		$request = $this->getRequest();
		$out = $this->getOutput();

		$this->requireLogin( 'requestwiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$this->checkExecutePermissions( $this->getUser() );

		if ( !$this->getUser()->isEmailConfirmed() && $this->config->get( 'RequestWikiConfirmEmail' ) ) {
			throw new ErrorPageError( 'requestwiki', 'requestwiki-error-emailnotconfirmed' );
		}

		$out->addModules( [ 'mediawiki.special.userrights' ] );
		$out->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );

		$out->addWikiMsg( 'requestwiki-header' );

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	protected function getFormFields() {
		$formDescriptor = [
			'subdomain' => [
				'type' => 'textwithbutton',
				'buttontype' => 'button',
				'buttonflags' => [],
				'buttonid' => 'inline-subdomain',
				'buttondefault' => '.' . $this->config->get( 'CreateWikiSubdomain' ),
				'label-message' => 'requestwiki-label-subdomain',
				'placeholder-message' => 'requestwiki-placeholder-subdomain',
				'help-message' => 'requestwiki-help-subdomain',
				'required' => true,
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'help-message' => 'requestwiki-help-sitename',
				'required' => true,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'requestwiki-label-language',
				'default' => 'en',
			],
		];

		if ( $this->config->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->config->get( 'CreateWikiCategories' ),
				'default' => 'uncategorised',
			];
		}

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'help-message' => 'requestwiki-help-private',
			];
		}

		if ( $this->config->get( 'CreateWikiShowBiographicalOption' ) ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio',
				'help-message' => 'requestwiki-help-bio',
			];
		}

		if ( $this->config->get( 'CreateWikiPurposes' ) ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'options' => $this->config->get( 'CreateWikiPurposes' ),
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 4,
			'minlength' => $this->config->get( 'RequestWikiMinimumLength' ) ?? false,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'validation-callback' => [ $this, 'isValidReason' ],
		];

		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiDiscover' ) && $this->config->get( 'WikiDiscoverUseDescriptions' ) && $this->config->get( 'RequestWikiUseDescriptions' ) ) {
			$formDescriptor['public-description'] = [
				'type' => 'textarea',
				'rows' => 2,
				'maxlength' => $this->config->get( 'WikiDiscoverDescriptionMaxLength' ) ?? false,
				'label-message' => 'requestwiki-label-public-description',
				'help-message' => 'requestwiki-help-public-description',
				'required' => true,
				'validation-callback' => [ $this, 'isValidReason' ],
			];
		}

		if ( $this->config->get( 'RequestWikiConfirmAgreement' ) ) {
			$formDescriptor['agreement'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-agreement',
				'help-message' => 'requestwiki-help-agreement',
				'required' => true,
			];
		}

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		$request = new WikiRequest( null, $this->hookRunner );
		$subdomain = strtolower( $formData['subdomain'] );
		$out = $this->getOutput();
		$err = '';

		$status = $request->parseSubdomain( $subdomain, $err );
		if ( $status === false ) {
			if ( $err !== '' ) {
				$out->addHTML(
					Html::warningBox(
						Html::rawElement(
							'p',
							[],
							$this->msg( 'createwiki-error-' . $err )->parse()
						),
						'mw-notify-error'
					)
				);
			}

			return false;
		}

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

		$idlink = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( Title::newFromText( 'Special:RequestWikiQueue/' . $requestID ), "#{$requestID}" );

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

	public function isValidReason( $reason, $allData ) {
		$regexes = CreateWikiRegexConstraint::regexesFromMessage( 'CreateWiki-disallowlist' );

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

	protected function getDisplayFormat() {
		return 'ooui';
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
