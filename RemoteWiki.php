<?php
class RemoteWiki {
	private function __construct( $dbname, $sitename, $language, $private, $closed, $settings ) {
		$this->dbname = $dbname;
		$this->sitename = $sitename;
		$this->language = $language;
		$this->private = $private == 1 ? true : false;
		$this->closed = $closed == 1 ? true : false;
		$this->settings = $settings;
		$this->creationDate = $this->determineCreationDate();
	}

	public static function newFromName( $dbname ) {
		return self::newFromConds( array( 'wiki_dbname' => $dbname ) );
	}

	protected static function newFromConds(
		$conds,
		$fname = __METHOD__,
		$dbType = DB_MASTER
	) {
		global $wgDBname;

		// We want to switch back after we queried cw_wikis
		$dbName = $wgDBname;

		$db = wfGetDB( $dbType );
		$db->selectDB( 'metawiki' ); // cw_wikis DB

		$row = $db->selectRow( 'cw_wikis', self::selectFields(), $conds, $fname );

		$db->selectDB( $dbName );

		if ( $row->wiki_dbname !== false ) {
			return new self( $row->wiki_dbname, $row->wiki_sitename, $row->wiki_language, $row->wiki_private, $row->wiki_closed, $row->wiki_settings );
		} else {
			return null;
		}
	}

	private function determineCreationDate() {
		global $wgDBname;

		$dbName = $wgDBname;

		$dbw = wfGetDB( DB_MASTER );
		$dbw->selectDB( 'metawiki' );

		$res = $dbw->selectField(
			'logging',
			'log_timestamp',
			array(
				'log_action' => 'createwiki',
				'log_params' => serialize( array( '4::wiki' => $this->dbname ) )
			),
			__METHOD__,
			array( // Sometimes a wiki might have been created multiple times.
				'ORDER BY' => 'log_timestamp DESC'
			)
		);

		$dbw->selectDB( $dbName );

		return is_string( $res ) ? $res : null;
	}

	public static function selectFields() {
		return array(
			'wiki_dbname',
			'wiki_sitename',
			'wiki_language',
			'wiki_private',
			'wiki_closed',
			'wiki_settings'
		);
	}

	public function getCreationDate() {
		return $this->creationDate;
	}

	public function getDBname() {
		return $this->dbname;
	}

	public function getSitename() {
		return $this->sitename;
	}

	public function getLanguage() {
		return $this->language;
	}

	public function isPrivate() {
		return $this->private;
	}

	public function isClosed() {
		return $this->closed;
	}
}
