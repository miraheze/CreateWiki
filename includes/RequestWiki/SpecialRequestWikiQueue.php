<?php
class SpecialRequestWikiQueue extends SpecialPage {
	function __construct() {
		parent::__construct( 'RequestWikiQueue', 'requestwiki' );
	}

	function execute( $par ) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$this->setHeaders();

		if ( is_null( $par ) || $par === '' ) {
			$this->doPagerStuff();
		} else {
			$this->lookupRequest( $par );
		}
	}

	function doPagerStuff() {
		$sitename = $this->getRequest()->getText( 'sitename' );
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$dbname = $this->getRequest()->getText( 'dbname' );

		$formDescriptor = [
			'dbname' => [
				'type' => 'text',
				'name' => 'dbname',
				'label-message' => 'createwiki-label-dbname',
				'default' => $dbname
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'requestwikiqueue-request-label-requester',
				'exist' => true,
				'default' => $requester
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'requestwikiqueue-request-label-status',
				'options' => [
					'Unreviewed' => 'inreview',
					'Approved' => 'approved',
					'Declined' => 'declined',
					'All' => '*'
				],
				'default' => ( $status ) ? $status : 'inreview'
			]
			'sitename' => [
				'type' => 'user',
				'name' => 'sitename',
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'exist' => true,
				'default' => $sitename
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitCallback( [ $this, 'dummyProcess' ] )->setMethod( 'get' )->prepareForm()->show();

		$pager = new RequestWikiQueuePager( $sitename, $requester, $dbname, $status );
		$table = $pager->getBody();

		$this->getOutput()->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}

	function dummyProcess() {
		return false;
	}

	function lookupRequest( $par ) {
		global $wgUser;

		$out = $this->getOutput();

		$out->addModules( 'ext.createwiki.oouiform' );

		$requestViewer = new RequestWikiRequestViewer();
		$htmlForm = $requestViewer->getForm( $par, $this->getContext() );
		$sectionTitles = $htmlForm->getFormSections();

		$sectTabs = [];
		foreach( $sectionTitles as $key ) {
			$sectTabs[] = [
				'name' => $key,
				'label' => $htmlForm->getLegend( $key )
			];
		}

		$out->addJsConfigVars( 'wgCreateWikiOOUIFormTabs', $sectTabs );

		$htmlForm->show();

	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
