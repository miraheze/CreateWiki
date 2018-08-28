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
	// Database object holding $wgCreateWikiDatabase DB_MASTER connection
	public $createWikiDbw;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'warn', 'Actually warn wikis which are considered inactive but not closable yet', false, false );
		$this->addOption( 'close', 'Actually close wikis which are considered inactive and closable.', false, false );
		$this->mDescription = 'A script to find inactive wikis in a farm.';
	}

	public function execute() {
		global $wgCreateWikiInactiveWikisWhitelist, $wgCreateWikiDatabase;

		$this->createWikiDbw = wfGetDB( DB_REPLICA, [], $wgCreateWikiDatabase );

		$res = $this->createWikiDbw->select(
			'cw_wikis',
			'wiki_dbname',
			[],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbname = $row->wiki_dbname;

			if ( in_array( $dbname, $wgCreateWikiInactiveWikisWhitelist ) ) {
				continue; // Wiki is in whitelist, do not check.
			}

			$wikiObj = RemoteWiki::newFromName( $dbname );

			if ( $wikiObj->getCreationDate() < date( "YmdHis", strtotime( "-45 days" ) ) ) {
				$this->checkLastActivity( $wikiObj );
			}
		}
	}

	public function checkLastActivity( $wikiObj ) {
		$wiki = $wikiObj->getDBname();

		$dbr = wfGetDB( DB_REPLICA, [], $wiki );
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

		$dbr->close(); // minimize simultaneous connections

		// Wiki doesn't seem inactive: go on to the next wiki.
		if ( isset( $lastEntryObj->rc_timestamp ) && $lastEntryObj->rc_timestamp > date( "YmdHis", strtotime( "-45 days" ) ) ) {
			if ( $this->hasOption( 'warn' ) && $wikiObj->isInactive() ) {
				$this->unWarnWiki( $wiki );
			}

			return true;
		}

		if ( !$wikiObj->isClosed() ) {
			// Wiki is NOT closed yet
			if ( isset( $lastEntryObj->rc_timestamp ) && $lastEntryObj->rc_timestamp < date( "YmdHis", strtotime( "-60 days" ) ) ) {
				// Last RC entry older than 60 days
				if ( $this->hasOption( 'close' ) ) {
					$this->closeWiki( $wiki );
					$this->emailBureaucrats( $wiki );
					$this->output( "{$wiki} was eligible for closing and has been closed now.\n" );
				} else {
					$this->output( "{$wiki} should be closed. Timestamp of last recent changes entry: {$lastEntryObj->rc_timestamp}\n" );
				}
			} elseif ( isset( $lastEntryObj->rc_timestamp ) && $lastEntryObj->rc_timestamp < date( "YmdHis", strtotime( "-45 days" ) ) ) {
				// Last RC entry older than 45 days but newer than 60 days
				if ( $this->hasOption( 'warn' ) ) {
					$this->warnWiki( $wiki );
					$this->output( "{$wiki} was eligible for a warning notice and one was given.\n" );
				} else {
					$this->output( "{$wiki} should get a warning notice. Timestamp of last recent changes entry: {$lastEntryObj->rc_timestamp}\n" );
				}
			} else {
				// No RC entries, but wiki is already 45+ days old
				if ( !$wikiObj->isInactive() ) {
					// Wiki not marked inactive yet, warning should be given
					if ( $this->hasOption( 'warn' ) ) {
						$this->warnWiki( $wiki );
						$this->output( "{$wiki} does not seem to contain recentchanges entries, therefore warning.\n" );
					} else {
						$this->output( "{$wiki} does not seem to contain recentchanges entries, eligible for warning.\n" );
					}
			    	} elseif ( $wikiObj->getInactiveDate() && $wikiObj->getInactiveDate() < date( "YmdHis", strtotime( "-15 days" ) ) ) {
					// Wiki already warned 15+ days ago, eligible for closure
					if ( $this->hasOption( 'close' ) ) {
						$this->closeWiki( $wiki );
						$this->output( "{$wiki} does not seem to contain recentchanges entries after 15+ days warning, therefore closing.\n" );
					} else {
						$this->output( "{$wiki} does not seem to contain recentchanges entries after 15+ days warning, eligible for closure.\n" );
					}
				} else {
					// Wiki warned 0-15 days ago
					$this->output( "{$wiki} does not seem to contain recentchanges entries, warned 0-15 days ago.\n" );
				}
			}
		} else {
			// Wiki already has been closed
			$closureDate = $wikiObj->getClosureDate();

			if ( $closureDate && $closureDate < date( "YmdHis", strtotime( "-120 days" ) ) ) {
				// Wiki closed 120 days ago or longer; eligible for deletion
				$this->output( "{$wiki} is eligible for deletion, has been closed on {$closureDate}.\n" );
			} elseif ( $closureDate && $closureDate > date( "YmdHis", strtotime( "-120 days" ) ) ) {
				// Wiki closed but not 120 days ago yet
				$this->output( "{$wiki} is not eligible for deletion yet, but has already been closed on {$closureDate}.\n" );
			} else {
				// Could not determine closure date, fallback
				$this->output( "{$wiki} has already been closed but its closure date could not be determined. Please check!\n" );
			}
		}

		return true;
	}

	public function closeWiki( $wikiDb ) {
		$this->createWikiDbw->update(
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

		return true;
	}

	public function warnWiki( $wikiDb ) {
		$this->createWikiDbw->update(
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

		return true;
	}

	public function unWarnWiki( $wikiDb ) {
		$this->createWikiDbw->update(
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

		return true;
	}

	public function emailBureaucrats( $wikiDb ) {
		global $wgPasswordSender, $wgSitename;

		$dbr = wfGetDB( DB_REPLICA, [], $wikiDb );

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

		$dbr->close(); // minimize simultaneous connections

		foreach ( $bureaucrats as $users ) {
			$emails[] = new MailAddress( $users->user_email, $users->user_name );
		}

		$from = new MailAddress( $wgPasswordSender, wfMessage( 'createwiki-close-email-sender' ));
		$subject = wfMessage( 'miraheze-close-email-subject', $wikiDb )->inContentLanguage()->text();
		$body = wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text();

		return UserMailer::send( $emails, $from, $subject, $body );
	}
}

$maintClass = 'ManageInactiveWikis';
require_once RUN_MAINTENANCE_IF_MAIN;
