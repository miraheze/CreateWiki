<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

class PopulateMainPage extends Maintenance {
	private $config;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Populates the Main Page of a new wiki.';
		$this->addOption( 'lang', 'Language of the Main Page, otherwise defaults to the wiki\'s language.', false );
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
	}

	public function execute() {
		$language = $this->getOption( 'lang', $this->config->get( 'LanguageCode' ) );

		$mainPageName = wfMessage( 'mainpage' )->inLanguage( $language )->plain();
		$title = Title::newFromText( $mainPageName );
		$article = WikiPage::factory( $title )->newPageUpdater( User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] ) );
		$article->setContent( SlotRecord::MAIN, new WikitextContent( wfMessage( 'createwiki-defaultmainpage' )->inLanguage( $language )->plain() ) );
		$article->saveRevision( CommentStoreComment::newUnsavedComment( wfMessage( 'createwiki-defaultmainpage-summary' )->inLanguage( $language )->plain() ), EDIT_SUPPRESS_RC );

	}
}

$maintClass = 'PopulateMainPage';
require_once( RUN_MAINTENANCE_IF_MAIN );
