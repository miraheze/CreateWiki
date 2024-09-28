<?php

namespace Miraheze\CreateWiki\CreateWiki;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class SpecialCreateWiki extends FormSpecialPage {

	private WikiManagerFactory $wikiManagerFactory;

	/**
	 * @param WikiManagerFactory $wikiManagerFactory
	 */
	public function __construct( WikiManagerFactory $wikiManagerFactory ) {
		parent::__construct( 'CreateWiki', 'createwiki' );
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( 'CreateWikiGlobalWiki' ) ) ) {
			throw new ErrorPageError( 'createwiki-wikinotglobalwiki', 'createwiki-wikinotglobalwiki' );
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
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

		if ( $this->getConfig()->get( 'CreateWikiUsePrivateWikis' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
			];
		}

		if ( $this->getConfig()->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->getConfig()->get( 'CreateWikiCategories' ),
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

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $formData ): bool {
		if ( $this->getConfig()->get( 'CreateWikiUsePrivateWikis' ) ) {
			$private = $formData['private'];
		} else {
			$private = 0;
		}

		if ( $this->getConfig()->get( 'CreateWikiCategories' ) ) {
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

	/**
	 * @param ?string $dbname
	 * @return bool|string
	 */
	public function isValidDatabase( ?string $dbname ): bool|string {
		if ( $dbname === null ) {
			return true;
		}

		$wm = $this->wikiManagerFactory->newInstance( $dbname );
		$check = $wm->checkDatabaseName( $dbname, forRename: false );

		if ( $check ) {
			// Will return a string — the error it received
			return $check;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
