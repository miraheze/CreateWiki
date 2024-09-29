<?php

namespace Miraheze\CreateWiki\RequestWiki;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestWikiQueue extends SpecialPage {

	private IConnectionProvider $connectionProvider;
	private PermissionManager $permissionManager;
	private UserFactory $userFactory;
	private WikiManagerFactory $wikiManagerFactory;

	public function __construct(
		IConnectionProvider $connectionProvider,
		PermissionManager $permissionManager,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory
	) {
		parent::__construct( 'RequestWikiQueue', 'requestwiki' );

		$this->connectionProvider = $connectionProvider;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( 'CreateWikiGlobalWiki' ) ) ) {
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

	private function doPagerStuff(): void {
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
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestwikiqueue-search-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new RequestWikiQueuePager(
			$this->getConfig(),
			$this->getContext(),
			$this->connectionProvider,
			$this->getLinkRenderer(),
			$this->permissionManager,
			$this->userFactory,
			$dbname,
			$requester,
			$status
		);

		$table = $pager->getFullOutput();

		$this->getOutput()->addParserOutputContent( $table );
	}

	private function lookupRequest( string $par ): void {
		$requestViewer = new RequestWikiRequestViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->permissionManager,
			$this->wikiManagerFactory
		);

		$htmlForm = $requestViewer->getForm( (int)$par );

		if ( $htmlForm ) {
			$htmlForm->show();
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}
}
