<?php

namespace Miraheze\CreateWiki\Specials;

use ErrorPageError;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiManagerFactory;

class SpecialCreateWiki extends FormSpecialPage {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiValidator $validator,
		private readonly WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( 'CreateWiki', 'createwiki' );
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		parent::execute( $par );
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$formDescriptor = [
			'dbname' => [
				'type' => 'text',
				'label-message' => 'createwiki-label-dbname',
				'required' => true,
				'validation-callback' => [ $this->validator, 'validateDatabaseEntry' ],
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_wikis.sql#L2
				'maxlength' => 64,
			],
			'requester' => [
				'type' => 'user',
				'exists' => true,
				'label-message' => 'createwiki-label-requester',
				'required' => true,
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'createwiki-label-sitename',
				'required' => true,
				// https://github.com/miraheze/CreateWiki/blob/20c2f47/sql/cw_wikis.sql#L3
				'maxlength' => 128,
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
			'rows' => 10,
			'label-message' => 'createwiki-label-reason',
			'required' => true,
			'useeditfont' => true,
		];

		return $formDescriptor;
	}

	/** @inheritDoc */
	public function onSubmit( array $formData ): bool {
		$wikiManager = $this->wikiManagerFactory->newInstance( $formData['dbname'] );
		$wikiManager->create(
			sitename: $formData['sitename'],
			language: $formData['language'],
			private: $formData['private'] ?? 0,
			category: $formData['category'] ?? 'uncategorised',
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

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}
}
