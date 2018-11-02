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
* @version 2.0
*/

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class ManageInactiveWikis extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'warn', 'Actually warn wikis which are considered inactive but not closable yet', false, false );
		$this->addOption( 'close', 'Actually close wikis which are considered inactive and closable.', false, false );
		$this->mDescription = 'A script to find inactive wikis in a farm.';
	}

	public function execute() {
		global $wgCreateWikiInactiveWikisWhitelist, $wgCreateWikiDatabase, $wgCreateWikiGlobalWiki;

		$dbr = wfGetDB( DB_REPLICA );
		$dbr->selectDB( $wgCreateWikiDatabase ); // force this

		$res = $dbr->select(
			'cw_wikis',
			[
				'wiki_dbname',
				'wiki_inactive',
				'wiki_inactive_timestamp',
				'wiki_closed',
				'wiki_closed_timestamp',
				'wiki_creation',
			],
			[],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbName = $row->wiki_dbname;
			$inactive = $row->wiki_inactive;
			$inactiveDate = $row->wiki_inactive_timestamp;
			$closed = $row->wiki_closed;
			$closedDate = $row->wiki_closed_timestamp;
			$wikiCreation = $row->wiki_creation;

			if ( in_array( $dbName, $wgCreateWikiInactiveWikisWhitelist ) ) {
				continue; // Wiki is in whitelist, do not check.
			}

			if ( $wikiCreation < date( "YmdHis", strtotime( "-45 days" ) ) ) {
				$this->checkLastActivity( $dbName, $inactive, $inactiveDate, $closed, $closedDate );
			}
		}
	}

	public function checkLastActivity( $dbName, $inactive, $inactiveDate, $closed, $closedDate  ) {
		$dbr = wfGetDB( DB_REPLICA );
		$dbr->selectDB( $dbName );

		$lastEntryObj = $dbr->selectRow(
			'recentchanges',
			'rc_timestamp',
			[
				"rc_log_action != 'renameuser'"
			],
			__METHOD__,
			[
				'ORDER BY' => 'rc_timestamp DESC',
			]
		);

		// Wiki doesn't seem inactive: go on to the next wiki.
		if ( isset( $lastEntryObj->rc_timestamp ) && $lastEntryObj->rc_timestamp > date( "YmdHis", strtotime( "-45 days" ) ) ) {
			if ( $this->hasOption( 'warn' ) && $inactive ) {
				$this->unWarnWiki( $dbName );
			}

			return true;
		}

		if ( !$closed ) {
			// Wiki is NOT closed yet
			if ( isset( $lastEntryObj->rc_timestamp ) && $lastEntryObj->rc_timestamp < date( "YmdHis", strtotime( "-60 days" ) ) ) {
				// Last RC entry older than 60 days
				if ( $this->hasOption( 'close' ) ) {
					$this->closeWiki( $dbName );
					$this->emailBureaucrats( $dbName );
					$this->output( "{$dbName} was eligible for closing and has been closed now.\n" );
				} else {
					$this->output( "{$dbName} should be closed. Timestamp of last recent changes entry: {$lastEntryObj->rc_timestamp}\n" );
				}
			} elseif ( isset( $lastEntryObj->rc_timestamp ) && $lastEntryObj->rc_timestamp < date( "YmdHis", strtotime( "-45 days" ) ) ) {
				// Last RC entry older than 45 days but newer than 60 days
				if ( $this->hasOption( 'warn' ) ) {
					$this->warnWiki( $dbName );
					$this->output( "{$dbName} was eligible for a warning notice and one was given.\n" );
				} else {
					$this->output( "{$dbName} should get a warning notice. Timestamp of last recent changes entry: {$lastEntryObj->rc_timestamp}\n" );
				}
			} else {
				// No RC entries, but wiki is already 45+ days old
				if ( !$inactive ) {
					// Wiki not marked inactive yet, warning should be given
					if ( $this->hasOption( 'warn' ) ) {
						$this->warnWiki( $dbName );
						$this->output( "{$dbName} does not seem to contain recentchanges entries, therefore warning.\n" );
					} else {
						$this->output( "{$dbName} does not seem to contain recentchanges entries, eligible for warning.\n" );
					}
			    	} elseif ( $inactiveDate && $inactiveDate < date( "YmdHis", strtotime( "-15 days" ) ) ) {
					// Wiki already warned 15+ days ago, eligible for closure
					if ( $this->hasOption( 'close' ) ) {
						$this->closeWiki( $dbName );
						$this->output( "{$dbName} does not seem to contain recentchanges entries after 15+ days warning, therefore closing.\n" );
					} else {
						$this->output( "{$dbName} does not seem to contain recentchanges entries after 15+ days warning, eligible for closure.\n" );
					}
				} else {
					// Wiki warned 0-15 days ago
					$this->output( "{$dbName} does not seem to contain recentchanges entries, warned 0-15 days ago.\n" );
				}
			}
		} else {
			// Wiki already has been closed
			if ( $closedDate && $closedDate < date( "YmdHis", strtotime( "-120 days" ) ) ) {
				// Wiki closed 120 days ago or longer; eligible for deletion
				$this->output( "{$dbName} is eligible for deletion, has been closed on {$closedDate}.\n" );
			} elseif ( $closedDate && $closedDate > date( "YmdHis", strtotime( "-120 days" ) ) ) {
				// Wiki closed but not 120 days ago yet
				$this->output( "{$dbName} is not eligible for deletion yet, but has already been closed on {$closedDate}.\n" );
			} else {
				// Could not determine closure date, fallback
				$this->output( "{$dbName} has already been closed but its closure date could not be determined. Please check!\n" );
			}
		}

		return true;
	}

	public function closeWiki( $wikiDb ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_REPLICA );
		$dbw->selectDB( $wgCreateWikiDatabase );

		$dbw->update(
			'cw_wikis',
			[
				'wiki_closed' => '1',
				'wiki_closed_timestamp' => $dbw->timestamp(), 
				'wiki_inactive' => '0',
				'wiki_inactive_timestamp' => 'NULL', // Consistency
			],
			[
				'wiki_dbname' => $wikiDb
			],
			__METHOD__
		);

		Hooks::run( 'CreateWikiStateClosed', [ $wikiDb ] );

		return true;
	}

	public function warnWiki( $wikiDb ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_REPLICA );
		$dbw->selectDB( $wgCreateWikiDatabase );

		$dbw->update(
			'cw_wikis',
			[
				'wiki_inactive' => '1',
				'wiki_inactive_timestamp' => $dbw->timestamp(),
			],
			[
				'wiki_dbname' => $wikiDb
			],
			__METHOD__
		);

		Hooks::run( 'CreateWikiStateInactive', [ $wikiDb ] );

		return true;
	}

	public function unWarnWiki( $wikiDb ) {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_REPLICA );
		$dbw->selectDB( $wgCreateWikiDatabase );

		$dbw->update(
			'cw_wikis',
			[
				'wiki_closed' => '0',
				'wiki_closed_timestamp' => 'NULL', // Consistency
				'wiki_inactive' => '0',
				'wiki_inactive_timestamp' => 'NULL', // Consistency
			],
			[
				'wiki_dbname' => $wikiDb
			],
			__METHOD__
		);

		Hooks::run( 'CreateWikiStateActive', [ $wikiDb ] );

		return true;
	}

	public function emailBureaucrats( $wikiDb ) {
		global $wgPasswordSender, $wgSitename;

		$dbr = wfGetDB( DB_REPLICA );
 		$dbr->selectDB( $wikiDb );

		$bureaucrats = $dbr->select(
			[ 'user', 'user_groups' ],
			[ 'user_email', 'user_name' ],
			[ 'ug_group' => 'bureaucrat' ],
			__METHOD__,
			[],
			[
				'user_groups' => [
					'INNER JOIN',
					[ 'user_id=ug_user' ]
				]
			]
		);

		foreach ( $bureaucrats as $users ) {
			$emails[] = new MailAddress( $users->user_email, $users->user_name );
		}

		$from = new MailAddress( $wgPasswordSender, wfMessage( 'createwiki-close-email-sender' ) );
		$subject = wfMessage( 'miraheze-close-email-subject', $wikiDb )->inContentLanguage()->text();
		$body = wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text();

		return UserMailer::send( $emails, $from, $subject, $body );
	}
}

$maintClass = 'ManageInactiveWikis';
require_once RUN_MAINTENANCE_IF_MAIN;
