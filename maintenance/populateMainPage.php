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
                        ),
                        'Create main page',
                        EDIT_SUPPRESS_RC,
                        false,
                        User::newFromName( 'MediaWiki default' )
                );
        }
}

$maintClass = 'PopulateMainPage';
require_once DO_MAINTENANCE;
