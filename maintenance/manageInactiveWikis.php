<?php
/**
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License along
* with this program; if not, write to the Free Software Foundation, Inc.,
* 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
* http://www.gnu.org/copyleft/gpl.html
*
* @file
* @ingroup Maintenance
* @author Southparkfan
* @author John Lewis
* @version 1.3
*/

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class FindInactiveWikis extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'warn', 'Actually warn wikis which are considered inactive but not closable yet', false, false );
		$this->addOption( 'close', 'Actually close wikis which are considered inactive and closable.', false, false );
		$this->mDescription = 'A script to find inactive wikis in a farm.';
	}

	public function execute() {
		global $wgCreateWikiInactiveWikisWhitelist, $wgCreateWikiDatabase;
		$dbr = wfGetDB( DB_SLAVE );
		$dbr->selectDB( $wgCreateWikiDatabase ); // force this

		$res = $dbr->select(
			'cw_wikis',
			array( 'wiki_dbname', 'wiki_inactive', 'wiki_closed' ),
			array(),
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbname = $row->wiki_dbname;
			$inactive = $row->wiki_inactive;
			$closed = $row->wiki_closed;

			if ( in_array( $dbname, $wgCreateWikiInactiveWikisWhitelist ) ) {
				continue; // Wiki is in whitelist, do not check.
			}

			// Apparently I need to force this here too, so I'll do that.
			$dbr->selectDB( $wgCreateWikiDatabase );

			$res = $dbr->selectRow(
				'logging',
				'log_timestamp',
				array(
					'log_action' => 'createwiki',
					'log_params' => serialize( array( '4::wiki' => $dbname ) )
				),
				__METHOD__,
				array( // Sometimes a wiki might have been created multiple times.
					'ORDER BY' => 'log_timestamp DESC'
				)
			);

			if ( !isset( $res ) || !isset( $res->log_timestamp ) ) {
				$this->output( "ERROR: couldn't determine when {$dbname} was created!\n" );
				continue;
			}

			if ( $res && $res->log_timestamp < date( "YmdHis", strtotime( "-45 days" ) ) ) {
				$this->checkLastActivity( $dbname, $inactive, $closed );
			}
		}
	}

	public function checkLastActivity( $wiki, $inactive, $closed ) {
		$dbr = wfGetDB( DB_SLAVE );
		$dbr->selectDB( $wiki );

		$res = $dbr->selectRow(
			'recentchanges',
			'rc_timestamp',
			array(
    		    // Exclude our Mediawiki:Sitenotice from edits so that we don't get 60 days after 45
				"NOT (rc_namespace = 8" .
				" AND rc_title = 'Sitenotice'" .
				" AND rc_comment = 'Inactivity warning')"
			),
			__METHOD__,
			array(
				'ORDER BY' => 'rc_timestamp DESC'
			)
		);

		// Wiki doesn't seem inactive: go on to the next wiki.
		if ( isset( $res->rc_timestamp ) && $res->rc_timestamp > date( "YmdHis", strtotime( "-45 days" ) ) ) {
			if ( $this->hasOption( 'warn' ) && $inactive ) {
				$this->unWarnWiki( $wiki );
			}

			return true;
		}

		if ( isset( $res->rc_timestamp ) && $res->rc_timestamp < date( "YmdHis", strtotime( "-60 days" ) ) ) {
			if ( $this->hasOption( 'close' ) && $inactive ) {
				$this->closeWiki( $wiki );
				$this->emailBureaucrats( $wiki );
				$this->output( "Wiki {$wiki} was eligible for closing and it was.\n" );
			} else {
				$this->output( "It looks like {$wiki} should be closed. Timestamp of last recent changes entry: {$res->rc_timestamp}\n" );
			}
		} elseif ( isset( $res->rc_timestamp ) && $res->rc_timestamp < date( "YmdHis", strtotime( "-45 days" ) ) ) {
			if ( $this->hasOption( 'warn' ) && !$closed ) {
				$this->warnWiki( $wiki );
				$this->output( "Wiki {$wiki} was eligible for a warning notice and one was given.\n" );
			} else {
				$this->output( "It looks like {$wiki} should get a warning notice. Timestamp of last recent changes entry: {$res->rc_timestamp}\n" );
			}
		} else {
			if ( $this->hasOption( 'warn' ) && !$closed ) {
				$this->warnWiki( $wiki );
				$this->output( "No recent changes entries have been found for {$wiki}. Therefore marking as inactive.\n" );
			} else {
				$this->output( "No recent changes have been found for {$wiki}.\n" );
			}
		}

		return true;
	}

	public function closeWiki( $wiki ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_SLAVE );
		$dbw->selectDB( $wgCreateWikiDatabase ); // force this

		$dbw->query( 'UPDATE cw_wikis SET wiki_closed=1,wiki_closed_timestamp=' . $dbw->timestamp() . ',wiki_inactive=0,wiki_inactive_timestamp=NULL WHERE wiki_dbname=' . $dbw->addQuotes( $wiki ) . ';' );

		return true;
	}

	public function warnWiki( $wiki ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_SLAVE );
		$dbw->selectDB( $wgCreateWikiDatabase );

		$dbw->query( 'UPDATE cw_wikis SET wiki_inactive=1,wiki_inactive_timestamp=' . $dbw->timestamp() . ' WHERE wiki_dbname=' . $dbw->addQuotes( $wiki ) . ';' );

		return true;
	}

	public function unWarnWiki( $wiki ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_SLAVE );
		$dbw->selectDB( $wgCreateWikiDatabase );

		$dbw->query( 'UPDATE cw_wikis SET wiki_inactive=0,wiki_inactive_timestamp=NULL WHERE wiki_dbname=' . $dbw->addQuotes( $wiki ) . ';' );

		return true;
	}

	public function emailBureaucrats( $wiki ) {
		global $wgPasswordSender, $wgSitename;
		$dbr = wfGetDB( DB_MASTER );
		$dbr->selectDB( $wiki );
		$bureaucrats = $dbr->select(
			array( 'user', 'user_groups' ),
			array( 'user_email', 'user_name' ),
			array( 'ug_group' => 'bureaucrat' ),
			__METHOD__,
			array(),
			array( 'user_groups' => array( 'INNER JOIN', array( 'user_id=ug_user' ) ) )
		);

		foreach ( $bureaucrats as $users ) {
			$emails[] = new MailAddress( $users->user_email, $users->user_name );
		}

		$from = new MailAddress( $wgPasswordSender, wfMessage('createwiki-close-email-sender' ));
		$subject = wfMessage( 'miraheze-close-email-subject', $wiki )->inContentLanguage()->text();
		$body = wfMessage('miraheze-close-email-body' )->inContentLanguage()->text();
		return UserMailer::send( $emails, $from, $subject, $body );
	}
}

$maintClass = 'FindInactiveWikis';
require_once RUN_MAINTENANCE_IF_MAIN;
