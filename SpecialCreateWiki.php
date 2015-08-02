<?php
class SpecialCreateWiki extends SpecialPage {
        function __construct() {
                parent::__construct( 'CreateWiki', 'createwiki' );
        }

        function execute( $par ) {
                $request = $this->getRequest();
                $out = $this->getOutput();
                $this->setHeaders();

		$this->showInputForm();

		if ( $request->wasPosted() ) {
			$this->handleInput();
		}
	}

	function showInputForm() {
		$localpage = $this->getPageTitle()->getLocalUrl();
		$request = $this->getRequest();
		$language = $request->getVal( 'cwLanguage' ) ? $request->getVal( 'cwLanguage' ) : 'en';
		$privateboxchecked = $request->getVal( 'cwPrivate' );

                $form = Xml::openElement( 'form', array( 'action' => $localpage, 'method' => 'post' ) );
                $form .= '<fieldset><legend>' . $this->msg( 'createwiki' )->escaped() . '</legend>';
                $form .= Xml::openElement( 'table' );
                $form .= '<tr><td>' . $this->msg( 'createwiki-label-dbname' )->escaped() . '</td>';
                $form .= '<td>' . Xml::input( 'cwDBname', 20, $request->getVal( 'cwDBname' ), array( 'required' => '' ) ) . '</td></tr>';
		$form .= '<tr><td>' . $this->msg( 'createwiki-label-founder' )->escaped() . '</td>';
		$form .= '<td>' . Xml::input( 'cwFounder', 20, $request->getVal( 'cwFounder' ), array( 'required' => '' ) ) . '</td></tr>';
                $form .= '<tr><td>' . $this->msg( 'createwiki-label-sitename' )->escaped() . '</td>';
                $form .= '<td>' . Xml::input( 'cwSitename', 20, $request->getVal( 'cwSitename' ), array( 'required' => '' ) ) . '</td></tr>';
                $form .= '<tr><td>' . $this->msg( 'createwiki-label-language' )->escaped() . '</td>';
                $form .= '<td>' . Xml::languageSelector( $language, true, null, array( 'name' => 'cwLanguage' ) )[1]  . '</td></tr>';
                $form .= '<tr><td>' . $this->msg( 'createwiki-label-private' )->escaped() . '</td>';
                $form .= '<td>' . Xml::check( 'cwPrivate', $privateboxchecked, array( 'value' => 0 ) ) . '</td></tr>';
                $form .= '<tr><td>' . $this->msg( 'createwiki-label-reason' )->escaped() . '</td>';
                $form .= '<td>' . Xml::input( 'cwReason', 45, $request->getVal( 'cwReason' ), array( 'required' => '' ) ) . '</td></tr>';
                $form .= '<tr><td>' . Xml::submitButton( $this->msg( 'createwiki-label-submit' )->plain() ) . '</td></tr>';
                $form .= Xml::closeElement( 'table' );
                $form .= '</fieldset>';
                $form .= Html::hidden( 'cwToken', $this->getUser()->getEditToken() );
                $form .= Xml::closeElement( 'form' );

                $this->getOutput()->addHTML( $form );
	}

	function handleInput() {
		global $IP, $wgCreateWikiSQLfiles;

		$request = $this->getRequest();
		$out = $this->getOutput();

		$DBname = trim( $request->getVal( 'cwDBname' ) );
		$founder = trim( $request->getVal( 'cwFounder' ) );
		$sitename = trim( $request->getVal( 'cwSitename' ) );
		$reason = $request->getVal( 'cwReason' );
		$language = $request->getVal( 'cwLanguage' );
		$private = is_null( $request->getVal( 'cwPrivate' ) ) ? 0 : 1;

		$dbw = wfGetDB( DB_MASTER );

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'cwToken' ) ) ) {
                        $out->addWikiMsg( 'createwiki-error-csrf' );
                        return false;
                }

		$validation = $this->validateInput( $DBname, $founder );

		if ( !$validation ) {
			return false;
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

		$dbw->query( 'SET storage_engine=InnoDB;' );
		$dbw->query( 'CREATE DATABASE ' . $dbw->addIdentifierQuotes( $DBname ) . ';' );
		$dbw->selectDB( $DBname );

		foreach ( $wgCreateWikiSQLfiles as $sqlfile ) {
			$dbw->sourceFile( $sqlfile );
		}

		$this->writeToDBlist( $DBname, $sitename, $language, $private );

		$shx = exec( "/usr/bin/php $IP/extensions/CentralAuth/maintenance/createLocalAccount.php " . wfEscapeShellArg( $founder ) . ' --wiki ' . wfEscapeShellArg( $DBname ) );
		if ( !strpos( $shx, 'created' ) ) {
			wfDebugLog( 'CreateWiki', 'Failed to create local account for founder. - error: ' . $shx );

			$out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-usernotcreated' )->escaped() . '</div>' );
			return false;
		}

		$this->createMainPage( $language );

		// Grant founder sysop and bureaucrat rights
		$founderUser = UserRightsProxy::newFromName( $DBname, User::newFromName( $founder )->getName() );
		$newGroups = array( 'sysop', 'bureaucrat' );
		array_map( array( $founderUser, 'addGroup' ), $newGroups );


		$out->addHTML( '<div class="successbox">' . $this->msg( 'createwiki-success' )->escaped() . '</div>' );
		return true;
	}

	function validateInput( $DBname, $founder ) {
		$out = $this->getOutput();

		$user = User::newFromName( $founder );
		if ( !$user->getId() ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-foundernonexistent' )->escaped() . '</div>' );
			return false;
		}

		if ( !$this->validateDBname( $DBname ) ) {
			return false;
		}

		return true;
	}

	function validateDBname( $DBname ) {
		global $wgConf;
		$out = $this->getOutput();

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
                        $out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-dbexists' )->escaped() . '</div>' );
                        return false;
		}

		if ( !$suffixed ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-notsuffixed' )->escaped() . '</div>' );
			return false;
		}

		if ( !ctype_alnum( $DBname ) ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'createwiki-error-notalnum' )->escaped() . '</div>' );
			return false;
		}

		if ( strtolower( $DBname ) !== $DBname ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg(  'createwiki-error-notlowercase' )->escaped() . '</div>' );
			return false;
		}

		return true;
	}

	function writeToDBlist( $DBname, $sitename, $language, $private ) {
		global $IP;

		$dbline = "$DBname|$sitename|$language|\n";
		file_put_contents( "$IP/all.dblist", $dbline, FILE_APPEND | LOCK_EX );

		if ( $private !== 0 ) {
			file_put_contents( "$IP/private.dblist", "$DBname\n", FILE_APPEND | LOCK_EX );
		}

		return true;
	}

	function createMainPage( $lang ) {
		$title = Title::newFromText( wfMessage( 'mainpage' )->inLanguage( $lang )->plain() );
		$article = WikiPage::factory( $title );

		$article->doEditContent( new WikitextContent(
				wfMessage( 'createwiki-defaultmainpage' )->inLanguage( $lang )->plain() ),
			'Create main page',
			EDIT_NEW
		);
	}
}
