<?php

class SpecialRequestWikiEdit extends SpecialPage {
	function __construct() {
		parent::__construct( 'RequestWikiEdit', 'requestwiki' );
	}

	function execute( $par ) {
		$out = $this->getOutput();
		$this->setHeaders();

		if ( !$this->getUser()->isLoggedIn() ) {
			$loginurl = SpecialPage::getTitleFor( 'Userlogin' )->getFullUrl( array( 'returnto' => $this->getPageTitle()->getPrefixedText() ) );
			$out->addWikiMsg( 'requestwiki-edit-notloggedin', $loginurl );
			return false;
		}

		if ( !is_null( $par ) && $par !== '' ) {
			$this->showEditForm( $par );
		} else {
			$this->showRequestInput();
		}
	}

	function showRequestInput() {
		$formDescriptor = array(
			'requestid' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-edit-id',
				'required' => true,
				'name' => 'rweID',
			)
		);

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForm' );
		$htmlForm->setMethod( 'post')->setSubmitCallback( array( $this, 'onSubmitRedirectToEditForm' ))->prepareForm()->show();

		return true;
	}

	function onSubmitRedirectToEditForm( array $params ) {
		global $wgRequest;

		if ( $params['requestid'] !== '' ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'RequestWikiEdit' )->getFullUrl() . '/' . $params['requestid'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}


	function showEditForm( $id ) {
		global $wgUser, $wgCreateWikiUseCategories, $wgCreateWikiCategories, $wgCreateWikiUsePrivateWikis, $wgCreateWikiUseCustomDomains;

		$out = $this->getOutput();

		$languages = Language::fetchLanguageNames( null, 'wmfile' );
		ksort( $languages );
		$options = array();
		foreach ( $languages as $code => $name ) {
			$options["$code - $name"] = $code;
		}

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->selectRow( 'cw_requests',
			array(
				'cw_user',
				'cw_comment',
				'cw_language',
				'cw_private',
				'cw_sitename',
				'cw_url',
				'cw_custom',
				'cw_category'
			),
			array(
				'cw_id' => $id
			),
			__METHOD__
		);

		if ( $wgUser->getId() != $res->cw_user && !$wgUser->isAllowed( 'createwiki' ) ) {
			$out->addWikiMsg( 'requestwiki-edit-user' );
			return;
		}

		$subdomain = substr( $res->cw_url, 0, -13 );

		$formDescriptor = array(
			'requestid' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-edit-id',
				'default' => $id,
				'disabled' => true,
				'name' => 'rweRequestID',
			),
			'subdomain' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-siteurl',
				'default' => $subdomain,
				'name' => 'rweSubdomain',
				'required' => true,
			),
			'sitename' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'default' => $res->cw_sitename,
				'name' => 'rweSitename',
				'required' => true,
			),
			'language' => array(
				'type' => 'select',
				'label-message' => 'requestwiki-label-language',
				'options' => $options,
				'default' => $res->cw_language,
				'name' => 'rweLanguage',
			),
		);

		if ( $wgCreateWikiUseCustomDomains ) {
			$formDescriptor['customdomain'] = array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-customdomain',
				'default' => $res->cw_custom,
				'name' => 'rweCustomdomain',
			);
		}

		if ( $wgCreateWikiUsePrivateWikis ) {
			$formDescriptor['private'] = array(
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
				'default' => $res->cw_private == 1,
				'name' => 'rwePrivate',
			);
		}

		if ( $wgCreateWikiUseCategories && $wgCreateWikiCategories ) {
			$formDescriptor['category'] = array(
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $wgCreateWikiCategories,
				'default' => $res->cw_category,
				'name' => 'rweCategory',
			);
		}

		$formDescriptor['reason'] = array(
			'type' => 'text',
			'label-message' => 'createwiki-label-reason',
			'default' => $res->cw_comment,
			'name' => 'rweReason',
			'required' => true,
		);

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'editForm' );
		$htmlForm->setMethod( 'post' )->setSubmitCallback( array( $this, 'onSubmitInput' ) )->prepareForm()->show();

	}

	function onSubmitInput( array $params ) {
		global $wgCreateWikiUsePrivateWikis, $wgCreateWikiUseCustomDomains, $wgCreateWikiSubdomain;

		$dbname = $params['subdomain'] . 'wiki';
		$fullurl = $params['subdomain'] . '.' . $wgCreateWikiSubdomain;

		if ( $wgCreateWikiUsePrivateWikis ) {
			$private = $params['private'] ? 1 : 0;
		} else {
			$private = 0;
		}

		if ( $wgCreateWikiUseCustomDomains ) {
			$customdomain = $params['customdomain'];
		} else {
			$customdomain = "";
		}

		$values = array(
			'cw_comment' => $params['reason'],
			'cw_language' => $params['language'],
			'cw_sitename' => $params['sitename'],
			'cw_dbname' => $dbname,
			'cw_url' => $fullurl,
			'cw_custom' => $customdomain,
			'cw_category' => $params['category'],
			'cw_private' => $private,
		);

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'cw_requests',
			$values,
			array(
				'cw_id' => $params['requestid'],
			),
			__METHOD__
		);

		$farmerLogEntry = new ManualLogEntry( 'farmer', 'requestwikiedit' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getTitle() );
		$farmerLogEntry->setParameters(
			array(
				'4::id' => $params['requestid'],
			)
		);
		$farmerLogID = $farmerLogEntry->insert();
		$farmerLogEntry->publish( $farmerLogID );

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'requestwiki-edit-success', $params['requestid'] )->escaped() . '</div>' );

		return true;
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
