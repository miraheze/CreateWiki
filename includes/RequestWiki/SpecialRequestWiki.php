<?php

class SpecialRequestWiki extends FormSpecialPage {
	function __construct() {
		parent::__construct( 'RequestWiki', 'requestwiki' );
	}

        public function execute( $par ) {
		global $wgCreateWikiUseCustomDomains, $wgCreateWikiCustomDomainPage;

                $request = $this->getRequest();
                $out = $this->getOutput();
                $this->setParameter( $par );
                $this->setHeaders();

                if ( !$this->getUser()->isLoggedIn() ) {
                        $loginurl = SpecialPage::getTitleFor( 'Userlogin' )->getFullUrl( array( 'returnto' => $this->getPageTitle()->getPrefixedText() ) );
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
		global $wgCreateWikiUseCategories, $wgCreateWikiCategories, $wgCreateWikiUsePrivateWikis, $wgCreateWikiUseCustomDomains;

		$request = $this->getRequest();

		$formDescriptor = array();

		$formDescriptor['subdomain'] = array(
			'type' => 'text',
			'label-message' => 'requestwiki-label-siteurl',
			'required' => true,
			'name' => 'rwSubdomain',
		);

		if ( $wgCreateWikiUseCustomDomains ) {
			$formDescriptor['customdomain-info'] = array(
				'type' => 'info',
				'label' => '',
				'label-message' => 'requestwiki-label-customdomain-info',
			);

			$formDescriptor['customdomain'] = array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-customdomain',
				'name' => 'rwCustom',
			);
		}

		$formDescriptor['sitename'] = array(
			'type' => 'text',
			'label-message' => 'requestwiki-label-sitename',
			'required' => true,
			'name' => 'rwSitename',
		);

		$languages = Language::fetchLanguageNames( null, 'wmfile' );
		ksort( $languages );
		$options = array();
		foreach ( $languages as $code => $name ) {
			$options["$code - $name"] = $code;
		}

		$formDescriptor['language'] = array(
			'type' => 'select',
			'options' => $options,
			'label-message' => 'requestwiki-label-language',
			'default' => 'en',
			'name' => 'rwLanguage',
		);

		if ( $wgCreateWikiUseCategories && $wgCreateWikiCategories ) {
			$formDescriptor['category'] = array(
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $wgCreateWikiCategories,
				'default' => 'uncategorised',
				'name' => 'rwCategory',
			);
		}

		if ( $wgCreateWikiUsePrivateWikis ) {
			$formDescriptor['private'] = array(
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'name' => 'rwPrivate',
			);
		}

		$formDescriptor['reason'] = array(
			'type' => 'text',
			'label-message' => 'createwiki-label-reason',
			'required' => true,
			'validation-callback' => array( __CLASS__, 'isValidReason' ),
			'name' => 'rwReason',
		);

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		global $wgCreateWikiUsePrivateWikis, $wgCreateWikiUseCustomDomains, $wgCreateWikiSubdomain;

		$subdomain = strtolower( $formData['subdomain'] );

		if ( strpos( $subdomain, $wgCreateWikiSubdomain ) !== false ) {
			str_replace( '.' . $wgCreateWikiSubdomain, '', $subdomain )
		}

		if ( $wgCreateWikiUsePrivateWikis ) {
			$private = $formData['private'] ? 1 : 0;
		} else {
			$private = 0;
		}

		if ( $wgCreateWikiUseCustomDomains ) {
			$customdomain = $formData['customdomain'];
		} else {
			$customdomain = "";
		}

		$out = $this->getOutput();

		// Make the subdomain a dbname
		if ( !ctype_alnum( $subdomain ) ) {
			$out->addHTML( '<div class="errorbox">' .  $this->msg( 'createwiki-error-notalnum' )->escaped() . '</div>' );
			wfDebugLog( 'CreateWiki', 'Invalid subdomain entered. Requested: ' . $subdomain );
			return false;
		} else {
			$url = $subdomain . '.' . $wgCreateWikiSubdomain;
			$dbname = $subdomain . 'wiki';
		}

		$request = $this->getRequest();
		
		$dbw = wfGetDB( DB_MASTER );

		$values = array(
			'cw_comment' => $formData['reason'],
			'cw_dbname' => $dbname,
			'cw_sitename' => $formData['sitename'],
			'cw_ip' => $request->getIP(),
			'cw_language' => $formData['language'],
			'cw_private' => $private,
			'cw_status' => 'inreview',
			'cw_timestamp' => $dbw->timestamp(),
			'cw_url' => $url,
			'cw_custom' => $customdomain,
			'cw_user' => $this->getUser()->getId(),
			'cw_category' => $formData['category'],
		);

		$dbw->insert( 'cw_requests',
			$values,
			__METHOD__
		);

		$idlink = Linker::link( Title::newFromText( 'Special:RequestWikiQueue/' . $dbw->insertId() ), "#{$dbw->insertId()}" );

		$farmerLogEntry = new ManualLogEntry ( 'farmer', 'requestwiki' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getTitle() );
		$farmerLogEntry->setComment( $formData['reason'] );
		$farmerLogEntry->setParameters(
			array(
				'4::sitename' => $formData['sitename'],
				'5::language' => $formData['language'],
				'6::private' => $private,
				'7::id' => "#{$dbw->insertId()}",
			)
		);
		$farmerLogID = $farmerLogEntry->insert();
		$farmerLogEntry->publish( $farmerLogID );

		$out->addHTML( '<div class="successbox">' . $this->msg( 'requestwiki-success', $idlink )->plain() . '</div>' );

		return true;
	}


	public function isValidReason( $reason, $allData ) {
		$title = Title::newFromText( 'MediaWiki:CreateWiki-blacklist' );
		$wikiPageContent = WikiPage::factory( $title )->getContent( Revision::RAW );
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
