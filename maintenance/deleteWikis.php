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

class DeleteWikis extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "Allows complete deletion of wikis with args controlling deletion levels. Will never DROP a database!";
		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );
	}

	function execute() {
		global $wgCreateWikiDBDirectory, $wgCreateWikiDatabase, $wgCreateWikiNotificationEmail, $wgPasswordSender;

		$wikis = file( "$wgCreateWikiDBDirectory/deleted.dblist" );

		if ( $wikis === false ) {
			$this->error( 'Unable to open deleted.dblist', 1 );
		}
		$deletedWiki = [];
		$dbw = $this->getDB( DB_MASTER, [], $wgCreateWikiDatabase );

		// check CA is installed
		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			$cadbw = CentralAuthUser::getCentralDB();
		}

		foreach ( $wikis as $wiki ) {
			$wiki = rtrim( $wiki );
			if ( $this->hasOption( 'delete' ) ) {
				$this->output( "$wiki:\n" );

				$this->doDeletes( $dbw, 'cw_wikis', 'wiki_dbname', $wiki );

				if ( $cadbw ) {
					$this->doDeletes( $cadbw, 'localnames', 'ln_wiki', $wiki );
					$this->doDeletes( $cadbw, 'localuser', 'lu_wiki', $wiki );
				} else {
					$this->output( "CentralAuth is not installed. If you are not running a hook for your local user management, users will not be deleted and may cause database errors. If you are using a hook, ignore this or consider contributing your method upstream at https://github.com/miraheze/CreateWiki!" );
				}

				// pass database connection to minimise connections and wiki name to extensions for their specific deletion stuff.
				Hooks::run( 'CreateWikiDeletion', [ $dbw, $wiki ] );

				$deletedWiki[] = $wiki;
			} else {
				$this->output( "$wiki\n" );
			}
		}
		$this->output( "Done.\n" );

		$this->notifyDeletions( $wgCreateWikiNotificationEmail, $wgPasswordSender, $deletedWiki, $this->getArg( 'user' ) );
	}

	/**
	 * @param DatabaseBase $dbw
	 * @param string $table
	 * @param string $column
	 * @param string $wiki
	 */
	public static function doAction( $dbw, $table, $column, $wiki ) {
		if ( !$dbw->tableExists( $table ) ) {
			$this->error( "Maintenance script cannot be run on this wiki as there is no $table table", 1 );
		}
		$this->output( "$table:\n" );
		$count = 0;
		do {
			$wikiQuoted = $dbw->addQuotes( $wiki );
			$dbw->query(
				"DELETE FROM $table WHERE $column=$wikiQuoted LIMIT 500",
				__METHOD__
			);
			$affected = $dbw->affectedRows();
			$count += $affected;
			$this->output( "$count\n" );
			wfWaitForSlaves();
		} while ( $affected === 500 );
		$this->output( "$count $table rows deleted\n" );
	}

	protected function notifyDeletions( $to, $from, $wikis, $user ) {
		$from = new MailAddress( $from, 'CreateWiki Notifications' );
		$to = new MailAddress( $to, 'Server Administrators' );
		$wikilist = implode( ', ', $wikis );
		$body = "Hello!\nThis is an automatic notification from CreateWiki notifying you that just now $user has deleted the following wikis from the CreateWiki and associated extensions:\n$wikilist";

		return UserMailer::send( $to, $from, 'Wikis Deleted Notification', $body );
	}
}
$maintClass = 'DeleteWikis';
require_once( DO_MAINTENANCE );
