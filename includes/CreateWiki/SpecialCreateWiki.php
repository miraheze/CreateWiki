<?php
class SpecialCreateWiki extends FormSpecialPage {
        function __construct() {
                parent::__construct( 'CreateWiki', 'createwiki' );
        }

	protected function getFormFields() {
		global $wgCreateWikiUseCategories, $wgCreateWikiCategories, $wgCreateWikiUsePrivateWikis;

		$par = $this->par;
		$request = $this->getRequest();

		$formDescriptor = array();

		$formDescriptor['dbname'] = array(
			'label-message' => 'createwiki-label-dbname',
			'type' => 'text',
			'default' => $request->getVal( 'cwDBname' ) ? $request->getVal( 'cwDBname' ) : $par,
			'size' => 20,
			'required' => true,
			'validation-callback' => array( __CLASS__, 'validateDBname' ),
			'name' => 'cwDBname',

		);

		$formDescriptor['requester'] = array(
			'label-message' => 'createwiki-label-requester',
			'type' => 'text',
			'default' => $request->getVal( 'cwRequester' ),
			'size' => 20,
			'required' => true,
			'validation-callback' => array( __CLASS__, 'validateRequester' ),
			'name' => 'cwRequester',
		);

		$formDescriptor['sitename'] = array(
			'label-message' => 'createwiki-label-sitename',
			'type' => 'text',
			'default' => $request->getVal( 'cwSitename' ),
			'size' => 20,
			'name' => 'cwSitename',
		);

		// Building a language selector (attribution:
		// includes/specials/SpecialPageLanguage.php L68)
		$languages = Language::fetchLanguageNames( null, 'wmfile' );
		ksort( $languages );
		$options = array();
		foreach ( $languages as $code => $name ) {
			$options["$code - $name"] = $code;
		}

		$formDescriptor['language'] = array(
			'type' => 'select',
			'options' => $options,
			'label-message' => 'createwiki-label-language',
			'default' => $request->getVal( 'cwLanguage' ) ? $request->getVal( 'cwLanguage' ) : 'en',
			'name' => 'cwLanguage',
		);

		if ( $wgCreateWikiUsePrivateWikis ) {
			$formDescriptor['private'] = array(
				'type' => 'check',
				'label-message' => 'createwiki-label-private',
				'name' => 'cwPrivate',
			);
		}


		if ( $wgCreateWikiUseCategories && $wgCreateWikiCategories ) {
			$formDescriptor['category'] = array(
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $wgCreateWikiCategories,
				'name' => 'cwCategory',
				'default' => 'uncategorised',
			);
		}

		$formDescriptor['reason'] = array(
			'label-message' => 'createwiki-label-reason',
			'type' => 'text',
			'default' => $request->getVal( 'wpreason' ),
			'size' => 45,
			'required' => true,
		);

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		global $IP, $wgCreateWikiDatabase, $wgCreateWikiSQLfiles, $wgDBname, $wgCreateWikiUseCategories, $wgCreateWikiUsePrivateWikis, $wgCreateWikiEmailNotifications;

		$DBname = $formData['dbname'];
		$requesterName = $formData['requester'];
		$siteName = $formData['sitename'];
		$language = $formData['language'];
		$reason = $formData['reason'];

		if ( $wgCreateWikiUsePrivateWikis ) {
			$private = $formData['private'];
		} else {
			$private = 0;
		}

		if ( $wgCreateWikiUseCategories ) {
			$category = $formData['category'];
		} else {
			$category = 'uncategorised';
		}

		$farmerLogEntry = new ManualLogEntry( 'farmer', 'createwiki' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getTitle() );
		$farmerLogEntry->setComment( $reason );
		$farmerLogEntry->setParameters(
			array(
				'4::wiki' => $DBname
			)
		);
		$farmerLogID = $farmerLogEntry->insert();
		$farmerLogEntry->publish( $farmerLogID );

		$dbw = wfGetDB( DB_MASTER );
		$dbw->query( 'SET storage_engine=InnoDB;' );
		$dbw->query( 'CREATE DATABASE ' . $dbw->addIdentifierQuotes( $DBname ) . ';' );

		$this->addWikiToDatabase( $DBname, $siteName, $language, $private, $category );

		// Let's ensure our wiki is in the DBlist on the server
		// we run the maintenance scripts on.
		exec( "/usr/bin/php /srv/mediawiki/w/extensions/CreateWiki/maintenance/DBListGenerator.php --wiki " . $wgCreateWikiDatabase );

		$dbw->selectDB( $DBname );

		foreach ( $wgCreateWikiSQLfiles as $sqlfile ) {
			$dbw->sourceFile( $sqlfile );
		}

		$this->createMainPage( $language );

		$dbw->selectDB( $wgDBname ); // revert back to main wiki

		Hooks::run( 'CreateWikiCreation', [ $DBname, $private ] );

		$shcreateaccount = exec( "/usr/bin/php " .
			"$IP/extensions/CentralAuth/maintenance/createLocalAccount.php " . wfEscapeShellArg( $requesterName ) . " --wiki " . wfEscapeShellArg( $DBname ) );

		if ( !strpos( $shcreateaccount, 'created' ) ) {
			wfDebugLog( 'CreateWiki', 'Failed to create local account for requester. - error: ' . $shcreateaccount );
			return wfMessage( 'createwiki-error-usernotcreated' )->escaped();
		}

		$shpromoteaccount = exec( "/usr/bin/php " .
			"$IP/maintenance/createAndPromote.php " . wfEscapeShellArg( $requesterName ) . " --bureaucrat --sysop --custom-groups='founder' --force --wiki " . wfEscapeShellArg( $DBname ) );

		if( $this->getUser()->getName() != $requesterName && $wgCreateWikiEmailNotifications ) {
			$notifyEmail = MailAddress::newFromUser( User::newFromName( $requesterName ) );
			$this->sendCreationEmail( $notifyEmail, $siteName );
		}

		$this->getOutput()->addHTML( '<div class="successbox">' . wfMessage( 'createwiki-success' )->escaped() . '</div>' );

		return true;
	}

