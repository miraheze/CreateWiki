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

use MediaWiki\MediaWikiServices;

class RenameWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Renames a wiki from it's original name to a new name. Will NOT perform core database operations so run AFTER new database exists and while old one still exists.";
		$this->addOption( 'rename', 'Performs the rename. If not, will output rename information.', false );
		$this->addArg( 'oldwiki', 'Old wiki database name', true );
		$this->addArg( 'newwiki', 'New wiki database name', true );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$oldwiki = $this->getArg( 0 );
		$newwiki = $this->getArg( 1 );

		$renamedWiki = [];

		if ( $this->hasOption( 'rename' ) ) {
				$this->output( "Renaming $oldwiki to $newwiki. If this is wrong, Ctrl-C now!" );
				// let's count down JUST to be safe!
				$this->countDown( 10 );

				$wm = new WikiManager( $oldwiki );

				$rename = $wm->rename( $newwiki );

				if ( $rename ) {
					$this->output( "{$rename}" );
					return;
				}

				$dbw = wfGetDB( DB_PRIMARY, [], $config->get( 'CreateWikiDatabase' ) );

				Hooks::run( 'CreateWikiRename', [ $dbw, $oldwiki, $newwiki ] );

				$renamedWiki[] = $oldwiki;
				$renamedWiki[] = $newwiki;
			} else {
				$this->output( "Wiki $oldwiki will be renamed to $newwiki" );
			}

		$this->output( "Done.\n" );

		if ( $this->hasOption( 'rename' ) ) {
			$this->notifyRename( $config->get( 'CreateWikiNotificationEmail' ), $config->get( 'PasswordSender' ), $renamedWiki, $this->getArg( 2 ) );
		}
	}

	private function notifyRename( $to, $from, $wikidata, $user ) {
		$from = new MailAddress( $from, 'CreateWiki Notifications' );
		$to = new MailAddress( $to, 'Server Administrators' );
		$wikirename = implode( ' to ', $wikidata );
		$body = "Hello!\nThis is an automatic notification from CreateWiki notifying you that just now $user has renamed the following wiki from CreateWiki and associated extensions - From $wikirename.";

		return UserMailer::send( $to, $from, 'Wiki Rename Notification', $body );
	}
}
$maintClass = RenameWiki::class;
require_once( RUN_MAINTENANCE_IF_MAIN );
