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
 */
require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class RenameWiki extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "Renames a wiki from it's original name to a new name. Will NOT perform core database operations so run AFTER new database exists and while old one still exists.";
		$this->addOption( 'rename', 'Performs the rename. If not, will output rename information.', false );
		$this->addArg( 'oldwiki', 'Old wiki database name', true );
		$this->addArg( 'newwiki', 'New wiki database name', true );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );
	}

	function execute() {
		global $wgCreateWikiDatabase, $wgCreateWikiNotificationEmail, $wgPasswordSender;

		$oldwiki = $this->getArg( 0 );
		$newwiki = $this->getArg( 1 );

		$renamedWiki = [];
		$dbw = $this->getDB( DB_MASTER, [], $wgCreateWikiDatabase );

		if ( ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			$cadbw = CentralAuthUser::getCentralDB();
		}

		if ( $this->hasOption( 'rename' ) ) {
				$this->output( "Renaming $oldwiki to $newwiki. If this is wrong, Ctrl-C now!" );
				$this->countDown( 10 ); // let's count down JUST to be safe!

				$this->doRename( $dbw, 'cw_wikis', 'wiki_dbname', $oldwiki, $newwiki );

				if ( $cadbw ) {
					$this->doRename( $cadbw, 'localuser', 'lu_wiki', $oldwiki, $newwiki );
					$this->doRename( $cadbw, 'localnames', 'ln_wiki', $oldwiki, $newwiki );
				} else {
					$this->output( "CentralAuth is not installed. If you are not running a hook for your local user management, users will not exist on the new wiki. If you are using a hook, ignore this or considering contributing your method upstream at https://github.com/miraheze/CreateWiki!" );
				}

				Hooks::run( 'CreateWikiRename', [ $dbw, $oldwiki, $newwiki ] );

				$renamedWiki[] = $oldwiki;
				$renamedWiki[] = $newwiki;
			} else {
				$this->output( "Wiki $oldwiki will be renamed to $newwiki" );
			}

		$this->output( "Done.\n" );

		if ( $this->hasOption( 'rename' ) ) {
			$this->notifyRename( $wgCreateWikiNotificationEmail, $wgPasswordSender, $renamedWiki, $this->getArg( 2 ) );
		}
	}

	/**
	 * @param DatabaseBase $dbw
	 * @param string $table
	 * @param string $column
	 * @param string $old
	 * @param string $new
	 */
	public static function doRename( $dbw, $table, $column, $old, $new ) {
		echo "$table:\n";
		$count = 0;
		do {
			$dbw->update(
				$table,
				[ $column => $new ],
				[ $column => $old ],
				__METHOD__
			);
			$affected = $dbw->affectedRows();
			$count += $affected;
			echo "$count\n";
			wfWaitForSlaves();
		} while ( $affected === 500 );
		echo "$count $table rows updated\n";
	}

	protected function notifyRename( $to, $from, $wikidata, $user ) {
		$from = new MailAddress( $from, 'CreateWiki Notifications' );
		$to = new MailAddress( $to, 'Server Administrators' );
		$wikirename = implode( ' to ', $wikidata );
		$body = "Hello!\nThis is an automatic notification from CreateWiki notifying you that just now $user has rename the following wiki from CreateWiki and associated extensions - From $wikirename.";

		return UserMailer::send( $to, $from, 'Wiki Rename Notification', $body );
	}
}
$maintClass = 'RenameWiki';
require_once( DO_MAINTENANCE );
