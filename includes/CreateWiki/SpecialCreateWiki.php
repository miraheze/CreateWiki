<?php

use MediaWiki\MediaWikiServices;

class SpecialCreateWiki extends FormSpecialPage {
	private $config;

	public function __construct() {
		parent::__construct( 'CreateWiki', 'createwiki' );
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
	}

	protected function getFormFields() {
		$par = $this->par;
		$request = $this->getRequest();

		$formDescriptor = [
			'dbname' => [
				'label-message' => 'createwiki-label-dbname',
				'type' => 'text',
				'default' => $request->getVal( 'cwDBname' ) ? $request->getVal( 'cwDBname' ) : $par,
				'required' => true,
				'validation-callback' => [ __CLASS__, 'validateDBname' ],
				'name' => 'cwDBname',
			],
			'requester' => [
				'label-message' => 'createwiki-label-requester',
				'type' => 'user',
				'default' => $request->getVal( 'cwRequester' ),
				'exists' => true,
				'required' => true,
				'name' => 'cwRequester',
			],
			'sitename' => [
				'label-message' => 'createwiki-label-sitename',
				'type' => 'text',
				'default' => $request->getVal( 'cwSitename' ),
				'size' => 20,
				'name' => 'cwSitename',
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'createwiki-label-language',
				'default' => $request->getVal( 'cwLanguage' ) ? $request->getVal( 'cwLanguage' ) : 'en',
				'name' => 'cwLanguage',
			]
		];

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
				'name' => 'cwPrivate',
			];
		}


		if ( $this->config->get( 'CreateWikiUseCategories' ) && $this->config->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->config->get( 'CreateWikiCategories' ),
				'name' => 'cwCategory',
				'default' => 'uncategorised',
			];
		}

		$formDescriptor['reason'] = [
			'label-message' => 'createwiki-label-reason',
			'type' => 'text',
			'default' => $request->getVal( 'wpreason' ),
			'size' => 45,
			'maxlength' => 512,
			'required' => true,
		];

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$private = $formData['private'];
		} else {
			$private = 0;
		}

		if ( $this->config->get( 'CreateWikiUseCategories' ) ) {
			$category = $formData['category'];
		} else {
			$category = 'uncategorised';
		}

		$wm = new WikiManager( $formData['dbname'] );

		$wm->create( $formData['sitename'], $formData['language'], $formData['reason'], $private, $category, $formData['requester'], $this->getContext()->getUser()->getName(), $formData['reason'] );

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'createwiki-success' )->escaped() . '</div>' );

		return true;
	}

	public function validateDBname( $DBname, $allData ) {
		if ( is_null( $DBname ) ) {
			return true;
		}

		$wm = new WikiManager( $DBname );

		$check = $wm->checkDatabaseName( $DBname );

		if ( $check ) {
			return $check;
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
