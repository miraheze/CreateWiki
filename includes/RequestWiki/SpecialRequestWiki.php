<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;

class SpecialRequestWiki extends FormSpecialPage {
	public function __construct() {
		parent::__construct( 'RequestWiki', 'requestwiki' );
	}

        public function execute( $par ) {
		global $wgCreateWikiUseCustomDomains, $wgCreateWikiCustomDomainPage;

                $request = $this->getRequest();
                $out = $this->getOutput();
                $this->setParameter( $par );
                $this->setHeaders();

                if ( !$this->getUser()->isLoggedIn() ) {
                        $loginurl = SpecialPage::getTitleFor( 'Userlogin' )->getFullUrl( ['returnto' => $this->getPageTitle()->getPrefixedText() ] );
                        $out->addWikiMsg( 'requestwiki-notloggedin', $loginurl );
                        return false;
                }

                $this->checkExecutePermissions( $this->getUser() );

                if ( !$request->wasPosted() && $wgCreateWikiUseCustomDomains && $wgCreateWikiCustomDomainPage ) {
                        $customdomainurl = Title::newFromText( $wgCreateWikiCustomDomainPage )->getFullURL();
                        $out->addWikiMsg( 'requestwiki-header', $customdomainurl );
                }

                $form = $this->getForm();
                if ( $form->show() ) {
                        $this->onSuccess();
                }
        }

	protected function getFormFields() {
		global $wgCreateWikiUseCategories, $wgCreateWikiCategories, $wgCreateWikiUsePrivateWikis;

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

		if ( $wgCreateWikiUseCategories && $wgCreateWikiCategories ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $wgCreateWikiCategories,
				'default' => 'uncategorised',
				'name' => 'rwCategory',
			];
		}

		if ( $wgCreateWikiUsePrivateWikis ) {
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
		global $wgCreateWikiUsePrivateWikis, $wgCreateWikiSubdomain, $wgCreateWikiBlacklistedSubdomains;

		$subdomain = strtolower( $formData['subdomain'] );

		if ( strpos( $subdomain, $wgCreateWikiSubdomain ) !== false ) {
			$subdomain = str_replace( '.' . $wgCreateWikiSubdomain, '', $subdomain );
		}

		if ( $wgCreateWikiUsePrivateWikis ) {
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
		} elseif ( preg_match( $wgCreateWikiBlacklistedSubdomains, $subdomain ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-blacklisted' )->escaped() . '</div>' );
			return false;
		} else {
			$url = $subdomain . '.' . $wgCreateWikiSubdomain;
			$dbname = $subdomain . 'wiki';
		}

		$request = $this->getRequest();

		$dbw = wfGetDB( DB_MASTER );

		$values = [
			'cw_comment' => $formData['reason'],
			'cw_dbname' => $dbname,
			'cw_sitename' => $formData['sitename'],
			'cw_ip' => $request->getIP(),
			'cw_language' => $formData['language'],
			'cw_private' => $private,
			'cw_status' => 'inreview',
			'cw_timestamp' => $dbw->timestamp(),
			'cw_url' => $url,
			'cw_user' => $this->getUser()->getId(),
			'cw_category' => $formData['category'],
			'cw_custom' => '' // todo remove this entirely
		];

		$dbw->insert( 'cw_requests',
			$values,
			__METHOD__
		);

		$idlink = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( Title::newFromText( 'Special:RequestWikiQueue/' . $dbw->insertId() ), "#{$dbw->insertId()}" );

		$farmerLogEntry = new ManualLogEntry ( 'farmer', 'requestwiki' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getPageTitle() );
		$farmerLogEntry->setComment( $formData['reason'] );
		$farmerLogEntry->setParameters(
			[
				'4::sitename' => $formData['sitename'],
				'5::language' => $formData['language'],
				'6::private' => $private,
				'7::id' => "#{$dbw->insertId()}",
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

	public function getDisplayFormat() {
		return 'ooui';
	}
}