	public function validateDBname( $DBname, $allData ) {
		global $wgConf;

		# HTMLForm's validation-callback somehow gets called, even
		# while the form was not submitted yet. This should prevent
		# the validation from failing because the submitted value is
		# NULL, but it is a hack, and instead the validation just
		# shouldn't be called unless the form actually has been
		# submitted..
		if ( is_null( $DBname ) ) {
			return true;
		}

		$suffixed = false;
		foreach ( $wgConf->suffixes as $suffix ) {
			if ( substr( $DBname, -strlen( $suffix ) ) === $suffix ) {
				$suffixed = true;
				break;
			}
		}

		$dbw = wfGetDB( DB_MASTER );
		$res = $dbw->query( 'SHOW DATABASES LIKE ' . $dbw->addQuotes( $DBname ) . ';' );

		if ( $res->numRows() !== 0 ) {
			return wfMessage( 'createwiki-error-dbexists' )->escaped();
		}

		if ( !$suffixed ) {
			return wfMessage( 'createwiki-error-notsuffixed' )->escaped();
		}

		if ( !ctype_alnum( $DBname ) ) {
			return wfMessage( 'createwiki-error-notalnum' )->escaped();
		}

		if ( strtolower( $DBname ) !== $DBname ) {
			return wfMessage( 'createwiki-error-notlowercase' )->escaped();
		}

		return true;
	}

	public function validateRequester( $requesterName, $allData ) {
		# HTMLForm's validation-callback somehow gets called, even
                # while the form was not submitted yet. This should prevent
                # the validation from failing because the submitted value is
                # NULL, but it is a hack, and instead the validation just
                # shouldn't be called unless the form actually has been
                # submitted..
		if ( is_null( $requesterName ) ) {
			return true;
		}

		$user = User::newFromName( $requesterName );

		if ( !$user->getId() ) {
			return wfMessage( 'createwiki-error-requesternonexistent' )->escaped();
		}

		return true;
	}

	public function addWikiToDatabase( $DBname, $siteName, $language, $private, $category ) {
		global $wgCreateWikiDatabase;
		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		if ( $private ) {
			$private = 1;
		} else {
			$private = 0;
		}

		$dbw->insert(
			'cw_wikis',
			array(
				'wiki_dbname' => $DBname,
				'wiki_sitename' => $siteName,
				'wiki_language' => $language,
				'wiki_private' => $private,
				'wiki_closed' => 0,
				'wiki_inactive' => 0,
				'wiki_category' => $category,
			),
			__METHOD__
		);

		return true;
	}

	public function createMainPage( $language ) {
		// Don't use Meta's mainpage message!
		if ( $language !== 'en' ) {
			$page = wfMessage( 'mainpage' )->inLanguage( $language )->plain();
		} else {
			$page = 'Main_Page';
		}

		$title = Title::newFromText( $page );
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

		return true;
	}

	public function getDisplayFormat() {
		return 'ooui';
        }

	protected function getGroupName() {
		return 'wikimanage';
	}

	protected function sendCreationEmail( $notifyEmail, $siteName ) {
		global $wgPasswordSender, $wgSitename;

		$from = new MailAddress( $wgPasswordSender, 'CreateWiki on ' . $wgSitename );
		$subject = wfMessage( 'createwiki-email-subject', $siteName )->inContentLanguage()->text();
		$body = wfMessage( 'createwiki-email-body' )->inContentLanguage()->text();

		return UserMailer::send( $notifyEmail, $from, $subject, $body );
	}
}
