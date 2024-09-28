<?php

namespace Miraheze\CreateWiki\CreateWiki;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class SpecialCreateWiki extends FormSpecialPage {

	private Config $config;
	private WikiManagerFactory $wikiManagerFactory;

	public function __construct(
		ConfigFactory $configFactory,
		WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( 'CreateWiki', 'createwiki' );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	public function execute( $par ) {
		if ( !WikiMap::isCurrentWikiId( $this->config->get( 'CreateWikiGlobalWiki' ) ) ) {
			return $this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'createwiki-wikinotglobalwiki' )->escaped() )
			);
		}

		parent::execute( $par );
	}

	protected function getFormFields() {
		$par = $this->par;
		$request = $this->getRequest();

		$formDescriptor = [
			'dbname' => [
				'label-message' => 'createwiki-label-dbname',
				'type' => 'text',
				'default' => $request->getVal( 'wpdbname' ) ?: $par,
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
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

		if ( $this->config->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->config->get( 'CreateWikiCategories' ),
				'default' => 'uncategorised',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 8,
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

		if ( $this->config->get( 'CreateWikiCategories' ) ) {
			$category = $formData['category'];
		} else {
			$category = 'uncategorised';
		}

		$wm = $this->wikiManagerFactory->newInstance( $formData['dbname'] );
		$wm->create(
			$formData['sitename'],
			$formData['language'],
			$private,
			$category,
			$formData['requester'],
			$this->getContext()->getUser()->getName(),
			$formData['reason']
		);

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'createwiki-success' )->escaped()
			)
		);

		return true;
	}

	public function isValidDatabase( ?string $dbname ) {
		if ( $dbname === null ) {
			return true;
		}

		$wm = $this->wikiManagerFactory->newInstance( $dbname );
		$check = $wm->checkDatabaseName( $dbname, forRename: false );

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
