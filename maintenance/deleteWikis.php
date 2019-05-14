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
 * @ingroup Wikimedia
 */
require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class DeleteWiki extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "Allows complete deletion of wikis with args controlling deletion levels. Will never DROP a database!";
		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );
	}

	function execute() {
		global $wgCreateWikiDatabase, $wgCreateWikiNotificationEmail, $wgPasswordSender;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$res = $dbw->select(
			'cw_wikis',
			'*',
			[
				'wiki_deleted' => 1
			]
		);

		$deletedWiki = [];

		foreach ( $res as $row ) {
			$wiki = $row->wiki_dbname;
			if ( $this->hasOption( 'delete' ) ) {
				$wm = new WikiManager( $wiki );

				$delete = $wm->delete();

				if ( $delete ) {
					$this->output( "{$wiki}: {$delete}\n" );
					return;
				}

				// pass database connection to minimise connections and wiki name to extensions for their specific deletion stuff.
				Hooks::run( 'CreateWikiDeletion', [ $dbw, $wiki ] );

				$this->output( "DROP DATABASE {$wiki};" );
				$deletedWiki[] = $wiki;
			} else {
				$this->output( "$wiki\n" );
			}
		}
		$this->output( "Done.\n" );

		$this->notifyDeletions( $wgCreateWikiNotificationEmail, $wgPasswordSender, $deletedWiki, $this->getArg( 0 ) );
	}

	private function notifyDeletions( $to, $from, $wikis, $user ) {
		$from = new MailAddress( $from, 'CreateWiki Notifications' );
		$to = new MailAddress( $to, 'Server Administrators' );
		$wikilist = implode( ', ', $wikis );
		$body = "Hello!\nThis is an automatic notification from CreateWiki notifying you that just now $user has deleted the following wikis from the CreateWiki and associated extensions:\n$wikilist";

		return UserMailer::send( $to, $from, 'Wikis Deleted Notification', $body );
	}
}
$maintClass = 'DeleteWiki';
require_once( DO_MAINTENANCE );
