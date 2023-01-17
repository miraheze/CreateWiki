<?php

namespace Miraheze\CreateWiki\CreateWiki;

use Config;
use FormSpecialPage;
use Html;
use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\WikiManager;

class SpecialCreateWiki extends FormSpecialPage {

	/** @var Config */
	private $config;
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public function __construct( CreateWikiHookRunner $hookRunner ) {
		parent::__construct( 'CreateWiki', 'createwiki' );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
	}

	protected function getFormFields() {
		$par = $this->par;
		$request = $this->getRequest();
		$this->checkReadOnly();

		$formDescriptor = [
			'dbname' => [
				'label-message' => 'createwiki-label-dbname',
				'type' => 'text',
				'default' => $request->getVal( 'wpdbname' ) ?: $par,
				'required' => true,
				'validation-callback' => [ $this, 'validateDBname' ],
			],
			'requester' => [
				'label-message' => 'createwiki-label-requester',
				'type' => 'user',
				'default' => $request->getVal( 'wprequester' ),
				'exists' => true,
				'required' => true,
			],
			'sitename' => [
				'label-message' => 'createwiki-label-sitename',
				'type' => 'text',
				'default' => $request->getVal( 'wpsitename' ),
				'size' => 20,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'createwiki-label-language',
				'default' => $request->getVal( 'wplanguage' ) ?: 'en',
			],
		];

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
			];
		}

		if ( $this->config->get( 'CreateWikiUseCategories' ) && $this->config->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->config->get( 'CreateWikiCategories' ),
				'default' => 'uncategorised',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 4,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'default' => $request->getVal( 'wpreason' ),
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

		$wm = new WikiManager( $formData['dbname'], $this->hookRunner );

		$wm->create( $formData['sitename'], $formData['language'], $private, $category, $formData['requester'], $this->getContext()->getUser()->getName(), $formData['reason'] );

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'createwiki-success' )->escaped() ) );

		return true;
	}

	public function validateDBname( $DBname, $allData ) {
		if ( $DBname === null ) {
			return true;
		}

		$wm = new WikiManager( $DBname, $this->hookRunner );

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
