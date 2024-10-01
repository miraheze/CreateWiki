<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MainConfigNames;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use WikitextContent;

class PopulateMainPage extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates the Main Page of a new wiki.' );
		$this->addOption( 'lang', 'Language of the Main Page, otherwise defaults to the wiki\'s language.', false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$language = $this->getOption( 'lang', $this->getConfig()->get( MainConfigNames::LanguageCode ) );
		$wikiPageFactory = $this->getServiceContainer()->getWikiPageFactory();

		$mainPageName = wfMessage( 'mainpage' )->inLanguage( $language )->plain();
		$title = Title::newFromText( $mainPageName );

		$article = $wikiPageFactory->newFromTitle( $title )->newPageUpdater(
			User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] )
		);

		$article->setContent(
			SlotRecord::MAIN,
			new WikitextContent(
				wfMessage( 'createwiki-defaultmainpage' )->inLanguage( $language )->plain()
			)
		);

		$article->saveRevision(
			CommentStoreComment::newUnsavedComment(
				wfMessage( 'createwiki-defaultmainpage-summary' )->inLanguage( $language )->plain()
			),
			EDIT_SUPPRESS_RC
		);
	}
}

$maintClass = PopulateMainPage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
