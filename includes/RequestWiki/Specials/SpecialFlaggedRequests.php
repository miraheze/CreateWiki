<?php

namespace Miraheze\CreateWiki\RequestWiki\Specials;

use ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\RequestWiki\FlaggedRequestsPager;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialFlaggedRequests extends SpecialPage {

	private IConnectionProvider $connectionProvider;
	private PermissionManager $permissionManager;
	private UserFactory $userFactory;
	private WikiManagerFactory $wikiManagerFactory;
	private WikiRequestManager $wikiRequestManager;

	public function __construct(
		IConnectionProvider $connectionProvider,
		PermissionManager $permissionManager,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory,
		WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( 'FlaggedRequests', 'createwiki' );

		$this->connectionProvider = $connectionProvider;
		$this->permissionManager = $permissionManager;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->wikiRequestManager = $wikiRequestManager;
	}

	/**
	 * @param ?string $par
	 */
	public function execute( $par ): void {
		if ( !WikiMap::isCurrentWikiId( $this->getConfig()->get( ConfigNames::GlobalWiki ) ) ) {
			throw new ErrorPageError( 'errorpagetitle', 'createwiki-wikinotglobalwiki' );
		}

		$this->setHeaders();
		$this->doPagerStuff();
	}

	private function doPagerStuff(): void {
		$formDescriptor = [
			'intro' => [
				'type' => 'info',
				'default' => $this->msg( 'flaggedrequests-info' )->text(),
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->suppressDefaultSubmit()
			->prepareForm()
			->displayForm( false );

		$pager = new FlaggedRequestsPager(
			$this->getConfig(),
			$this->getContext(),
			$this->connectionProvider,
			$this->getLinkRenderer(),
			$this->permissionManager,
			$this->userFactory,
			$this->wikiManagerFactory,
			$this->wikiRequestManager
		);

		$table = $pager->getFullOutput();
		$this->getOutput()->addParserOutputContent( $table );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'wikimanage';
	}
}