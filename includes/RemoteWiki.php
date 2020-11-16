<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

class RemoteWiki {
	public $changes = [];
	public $specialLog;

	private $hooks = [];
	private $newRows = [];
	private $config;
	private $dbw;
	private $dbname;
	private $sitename;
	private $language;
	private $private;
	private $creation;
	private $url;
	private $closed;
	private $inactive;
	private $inactiveExempt;
	private $deleted;
	private $locked;
	private $dbcluster;
	private $category;

	public function __construct( string $wiki, IDatabase $dbw = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$this->dbw = $dbw ?? wfGetDB( DB_MASTER, [], $this->config->get( 'CreateWikiDatabase' ) );
		$wikiRow = $this->dbw->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $wiki
			]
		);

		if ( !$wikiRow ) {
			return null;
		}

		$this->dbname = $wikiRow->wiki_dbname;
		$this->sitename = $wikiRow->wiki_sitename;
		$this->language = $wikiRow->wiki_language;
		$this->private = $wikiRow->wiki_private;
		$this->creation = $wikiRow->wiki_creation;
		$this->url = $wikiRow->wiki_url;
		$this->closed = $wikiRow->wiki_closed_timestamp ?? false;
		$this->inactive = $wikiRow->wiki_inactive_timestamp ?? false;
		$this->inactiveExempt = $wikiRow->wiki_inactive_exempt;
		$this->deleted = $wikiRow->wiki_deleted_timestamp ?? false;
		$this->locked = $wikiRow->wiki_locked;
		$this->dbcluster = $wikiRow->wiki_dbcluster;
		$this->category = $wikiRow->wiki_category;
	}

	public function getCreationDate() {
		return $this->creation;
	}

	public function getDBname() {
		return $this->dbname;
	}

	public function getSitename() {
		return $this->sitename;
	}

	public function setSitename( string $sitename ) {
		$this->changes['sitename'] = [
			'old' => $this->sitename,
			'new' => $sitename
		];

		$this->sitename = $sitename;
		$this->newRows['wiki_sitename'] = $sitename;
	}

	public function getLanguage() {
		return $this->language;
	}

	public function setLanguage( string $lang ) {
		$this->changes['language'] = [
			'old' => $this->language,
			'new' => $lang
		];

		$this->language = $lang;
		$this->newRows['wiki_language'] = $lang;
	}

	public function isInactive() {
		return $this->inactive;
	}

	public function markInactive() {
		$this->changes['inactive'] = [
			'old' => 0,
			'new' => 1
		];

		$this->inactive = $this->dbw->timestamp();
		$this->newRows += [
			'wiki_inactive' => 1,
			'wiki_inactive_timestamp' =>$this->inactive
		];
	}

	public function markActive() {
		$this->changes['active'] = [
			'old' => 0,
			'new' => 1
		];

		$this->hooks[] = 'CreateWikiStateOpen';
		$this->inactive = false;
		$this->closed = false;
		$this->newRows += [
			'wiki_closed' => 0,
			'wiki_closed_timestamp' => null,
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null
		];
	}

	public function isInactiveExempt() {
		return $this->inactiveExempt;
	}

	public function markExempt() {
		$this->changes['inactive-exempt'] = [
			'old' => 0,
			'new' => 1
		];

		$this->inactiveExempt = true;
		$this->newRows['wiki_inactive_exempt'] = true;
	}

	public function unExempt() {
		$this->changes['inactive-exempt'] = [
			'old' => 1,
			'new' => 0
		];

		$this->inactiveExempt = false;
		$this->newRows['wiki_inactive_exempt'] = false;
	}

	public function isPrivate() {
		return $this->private;
	}

	public function markPrivate() {
		$this->changes['private'] = [
			'old' => 0,
			'new' => 1
		];

		$this->hooks[] = 'CreateWikiStatePrivate';
		$this->private = true;
		$this->newRows['wiki_private'] = true;
	}

	public function markPublic() {
		$this->changes['public'] = [
			'old' => 0,
			'new' => 1
		];

		$this->hooks[] = 'CreateWikiStatePublic';
		$this->private = false;
		$this->newRows['wiki_private'] = false;
	}

	public function isClosed() {
		return $this->closed;
	}

	public function markClosed() {
		$this->changes['closed'] = [
			'old' => 0,
			'new' => 1
		];

		$this->hooks[] = 'CreateWikiStateClosed';
		$this->closed = $this->dbw->timestamp();
		$this->newRows += [
			'wiki_closed' => 1,
			'wiki_closed_timestamp' => $this->closed
		];
	}

	public function isDeleted() {
		return $this->deleted;
	}

	public function delete() {
		$this->changes['deleted'] = [
			'old' => 0,
			'new' => 1
		];

		$this->specialLog = 'delete';
		$this->deleted = $this->dbw->timestamp();
		$this->newRows += [
			'wiki_deleted' => 1,
			'wiki_deleted_timestamp' => $this->deleted
		];
	}

	public function undelete() {
		$this->changes['deleted'] = [
			'old' => 1,
			'new' => 0
		];

		$this->specialLog = 'undelete';
		$this->deleted = false;
		$this->newRows += [
			'wiki_deleted' => 0,
			'wiki_deleted_timestamp' => null
		];
	}

	public function isLocked() {
		return $this->locked;
	}

	public function lock() {
		$this->changes['locked'] = [
			'old' => 0,
			'new' => 1
		];

		$this->specialLog = 'lock';
		$this->locked = true;
		$this->newRows['wiki_locked'] = 1;
	}

	public function unlock() {
		$this->changes['locked'] = [
			'old' => 1,
			'new' => 0
		];

		$this->specialLog = 'unlock';
		$this->locked = false;
		$this->newRows['wiki_locked'] = 0;
	}

	public function getCategory() {
		return $this->category;
	}

	public function setCategory( string $category ) {
		$this->changes['category'] = [
			'old' => $this->category,
			'new' => $category
		];

		$this->category = $category;
		$this->newRows['wiki_category'] = $category;
	}

	public function getServerName() {
		return $this->url;
	}

	public function setServerName( string $server ) {
		$server = ( $server == '' ) ? NULL : $server;

		$this->changes['servername'] = [
			'old' => $this->url,
			'new' => $server
		];

		$this->url = $server;
		$this->newRows['wiki_url'] = $server;
	}

	public function getDBCluster() {
		return $this->dbcluster;
	}

	public function setDBCluster( string $dbcluster ) {
		$this->changes['dbcluster'] = [
			'old' => $this->dbcluster,
			'new' => $dbcluster
		];

		$this->dbcluster = $dbcluster;
		$this->newRows['wiki_dbcluster'] = $dbcluster;
	}

	public function commit() {
		if ( !empty( $this->changes ) ) {
			$this->dbw->update(
				'cw_wikis',
				$this->newRows,
				[
					'wiki_dbname' => $this->dbname
				]
			);

			$cWJ = new CreateWikiJson( $this->dbname );
			$cWJ->resetDatabaseList();
			$cWJ->resetWiki();
		}
	}
}
