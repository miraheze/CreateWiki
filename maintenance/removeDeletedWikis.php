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

class RemoveDeletedWikis extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "Remove any remaining entries in globalimagelinks, localuser and localnames for deleted wikis.\n";
	}
	function execute() {
		global $wgCreateWikiDBDirectory;

		$wikis = file( "$wgCreateWikiDBDirectory/deleted.dblist" );
		if ( $wikis === false ) {
			$this->error( 'Unable to open deleted.dblist', 1 );
		}
		$dbw = $this->getDB( DB_MASTER );
		$cadbw = CentralAuthUser::getCentralDB();
		foreach ( $wikis as $wiki ) {
			$wiki = rtrim( $wiki );
			$this->output( "$wiki:\n" );
			$this->doDeletes( $cadbw, 'localnames', 'ln_wiki', $wiki );
			$this->doDeletes( $cadbw, 'localuser', 'lu_wiki', $wiki );
			// @todo: Delete from wikisets
		}
		$this->output( "Done.\n" );
	}
	/**
	 * @param DatabaseBase $dbw
	 * @param string $table
	 * @param string $column
	 * @param string $wiki
	 */
	function doDeletes( $dbw, $table, $column, $wiki ) {
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
}
$maintClass = 'RemoveDeletedWikis';
require_once( DO_MAINTENANCE );
