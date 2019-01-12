<?php
require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class PopulateMainPage extends Maintenance {
        public function __construct() {
                parent::__construct();
                $this->mDescription = 'Populates the Main Page of a new wiki.';
                $this->addOption( 'lang', 'Language of the Main Page, otherwise defaults to the wiki\'s language.', false );
        }

        public function execute() {
                global $wgLanguageCode;

                $language = $this->getOption( 'lang', $wgLanguageCode );

                $mainPageName = wfMessage( 'mainpage' )->inLanguage( $language )->plain();
                $title = Title::newFromText( $mainPageName );
                $article = WikiPage::factory( $title );
                $article->doEditContent(
                        new WikitextContent(
                                wfMessage( 'createwiki-defaultmainpage' )->inLanguage( $language )->plain()
                        ), // Text
                        'Create main page', // Edit summary
                        EDIT_SUPPRESS_RC, // Flags
                        false, // I have no idea what this is
                        User::newFromName( 'MediaWiki default' ) // We don't want to have incorrect user_id - user_name entries
                );
        }
}

$maintClass = 'PopulateMainPage';
require_once DO_MAINTENANCE;
