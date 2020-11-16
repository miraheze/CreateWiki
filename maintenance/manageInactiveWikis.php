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
* @version 2.1
*/

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

class ManageInactiveWikis extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'write', 'Actually make changes to wikis which are considered for the next stage in dormancy', false, false );
		$this->mDescription = 'A script to find inactive wikis in a farm.';
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );

		$dbw = wfGetDB( DB_MASTER, [], $config->get( 'CreateWikiDatabase' ) );

		$res = $dbw->select(
			'cw_wikis',
			'wiki_dbname',
			[
				'wiki_inactive_exempt' => 0,
				'wiki_deleted' => 0
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbName = $row->wiki_dbname;
			$wiki = new RemoteWiki( $dbName );
			$inactiveDays = (int)$config->get( 'CreateWikiStateDays' )['inactive'];

			if ( $wiki->getCreationDate() < date( "YmdHis", strtotime( "-{$inactiveDays} days" ) ) ) {
				$dbw = wfGetDB( DB_MASTER, [], $dbName );
				$this->checkLastActivity( $dbName, $wiki, $dbw, $config );
			}
		}
	}

	public function checkLastActivity( $dbName, $wiki, $dbw, $config ) {
		$inactiveDays = (int)$config->get( 'CreateWikiStateDays' )['inactive'];
		$closeDays = (int)$config->get( 'CreateWikiStateDays' )['closed'];
		$removeDays = (int)$config->get( 'CreateWikiStateDays' )['removed'];

		$canWrite = $this->hasOption( 'write' );

		$blankConfig = new GlobalVarConfig( '' );

		$lastEntryObj = $dbw->selectRow(
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

		$timeStamp = isset( $lastEntryObj->rc_timestamp ) ? (int)$lastEntryObj->rc_timestamp : false;

		if ( !$timeStamp ) {
      return true;
    }
		
		$timeStamp = $timeStamp->getStdout();

		// Wiki doesn't seem inactive: go on to the next wiki.
		if ( $timeStamp > date( "YmdHis", strtotime( "-{$inactiveDays} days" ) ) ) {
			if ( $canWrite && $wiki->isInactive() ) {
				$wiki->markActive();
				$wiki->commit();
			}

			return true;
		}

		if ( !$wiki->isClosed() ) {
			// Wiki is NOT closed yet
			$closeTime = $inactiveDays + $closeDays;
			if ( $timeStamp < date( "YmdHis", strtotime( "-{$closeTime} days" ) ) ) {
				// Last RC entry older than allowed time
				if ( $canWrite ) {
					$wiki->markClosed();
					$this->emailBureaucrats( $dbName, $config );
					$this->output( "{$dbName} was eligible for closing and has been closed now.\n" );
				} else {
					$this->output( "{$dbName} should be closed. Timestamp of last recent changes entry: {$timeStamp}\n" );
				}
			} elseif ( $timeStamp < date( "YmdHis", strtotime( "-{$inactiveDays} days" ) ) ) {
				// Meets inactivity
				if ( $canWrite ) {
					$wiki->markInactive();
					$this->output( "{$dbName} was eligible for a warning notice and one was given.\n" );
				} else {
					$this->output( "{$dbName} should get a warning notice. Timestamp of last recent changes entry: {$timeStamp}\n" );
				}
			} else {
				// No RC entries
				if ( !$wiki->isInactive() ) {
					// Wiki not marked inactive yet, warning should be given
					if ( $canWrite ) {
						$wiki->markInactive();
						$this->output( "{$dbName} does not seem to contain recentchanges entries, therefore warning.\n" );
					} else {
						$this->output( "{$dbName} does not seem to contain recentchanges entries, eligible for warning.\n" );
					}
				} elseif ( $wiki->isInactive() && $wiki->isInactive() < date( "YmdHis", strtotime( "-{$closeDays} days" ) ) ) {
					// Wiki already warned, eligible for closure
					if ( $canWrite ) {
						$wiki->markClosed();
						$this->output( "{$dbName} does not seem to contain recentchanges entries after {$closeDays}+ days warning, therefore closing.\n" );
					} else {
						$this->output( "{$dbName} does not seem to contain recentchanges entries after {$closeDays}+ days warning, eligible for closure.\n" );
					}
				} else {
					// Wiki warned recently
					$this->output( "{$dbName} does not seem to contain recentchanges entries, warned recently.\n" );
				}
			}
		} else {
			// Wiki already has been closed
			if ( $wiki->isClosed() && $wiki->isClosed() < date( "YmdHis", strtotime( "-{$removeDays} days" ) ) ) {
				// Wiki closed, eligible for deletion
				if ( $canWrite ) {
					$wiki->delete();
					$this->output( "{$dbName} is eligible to be removed from public viewing and has been.\n" );
				} else {
					$this->output( "{$dbName} is eligible for public removal, was closed on {$wiki->isClosed()}.\n" );
				}
			} elseif ( $wiki->isClosed() && $wiki->isClosed() > date( "YmdHis", strtotime( "-{$removeDays} days" ) ) ) {
				// Wiki closed but not yet eligible for removal
				$this->output( "{$dbName} is not eligible for public removal yet, but has already been closed on {$wiki->isClosed()}.\n" );
			} else {
				// Could not determine closure date, fallback
				$this->output( "{$dbName} has already been closed but its closure date could not be determined. Please check!\n" );
			}
		}

		$wiki->commit();
		return true;
	}

	public function emailBureaucrats( $wikiDb, $config ) {
		$dbr = wfGetDB( DB_REPLICA );

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

		$emails = [];
		foreach ( $bureaucrats as $users ) {
			$emails[] = new MailAddress( $users->user_email, $users->user_name );
		}

		$from = new MailAddress( $config->get( 'PasswordSender' ), wfMessage( 'createwiki-close-email-sender' ) );
		$subject = wfMessage( 'miraheze-close-email-subject', $wikiDb )->inContentLanguage()->text();
		$body = wfMessage( 'miraheze-close-email-body' )->inContentLanguage()->text();

		return UserMailer::send( $emails, $from, $subject, $body );
	}
}

$maintClass = 'ManageInactiveWikis';
require_once RUN_MAINTENANCE_IF_MAIN;
