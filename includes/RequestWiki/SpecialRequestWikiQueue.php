<?php

namespace Miraheze\CreateWiki\RequestWiki;

use HTMLForm;
use SpecialPage;

class SpecialRequestWikiQueue extends SpecialPage {

	public function __construct() {
		parent::__construct( 'RequestWikiQueue', 'requestwiki' );
	}

	public function execute( $par ) {
		$this->setHeaders();

		if ( $par === null || $par === '' ) {
			$this->doPagerStuff();
		} else {
			$this->lookupRequest( $par );
		}
	}

	private function doPagerStuff() {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$dbname = $this->getRequest()->getText( 'dbname' );

		$formDescriptor = [
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
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestwikiqueue-request-label-status',
				'options' => [
					'Unreviewed' => 'inreview',
					'Approved' => 'approved',
					'Declined' => 'declined',
					'All' => '*',
				],
				'default' => $status ?: 'inreview',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setMethod( 'get' )->prepareForm()->displayForm( false );

		$pager = new RequestWikiQueuePager( $this, $requester, $dbname, $status );
		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	private function lookupRequest( $par ) {
		$out = $this->getOutput();

		$out->addModules( 'ext.createwiki.oouiform' );

		$requestViewer = new RequestWikiRequestViewer();
		$htmlForm = $requestViewer->getForm( $par, $this->getContext() );
		$sectionTitles = $htmlForm->getFormSections();

		$sectTabs = [];
		foreach ( $sectionTitles as $key ) {
			$sectTabs[] = [
				'name' => $key,
				'label' => $htmlForm->getLegend( $key ),
			];
		}

		$out->addJsConfigVars( 'wgCreateWikiOOUIFormTabs', $sectTabs );

		$htmlForm->show();
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
