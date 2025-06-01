<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\RequestWiki\RequestWikiQueuePager;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Miraheze\CreateWiki\Services\WikiRequestViewer;

class SpecialRequestWikiQueue extends SpecialPage {

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly UserFactory $userFactory,
		private readonly WikiRequestManager $wikiRequestManager,
		private readonly WikiRequestViewer $wikiRequestViewer
	) {
		parent::__construct( 'RequestWikiQueue', 'requestwiki' );
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !$this->databaseUtils->isCurrentWikiCentral() ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotcentralwiki' );
		}

		$this->setHeaders();

		if ( $par ) {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
			$this->lookupRequest( $par );
			return;
		}

		$this->doPagerStuff();
	}

	private function doPagerStuff(): void {
		$dbname = $this->getRequest()->getText( 'dbname' );
		$language = $this->getRequest()->getText( 'language' );
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );

		$formDescriptor = [
			'intro' => [
				'type' => 'info',
				'default' => $this->msg( 'requestwikiqueue-info' )->text(),
			],
			'dbname' => [
				'type' => 'text',
				'name' => 'dbname',
				'label-message' => 'createwiki-label-dbname',
				'default' => $dbname,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'requestwikiqueue-request-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'language' => [
				'type' => 'language',
				'name' => 'language',
				'label-message' => 'requestwikiqueue-request-label-language',
				'default' => $language ?: '*',
				'options' => [
					// We cannot use options-messages here as otherwise
					// it overrides all language options.
					$this->msg( 'createwiki-label-all-languages' )->text() => '*',
				],
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestwikiqueue-request-label-status',
				'options-messages' => [
					'requestwikiqueue-inreview' => 'inreview',
					'requestwikiqueue-approved' => 'approved',
					'requestwikiqueue-declined' => 'declined',
					'requestwikiqueue-onhold' => 'onhold',
					'requestwikiqueue-moredetails' => 'moredetails',
					'createwiki-label-all-statuses' => '*',
				],
				'default' => $status ?: 'inreview',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestwikiqueue-search-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new RequestWikiQueuePager(
			$this->getContext(),
			$this->databaseUtils,
			$this->languageNameUtils,
			$this->getLinkRenderer(),
			$this->userFactory,
			$this->wikiRequestManager,
			$dbname,
			$language,
			$requester,
			$status
		);

		$table = $pager->getFullOutput();
		$this->getOutput()->addParserOutputContent( $table );
	}

	private function lookupRequest( string $par ): void {
		$this->getOutput()->enableOOUI();
		// Lookup the request by the id (the current subpage)
		// and then show the form for the request if it is found.
		$this->wikiRequestViewer->getForm( (int)$par )->show();
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}
}
