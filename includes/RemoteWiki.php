<?php
class RemoteWiki {
	private function __construct( $dbname, $sitename, $language, $private, $wikiCreation, $closed, $closedDate, $inactive, $inactiveDate, $inactiveExempt, $deleted, $deletionDate, $locked, $category, $url, $extensions, $settings ) {
		$this->dbname = $dbname;
		$this->sitename = $sitename;
		$this->language = $language;
		$this->url = $url;
		$this->private = (bool)$private;
		$this->wikiCreation = $wikiCreation;
		$this->closed = (bool)$closed;
		$this->deleted = (bool)$deleted;
		$this->inactive = (bool)$inactive;
		$this->inactiveExempt = (bool)$inactiveExempt;
		$this->closureDate = $closedDate;
		$this->creationDate = $this->determineCreationDate();
		$this->deletionDate = $deletionDate;
		$this->inactiveDate = $inactiveDate;
		$this->locked = $locked;
		$this->category = $category;
		$this->extensions = $extensions;
		$this->settings = $settings;
	}

	public static function newFromName( $dbname ) {
		return static::newFromConds( [ 'wiki_dbname' => $dbname ] );
	}

	protected static function newFromConds(
		$conds
	) {
		global $wgCreateWikiDatabase;

		$row = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase )->selectRow( 'cw_wikis', static::selectFields(), $conds, __METHOD__ );
		$mwRow = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase)->selectRow( 'mw_settings', '*', [ 's_dbname' => $conds['wiki_dbname'] ] );

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
				$row->wiki_deleted,
				$row->wiki_deleted_timestamp,
				$row->wiki_locked,
				$row->wiki_category,
				$row->wiki_url,
				$mwRow->s_extensions,
				$mwRow->s_settings
			);
		} else {
			return null;
		}
	}

	private function determineCreationDate() {
		return $this->wikiCreation;
	}

	public static function selectFields() {
		return [
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
			'wiki_deleted',
			'wiki_deleted_timestamp',
			'wiki_locked',
			'wiki_settings',
			'wiki_category',
			'wiki_extensions',
			'wiki_url'
		];
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

	public function isDeleted() {
		return $this->deleted;
	}

	public function deletionDate() {
		return $this->deletionDate;
	}

	public function isLocked() {
		return $this->locked;
	}

	public function getCategory() {
		return $this->category;
	}

	public function getExtensions() {
		return json_decode( $this->extensions, true );
	}

	public function hasExtension( $extension ) {
		return in_array( $extension, (array)$this->getExtensions() );
	}

	public function getServerName() {
		return $this->url ?? false;
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
