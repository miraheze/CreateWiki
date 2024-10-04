<?php

namespace Miraheze\CreateWiki\CreateWiki;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
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
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( ConfigNames::GlobalWiki ) ) ) {
			throw new ErrorPageError( 'createwiki-wikinotglobalwiki', 'createwiki-wikinotglobalwiki' );
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		$formDescriptor = [
			'dbname' => [
				'label-message' => 'createwiki-label-dbname',
				'type' => 'text',
				'required' => true,
				'validation-callback' => [ $this, 'isValidDatabase' ],
			],
			'requester' => [
				'label-message' => 'createwiki-label-requester',
				'type' => 'user',
				'exists' => true,
				'required' => true,
			],
			'sitename' => [
				'label-message' => 'createwiki-label-sitename',
				'type' => 'text',
				'size' => 20,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'createwiki-label-language',
				'default' => 'en',
			],
		];

		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
			];
		}

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->getConfig()->get( ConfigNames::Categories ),
				'default' => 'uncategorised',
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 6,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
		];

		return $formDescriptor;
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $formData ): bool {
		if ( $this->getConfig()->get( ConfigNames::UsePrivateWikis ) ) {
			$private = $formData['private'];
		} else {
			$private = 0;
		}

		if ( $this->getConfig()->get( ConfigNames::Categories ) ) {
			$category = $formData['category'];
		} else {
			$category = 'uncategorised';
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $formData['dbname'] );
		$wikiManager->create(
			sitename: $formData['sitename'],
			language: $formData['language'],
			private: $private,
			category: $category,
			requester: $formData['requester'],
			actor: $this->getContext()->getUser()->getName(),
			reason: $formData['reason'],
			extra: []
		);

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'createwiki-success' )->escaped()
			)
		);

		return true;
	}

	public function isValidDatabase( ?string $dbname ): bool|string|Message {
		if ( !$dbname || ctype_space( $dbname ) ) {
			return $this->msg( 'htmlform-required' );
		}

		$wikiManager = $this->wikiManagerFactory->newInstance( $dbname );
		$check = $wikiManager->checkDatabaseName( $dbname, forRename: false );

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
