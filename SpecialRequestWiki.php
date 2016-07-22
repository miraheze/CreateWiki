<?php

class SpecialRequestWiki extends SpecialPage {
	private $errors = false;

	function __construct() {
		parent::__construct( 'RequestWiki' );
	}

	function execute( $par ) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$this->setHeaders();

		if ( !$this->getUser()->isLoggedIn() ) {
			$loginurl = SpecialPage::getTitleFor( 'Userlogin' )->getFullUrl( array( 'returnto' => $this->getPageTitle()->getPrefixedText() ) );
			$out->addWikiMsg( 'requestwiki-notloggedin', $loginurl );
			return false;
		}

		if ( $this->getUser()->isBlocked() ) {
			throw new UserBlockedError( $this->getUser()->getBlock() );
		}

		if ( !$request->wasPosted() ) {
			$customdomainurl = Title::newFromText( 'Special:MyLanguage/Custom_domains' )->getFullURL();
			$out->addWikiMsg( 'requestwiki-header', $customdomainurl );
		}

		if ( !$request->wasPosted() || ( $request->wasPosted() && $this->errors ) ) {
			$this->addRequestWikiForm();
		}

		if ( $request->wasPosted() ) {
			$this->handleRequestWikiFormInput();
		}
	}

	function addRequestWikiForm() {
		$localpage = $this->getPageTitle()->getLocalUrl();

		$form = Xml::openElement( 'form', array( 'action' => $localpage, 'method' => 'post' ) );
		$form .= '<fieldset><legend>' . $this->msg( 'requestwiki' )->escaped() . '</legend>';
		$form .= Xml::openElement( 'table' );
		$form .= '<tr><td>' . $this->msg( 'requestwiki-label-siteurl' )->escaped() . '</td>';
		$form .= '<td>' . Xml::input( 'subdomain', 20, '' ) . '.miraheze.org' . '</td></tr>';
		$form .= '<tr><td>' . $this->msg( 'requestwiki-label-sitename' )->escaped() . '</td>';
		$form .= '<td>' . Xml::input( 'sitename', 20, '', array( 'required' => '' ) ) . '</td></tr>';
		$form .= '<tr><td>' . $this->msg( 'requestwiki-label-customdomain' )->escaped() . '</td>';
		$form .= '<td>' . Xml::input( 'customdomain', 20, '' ) . '</td></tr>';
		$form .= '<tr><td>' . $this->msg( 'requestwiki-label-language' )->escaped() . '</td>';
		$form .= '<td>' . Xml::languageSelector( 'en', true, null, array( 'name' => 'language' ) )[1]  . '</td></tr>';
		$form .= '<tr><td>' . $this->msg( 'requestwiki-label-private' )->escaped() . '</td>';
		$form .= '<td>' . Xml::check( 'private', false, array( 'value' => 0 ) ) . '</td></tr>';
		$form .= '<tr><td>' . $this->msg( 'requestwiki-label-comments' )->escaped() . '</td>';
		$form .= '<td>' . Xml::textarea( 'comments', '', 40, 5, array( 'required' => '' ) ) . '</td></tr>';
		$form .= '<tr><td>' . Xml::submitButton( $this->msg( 'requestwiki-submit' )->plain() ) . '</td></tr>';
		$form .= Xml::closeElement( 'table' );
		$form .= '</fieldset>';
		$form .= Html::hidden( 'token', $this->getUser()->getEditToken() );
		$form .= Xml::closeElement( 'form' );

		$this->getOutput()->addHTML( $form );
	}

	function handleRequestWikiFormInput() {
		global $wgRequest;

		$request = $this->getRequest();
		$out = $this->getOutput();
		$vars = array( 'sitename', 'language', 'comments' );

		// Check if each var exists
		foreach ( $vars as $var ) {
			if ( !$request->getVal( $var ) ) {
				$this->errors = is_array( $this->errors ) ?: $this->errors;
				$this->errors[] = $var;
			}
		}

		if ( $this->errors ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'requestwiki-error-notallfilledin' )->escaped() . '</div>' );
			return false;
		}

		$subdomain = $request->getVal( 'subdomain' );
		$sitename = $request->getVal( 'sitename' );
		$language = $request->getVal( 'language' );
		$private = is_null( $request->getVal( 'private' ) ) ? 0 : 1;
		$comment = $request->getVal( 'comments' );
		$customdomain = $request->getVal( 'customdomain' );

		if ( $subdomain && $customdomain ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'requestwiki-error-twodomains' )->escaped() . '</div>' );
			return false;
		} elseif ( !$subdomain && !$customdomain ) {
			$out->addHTML( '<div class="errorbox">' . $this->msg( 'requestwiki-error-nodomain' )->escaped() . '</div>' );
			return false;
		} elseif ( $subdomain && !$customdomain ) {
			$domain = 'subdomain';
		} else {
			$domain = 'customdomain';
		}

		if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
			$out->addWikiMsg( 'requestwiki-error-csrf' );
			return false;
		}

		// Make the subdomain a dbname
		if ( $subdomain ) {
			if ( !ctype_alnum( $subdomain ) ) {
				$out->addHTML( '<div class="errorbox">' .  $this->msg( 'createwiki-error-notalnum' )->escaped() . '</div>' );
				return false;
			} else {
				$url = strtolower( $subdomain ) . '.miraheze.org';
				$subdomain = strtolower( $subdomain ) . 'wiki';
			}
		} else {
			$url = $customdomain;
			$subdomain = 'NULL';
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'cw_requests',
			array(
				'cw_comment' => $comment,
				'cw_dbname' => $subdomain,
				'cw_ip' => $request->getIP(),
				'cw_language' => $language,
				'cw_private' => $private,
				'cw_status' => 'inreview',
				'cw_sitename' => $sitename,
				'cw_timestamp' => $dbw->timestamp(),
				'cw_url' => $url,
				'cw_user' => $this->getUser()->getId(),
			), __METHOD__ );

		$idlink = Linker::link( Title::newFromText( 'Special:RequestWikiQueue/' . $dbw->insertId() ), "#{$dbw->insertId()}" );

                $farmerLogEntry = new ManualLogEntry( 'farmer', 'requestwiki' );
                $farmerLogEntry->setPerformer( $this->getUser() );
                $farmerLogEntry->setTarget( $this->getTitle() );
                $farmerLogEntry->setComment( $comment );
                $farmerLogEntry->setParameters(
                        array(
                                '4::sitename' => $sitename,
				'5::language' => $language,
				'6::private' => $private,
				'7::id' => "#{$dbw->insertId()}"
                        )
                );
                $farmerLogID = $farmerLogEntry->insert();
                $farmerLogEntry->publish( $farmerLogID );

		$this->getOutput()->addHTML( '<div class="successbox">' . $this->msg( 'requestwiki-success', $idlink )->plain() . '</div>' );
	}
}
