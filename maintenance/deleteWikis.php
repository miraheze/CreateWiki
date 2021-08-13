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

use MediaWiki\MediaWikiServices;

class DeleteWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Allows complete deletion of wikis with args controlling deletion levels. Will never DROP a database!";
		$this->addOption( 'delete', 'Actually performs deletions and not outputs wikis to be deleted', false );
		$this->addArg( 'user', 'Username or reference name of the person running this script. Will be used in tracking and notification internally.', true );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbw = wfGetDB( DB_PRIMARY, [], $config->get( 'CreateWikiDatabase' ) );

		$res = $dbw->select(
			'cw_wikis',
			'*',
			[
				'wiki_deleted' => 1
			]
		);

		$deletedWikis = [];

		foreach ( $res as $row ) {
			$wiki = $row->wiki_dbname;
			if ( $this->hasOption( 'delete' ) ) {
				$wm = new WikiManager( $wiki );

				$delete = $wm->delete();

				if ( $delete ) {
					$this->output( "{$wiki}: {$delete}\n" );
					continue;
				}

				$this->output( "DROP DATABASE {$wiki};\n" );
				$deletedWikis[] = $wiki;
			} else {
				$this->output( "$wiki\n" );
			}
		}

		$this->output( "Done.\n" );

		$deletionData = [
			'deletedWikis' => implode( ', ', $deletedWikis ),
			'user' => $this->getArg( 0 )
		];

		WikiManager::notificationsTrigger( 'deletion', $deletionData );
	}
}

$maintClass = 'DeleteWiki';
require_once( RUN_MAINTENANCE_IF_MAIN );
