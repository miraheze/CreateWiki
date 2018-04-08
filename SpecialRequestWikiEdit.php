<?php

class SpecialRequestWikiEdit extends SpecialPage {
	function __construct() {
		parent::__construct( 'RequestWikiEdit' );
	}

	function execute( $par ) {
		$out = $this->getOutput();
		$this->setHeaders();

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
			header( 'Location: ' . SpecialPage::getTitleFor( 'RequestWikiEdit' )->getFullUrl() . '/' . $params['dbname'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}


	function showEditForm( $id ) {
		global $wgUser, $wgCreateWikiUseCategories, $wgCreateWikiCategories;

		// Let everyone view the edit form, disable for those who shouldn't edit
		$disabled = true;

		$out = $this->getOutput();

		$languages = Language::fetchLanguageNames( null, 'mwfile' );
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

		if ( $wgUser->getId() === $res->cw_user || $wgUser->isAllowed( 'createwiki' ) ) {
			$disabled = false;
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
				'disabled' => $disabled,
				'default' => $subdomain,
				'name' => 'rweSubdomain',
			),
			'customdomain' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-customdomain',
				'disabled' => $disabled,
				'default' => $res->cw_custom,
				'name' => 'rweCustomdomain',
			),
			'sitename' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'disabled' => $disabled,
				'default' => $res->cw_sitename,
				'name' => 'rweSitename',
			),
			'language' => array(
				'type' => 'select',
				'label-message' => 'requestwiki-label-language',
				'options' => $options,
				'disabled' => $disabled,
				'default' => $res->cw_language,
				'name' => 'rweLanguage',
			),
			'private' => array(
				'type' => 'text',
				'label-message' => 'requestwiki-label-private',
				'disabled' => $disabled,
				'default' => $res->cw_private,
				'name' => 'rwePrivate',
			),
			'reason' => array(
				'type' => 'text',
				'label-message' => 'createwiki-label-reason',
				'disabled' => $disabled,
				'default' => $res->cw_comment,
				'name' => 'rweReason',
			),
		);

		if ( $wgCreateWikiUseCategories && $wgCreateWikiCategories ) {
			$formDescriptor['category'] = array(
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $wgCreateWikiCategories,
				'disabled' => $disabled,
				'default' => $res->cw_category,
				'name' => 'rweCategory',
			);
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'editForm' );
		$htmlForm->setMethod( 'post' )->setSubmitCallback( array( $this, 'onSubmitInput' ) )->prepareForm()->show();

	}

	function onSubmitInput( array $params ) {
		$fullurl = $params['subdomain'] . ".miraheze.org";

		$values = array(
			'cw_comment' => $params['reason'],
			'cw_language' => $params['language'],
			'cw_sitename' => $params['sitename'],
			'cw_url' => $fullurl,
			'cw_custom' => $params['customdomain'],
			'cw_category' => $params['category'],
			'cw_private' => ( $params['private'] == true ) ? 1 : 0,
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
