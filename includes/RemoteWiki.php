<?php
class RemoteWiki {
	private function __construct( $dbname, $sitename, $language, $private, $wikiCreation, $closed, $closedDate, $inactive, $inactiveDate, $inactiveExempt, $settings, $category, $extensions ) {
		$this->dbname = $dbname;
		$this->sitename = $sitename;
		$this->language = $language;
		$this->private = $private == 1 ? true : false;
		$this->wikiCreation = $wikiCreation;
		$this->closed = $closed == 1 ? true : false;
		$this->inactive = $inactive == 1 ? true : false;
		$this->inactiveExempt = $inactiveExempt == 1 ? true : false;
		$this->settings = $settings;
		$this->closureDate = $closedDate;
		$this->creationDate = $this->determineCreationDate();
		$this->inactiveDate = $inactiveDate;
		$this->category = $category;
		$this->extensions = $extensions;
	}

	public static function newFromName( $dbname ) {
		return self::newFromConds( array( 'wiki_dbname' => $dbname ) );
	}

	protected static function newFromConds(
		$conds
	) {
		global $wgCreateWikiDatabase;

		$row = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase )->selectRow( 'cw_wikis', self::selectFields(), $conds, __METHOD__ );

		if ( $row !== false ) {
			return new self(
				$row->wiki_dbname,
				$row->wiki_sitename,
				$row->wiki_language,
				$row->wiki_private,
				$row->wiki_creation,
				$row->wiki_closed,
				$row->wiki_closed_timestamp,
				$row->wiki_inactive,
				$row->wiki_inactive_timestamp,
				$row->wiki_inactive_exempt,
				$row->wiki_settings,
				$row->wiki_category,
				$row->wiki_extensions
			);
		} else {
			return null;
		}
	}

	private function determineCreationDate() {
		return $this->wikiCreation;
	}

	public static function selectFields() {
		return array(
			'wiki_dbname',
			'wiki_sitename',
			'wiki_language',
			'wiki_private',
			'wiki_creation',
			'wiki_closed',
			'wiki_closed_timestamp',
			'wiki_inactive',
			'wiki_inactive_timestamp',
			'wiki_inactive_exempt',
			'wiki_settings',
			'wiki_category',
			'wiki_extensions'
		);
	}

	public function getCreationDate() {
		return $this->creationDate;
	}

	public function getClosureDate() {
		return $this->closureDate;
	}

	public function getInactiveDate() {
		return $this->inactiveDate;
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

	public function isInactive() {
		return $this->inactive;
	}

	public function isInactiveExempt() {
		return $this->inactiveExempt;
	}

	public function isPrivate() {
		return $this->private;
	}

	public function isClosed() {
		return $this->closed;
	}

	public function closureDate() {
		return $this->closureDate;
	}

	public function getCategory() {
		return $this->category;
	}

	public function getExtensions() {
		return $this->extensions;
	}

	public function hasExtension( $extension ) {
		$extensionsarray = explode( ",", $this->extensions );

		return in_array( $extension, $extensionsarray );
	}

	public function getSettings() {
		return json_decode( $this->settings, true );
	}

	public function getSettingsValue( $setting ) {
		$settingsarray = $this->getSettings();

		if ( isset( $settingsarray[$setting] ) ) {
			return $settingsarray[$setting];
		}
		
		return null;
	}
}
