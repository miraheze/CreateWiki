<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;

class SpecialRequestWiki extends FormSpecialPage {
	private $config;

	public function __construct() {
		parent::__construct( 'RequestWiki', 'requestwiki' );
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
	}

        public function execute( $par ) {
			$request = $this->getRequest();
			$out = $this->getOutput();
			$this->setParameter( $par );
			$this->setHeaders();

			if ( !$this->getUser()->isRegistered() ) {
				$loginurl = SpecialPage::getTitleFor( 'Userlogin' )->getFullURL( ['returnto' => $this->getPageTitle()->getPrefixedText() ] );
				$out->addWikiMsg( 'requestwiki-notloggedin', $loginurl );
				return false;
			}

			$this->checkExecutePermissions( $this->getUser() );

			if ( !$request->wasPosted() && $this->config->get( 'CreateWikiCustomDomainPage' ) ) {
				$customdomainurl = Title::newFromText( $this->config->get( 'CreateWikiCustomDomainPage' ) )->getFullURL();
				$out->addWikiMsg( 'requestwiki-header', $customdomainurl );
			}

			$form = $this->getForm();
			if ( $form->show() ) {
				$this->onSuccess();
			}
        }

	protected function getFormFields() {
		$formDescriptor = [
			'subdomain' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-siteurl',
				'required' => true,
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'required' => true,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'requestwiki-label-language',
				'default' => 'en',
			]
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
			];
		}

		if ( $this->config->get( 'CreateWikiShowBiographicalOption') ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio'
			];
		}

		if ( $this->config->get( 'CreateWikiPurposes') ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'options' => $this->config->get( 'CreateWikiPurposes')
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 4,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'validation-callback' => [ __CLASS__, 'isValidReason' ],
		];

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		$subdomain = strtolower( $formData['subdomain'] );

		if ( is_array( $this->config->get( 'CreateWikiBlacklistedSubdomains' ) ) ) {
			$subdomainBlacklist = '/^(' . implode( '|', $this->config->get( 'CreateWikiBlacklistedSubdomains' ) ) . ')+$/';
		} else {
			$subdomainBlacklist = $this->config->get( 'CreateWikiBlacklistedSubdomains' );
		}

		if ( strpos( $subdomain, $this->config->get( 'CreateWikiSubdomain' ) ) !== false ) {
			$subdomain = str_replace( '.' . $this->config->get( 'CreateWikiSubdomain' ), '', $subdomain );
		}

		$out = $this->getOutput();

		// Make the subdomain a dbname
		if ( !ctype_alnum( $subdomain ) ) {
			$out->addHTML( '<div class="errorbox">' .  $this->msg( 'createwiki-error-notalnum' )->escaped() . '</div>' );
			return false;
		} elseif ( preg_match( $subdomainBlacklist, $subdomain ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-blacklisted' )->escaped() . '</div>' );
			return false;
		} else {
			$url = $subdomain . '.' . $this->config->get( 'CreateWikiSubdomain' );
			$dbname = $subdomain . 'wiki';
		}

		$request = new WikiRequest();
		$request->description = $formData['reason'];
		$request->dbname = $dbname;
		$request->sitename = $formData['sitename'];
		$request->language = $formData['language'];
		$request->private = $formData['private'] ?? 0;
		$request->url = $url;
		$request->requester = $this->getUser();
		$request->category = $formData['category'];
		$request->purpose = $formData['purpose'] ?? '';
		$request->bio = $formData['bio'] ?? 0;

		try {
			$requestID = $request->save();

			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			$idlink = $linkRenderer->makeLink( Title::newFromText( 'Special:RequestWikiQueue/' . $requestID ), "#{$requestID}" );

			$request->save();

			$request = new WikiRequest();

			$wm = new WikiManager( $request->dbname );
			$wmError = $wm->checkDatabaseName( $request->dbname );

			if ( $wmError ) {
				$request->decline( $wmError, User::newSystemUser( 'CreateWiki Extension' ), true, false );

				$out->addHTML( '<div class="successbox">' . $this->msg( 'requestwiki-success', $idlink )->plain() . '</div>' );
				return true;
			}
		} catch ( MWException $e ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'requestwiki-error-patient' )->plain() . '</div>' );
			return false;
		}

		$farmerLogEntry = new ManualLogEntry ( 'farmer', 'requestwiki' );
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

		$out->addHTML( '<div class="successbox">' . $this->msg( 'requestwiki-success', $idlink )->plain() . '</div>' );

		return true;
	}


	public static function isValidReason( $reason, $allData ) {
		$title = Title::newFromText( 'MediaWiki:CreateWiki-blacklist' );
		$wikiPageContent = WikiPage::factory( $title )->getContent( RevisionRecord::RAW );
		$content = ContentHandler::getContentText( $wikiPageContent );

		$regexes = explode( PHP_EOL, $content );
		unset( $regexes[0] );

		foreach ( $regexes as $regex ) {
			preg_match( "/" . $regex . "/i", $reason, $output );

			if ( is_array( $output ) && count( $output ) >= 1 ) {
				return wfMessage( 'requestwiki-error-invalidcomment' )->escaped();
			}
		}

		if ( !$reason || ctype_space( $reason ) ) {
			return wfMessage( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	protected function getGroupName() {
		return 'wikimanage';
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}
}
