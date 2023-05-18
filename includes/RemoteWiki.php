<?php

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use User;

class RemoteWiki {
	public $changes = [];
	public $log;
	public $logParams = [];
	public $newRows = [];

	private $hooks = [];
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
	private $inactiveExemptReason;
	private $inactiveExemptGranter;
	private $inactiveExemptTimestamp;
	private $deleted;
	private $locked;
	private $dbcluster;
	private $category;
	private $experimental;
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner ?? MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$this->dbw = $lbFactory->getMainLB( $this->config->get( 'CreateWikiDatabase' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $this->config->get( 'CreateWikiDatabase' ) );

		$wikiRow = $this->dbw->selectRow(
			'cw_wikis',
			'*',
			[
				'wiki_dbname' => $wiki
			]
		);

		if ( !$wikiRow ) {
			return;
		}

		$this->dbname = $wikiRow->wiki_dbname;
		$this->sitename = $wikiRow->wiki_sitename;
		$this->language = $wikiRow->wiki_language;
		$this->creation = $wikiRow->wiki_creation;
		$this->url = $wikiRow->wiki_url;
		$this->deleted = $wikiRow->wiki_deleted_timestamp ?? 0;
		$this->locked = $wikiRow->wiki_locked;
		$this->dbcluster = $wikiRow->wiki_dbcluster;
		$this->category = $wikiRow->wiki_category;

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
			$this->private = $wikiRow->wiki_private;
		}

		if ( $this->config->get( 'CreateWikiUseClosedWikis' ) ) {
			$this->closed = $wikiRow->wiki_closed_timestamp ?? 0;
		}

		if ( $this->config->get( 'CreateWikiUseInactiveWikis' ) ) {
			$this->inactive = $wikiRow->wiki_inactive_timestamp ?? 0;
			$this->inactiveExempt = $wikiRow->wiki_inactive_exempt;
			$this->inactiveExemptReason = $wikiRow->wiki_inactive_exempt_reason ?? null;
			$this->inactiveExemptGranter = $wikiRow->wiki_inactive_exempt_granter;
			$this->inactiveExemptTimestamp = $wikiRow->wiki_inactive_exempt_timestamp ?? null;
		}

		if ( $this->config->get( 'CreateWikiUseExperimental' ) ) {
			$this->experimental = $wikiRow->wiki_experimental;
		}
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
			'wiki_inactive_timestamp' => $this->inactive
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
		$user = new User;

		$this->changes['inactive-exempt'] = [
			'old' => 0,
			'new' => 1
		];
		$this->changes['inactive-exempt-granter'] = [
			'old' => null,
			'new' => $user->getUser()
		];

		$this->inactiveExempt = true;
		$this->inactiveExemptTimestamp = $this->dbw->timestamp();
		$this->newRows += [
			'wiki_inactive_exempt' => 1,
			'wiki_inactive_exempt_granter' => $user->getUser(),
			'wiki_inactive_exempt_timestamp' => $this->inactiveExemptTimestamp
		];
	}

	public function unExempt() {
		$this->changes['inactive-exempt'] = [
			'old' => 1,
			'new' => 0
		];

		$this->inactiveExempt = false;
		$this->newRows += [
			'wiki_inactive_exempt' => 0,
			'wiki_inactive_exempt_granter' => null
		];

		$this->inactiveExemptReason = null;
		$this->inactiveExemptTimestamp = false;

		$this->newRows += [
			'wiki_inactive_exempt_reason' => null,
			'wiki_inactive_exempt_timestamp' => 0
		];
	}

	public function setInactiveExemptReason( string $reason ) {
		$reason = ( $reason == '' ) ? null : $reason;

		$this->changes['inactive-exempt-reason'] = [
			'old' => $this->inactiveExemptReason,
			'new' => $reason
		];

		$this->inactiveExemptReason = $reason;
		$this->newRows['wiki_inactive_exempt_reason'] = $reason;
	}

	public function getInactiveExemptReason() {
		return $this->inactiveExemptReason;
	}

	public function isPrivate() {
		return $this->private;
	}

	public function markPrivate() {
		$this->changes['private'] = [
			'old' => 0,
			'new' => 1
		];

		if ( $this->config->get( 'CreateWikiUseSecureContainers' ) ) {
			$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
			$jobQueueGroupFactory->makeJobQueueGroup( $this->dbname )->push(
				new SetContainersAccessJob( [ 'private' => true ] )
			);
		}

		$this->hooks[] = 'CreateWikiStatePrivate';
		$this->private = true;
		$this->newRows['wiki_private'] = true;
	}

	public function markPublic() {
		$this->changes['public'] = [
			'old' => 0,
			'new' => 1
		];

		if ( $this->config->get( 'CreateWikiUseSecureContainers' ) ) {
			$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
			$jobQueueGroupFactory->makeJobQueueGroup( $this->dbname )->push(
				new SetContainersAccessJob( [ 'private' => false ] )
			);
		}

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
			'wiki_closed_timestamp' => $this->closed,
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null
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

		$this->log = 'delete';
		$this->deleted = $this->dbw->timestamp();
		$this->newRows += [
			'wiki_deleted' => 1,
			'wiki_deleted_timestamp' => $this->deleted,
			'wiki_closed' => 0,
			'wiki_closed_timestamp' => null
		];
	}

	public function undelete() {
		$this->changes['deleted'] = [
			'old' => 1,
			'new' => 0
		];

		$this->log = 'undelete';
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

		$this->log = 'lock';
		$this->locked = true;
		$this->newRows['wiki_locked'] = 1;
	}

	public function unlock() {
		$this->changes['locked'] = [
			'old' => 1,
			'new' => 0
		];

		$this->log = 'unlock';
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
		$server = ( $server == '' ) ? null : $server;

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

	public function isExperimental() {
		return $this->experimental;
	}

	public function markExperimental() {
		$this->changes['experimental'] = [
			'old' => 0,
			'new' => 1
		];

		$this->experimental = true;
		$this->newRows['wiki_experimental'] = true;
	}

	public function unMarkExperimental() {
		$this->changes['experimental'] = [
			'old' => 1,
			'new' => 0
		];

		$this->experimental = false;
		$this->newRows['wiki_experimental'] = false;
	}

	public function commit() {
		if ( !empty( $this->changes ) ) {
			if ( $this->newRows ) {
				$this->dbw->update(
					'cw_wikis',
					$this->newRows,
					[
						'wiki_dbname' => $this->dbname
					]
				);
			}

			foreach ( $this->hooks as $hook ) {
				switch ( $hook ) {
					case 'CreateWikiStateOpen':
						$this->hookRunner->onCreateWikiStateOpen( $this->dbname );
						break;
					case 'CreateWikiStateClosed':
						$this->hookRunner->onCreateWikiStateClosed( $this->dbname );
						break;
					case 'CreateWikiStatePublic':
						$this->hookRunner->onCreateWikiStatePublic( $this->dbname );
						break;
					case 'CreateWikiStatePrivate':
						$this->hookRunner->onCreateWikiStatePrivate( $this->dbname );
						break;
					default:
						// TODO: throw exception
				}
			}

			// @phan-suppress-next-line SecurityCheck-PathTraversal
			$cWJ = new CreateWikiJson( $this->dbname, $this->hookRunner );

			$cWJ->resetDatabaseList();
			$cWJ->resetWiki();

			if ( $this->log === null ) {
				$this->log = 'settings';
				$this->logParams = [
					'5::changes' => implode( ', ', array_keys( $this->changes ) )
				];
			}
		}
	}
}
