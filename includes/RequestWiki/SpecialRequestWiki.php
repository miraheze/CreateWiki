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

			if ( !$this->getUser()->isLoggedIn() ) {
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
				'name' => 'rwSubdomain',
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'required' => true,
				'name' => 'rwSitename',
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'requestwiki-label-language',
				'default' => 'en',
				'name' => 'rwLanguage',
			]
		];

		if ( $this->config->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->config->get( 'CreateWikiCategories' ),
				'default' => 'uncategorised',
				'name' => 'rwCategory',
			];
		}

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'name' => 'rwPrivate',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'text',
			'label-message' => 'createwiki-label-reason',
			'required' => true,
			'validation-callback' => [ __CLASS__, 'isValidReason' ],
			'name' => 'rwReason',
		];

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		$subdomain = strtolower( $formData['subdomain'] );

		if ( strpos( $subdomain, $this->config->get( 'CreateWikiSubdomain' ) ) !== false ) {
			$subdomain = str_replace( '.' . $this->config->get( 'CreateWikiSubdomain' ), '', $subdomain );
		}

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$private = $formData['private'] ? 1 : 0;
		} else {
			$private = 0;
		}

		$out = $this->getOutput();

		// Make the subdomain a dbname
		if ( !ctype_alnum( $subdomain ) ) {
			$out->addHTML( '<div class="errorbox">' .  $this->msg( 'createwiki-error-notalnum' )->escaped() . '</div>' );
			wfDebugLog( 'CreateWiki', 'Invalid subdomain entered. Requested: ' . $subdomain );
			return false;
		} elseif ( preg_match( $this->config->get( 'CreateWikiBlacklistedSubdomains' ), $subdomain ) ) {
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
		$request->private = $private;
		$request->url = $url;
		$request->requester = $this->getUser();
		$request->category = $formData['category'];

		$requestID = $request->save();

		$idlink = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( Title::newFromText( 'Special:RequestWikiQueue/' . $requestID ), "#{$requestID}" );

		$farmerLogEntry = new ManualLogEntry ( 'farmer', 'requestwiki' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getPageTitle() );
		$farmerLogEntry->setComment( $formData['reason'] );
		$farmerLogEntry->setParameters(
			[
				'4::sitename' => $formData['sitename'],
				'5::language' => $formData['language'],
				'6::private' => $private,
				'7::id' => "#{$requestID}",
			]
		);
		$farmerLogID = $farmerLogEntry->insert();
		$farmerLogEntry->publish( $farmerLogID );

		$out->addHTML( '<div class="successbox">' . $this->msg( 'requestwiki-success', $idlink )->plain() . '</div>' );

		return true;
	}


	public function isValidReason( $reason, $allData ) {
		$title = Title::newFromText( 'MediaWiki:CreateWiki-blacklist' );
		$wikiPageContent = WikiPage::factory( $title )->getContent( RevisionRecord::RAW );
		$content = ContentHandler::getContentText( $wikiPageContent );

		$regexes = explode( PHP_EOL, $content );
		unset( $regexes[0] );

		foreach ( $regexes as $regex ) {
			preg_match( "/" . $regex . "/i", $reason, $output );

			if ( is_array( $output ) && count( $output ) >= 1 ) {
				return wfMessage( 'requestwiki-error-invalidcomment' );
			}
		}

		if ( $reason == '' ) {
			return wfMessage( 'htmlform-required', 'parseinline' );
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
