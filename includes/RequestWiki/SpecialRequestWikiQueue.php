<?php

namespace Miraheze\CreateWiki\RequestWiki;

use HTMLForm;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\EntryPointUtils;
use SpecialPage;

class SpecialRequestWikiQueue extends SpecialPage {

	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public function __construct( CreateWikiHookRunner $hookRunner ) {
		parent::__construct( 'RequestWikiQueue', 'requestwiki' );

		$this->hookRunner = $hookRunner;
	}

	public function execute( $par ) {
		if ( !EntryPointUtils::currentWikiIsGlobalWiki() ) {
			return $this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'createwiki-wikinotglobalwiki' )->escaped() )
			);
		}

		$this->setHeaders();

		if ( $par === null || $par === '' ) {
			$this->doPagerStuff();
		} else {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );

			$this->lookupRequest( $par );
		}
	}

	private function doPagerStuff() {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$dbname = $this->getRequest()->getText( 'dbname' );

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
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestwikiqueue-request-label-status',
				'options' => [
					'Unreviewed' => 'inreview',
					'Approved' => 'approved',
					'Declined' => 'declined',
					'On hold (further review)' => 'onhold',
					'Needs more details' => 'moredetails',
					'All' => '*',
				],
				'default' => $status ?: 'inreview',
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setWrapperLegendMsg( 'requestwikiqueue-search-header' );
		$htmlForm->setMethod( 'get' )->prepareForm()->displayForm( false );

		$pager = new RequestWikiQueuePager( $this, $requester, $dbname, $status );
		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	private function lookupRequest( $par ) {
		$out = $this->getOutput();

		$out->addModules( [ 'ext.createwiki.oouiform' ] );

		$out->addModuleStyles( [ 'ext.createwiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );
		$out->addModules( [ 'mediawiki.special.userrights' ] );

		$requestViewer = new RequestWikiRequestViewer( $this->hookRunner );
		$htmlForm = $requestViewer->getForm( $par, $this->getContext() );

		$htmlForm->show();
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
