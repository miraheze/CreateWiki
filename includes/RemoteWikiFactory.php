<?php

namespace Miraheze\CreateWiki;

use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

class RemoteWikiFactory {

	public const CONSTRUCTOR_OPTIONS = [
		'CreateWikiDatabase',
		'CreateWikiUseClosedWikis',
		'CreateWikiUseExperimental',
		'CreateWikiUseInactiveWikis',
		'CreateWikiUsePrivateWikis',
	];

	private CreateWikiDataFactory $dataFactory;
	private CreateWikiHookRunner $hookRunner;

	private IConnectionProvider $connectionProvider;
	private IReadableDatabase $dbr;

	private ServiceOptions $options;

	private array $changes = [];
	private array $logParams = [];
	private array $newRows = [];
	private array $hooks = [];

	private string $dbname;
	private string $sitename;
	private string $language;
	private string $dbcluster;
	private string $category;
	private ?string $creation;
	private ?string $url;

	private bool $deleted;
	private bool $locked;

	private bool $private = false;
	private bool $closed = false;
	private bool $inactive = false;
	private bool $inactiveExempt = false;
	private bool $experimental = false;
	private ?string $inactiveExemptReason = null;

	private ?string $log = null;

	public function __construct(
		IConnectionProvider $connectionProvider,
		CreateWikiDataFactory $dataFactory,
		CreateWikiHookRunner $hookRunner,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->dataFactory = $dataFactory;
		$this->connectionProvider = $connectionProvider;
		$this->hookRunner = $hookRunner;
		$this->options = $options;
	}

	public function newInstance( string $wiki ): self {
		$this->dbr = $this->connectionProvider->getReplicaDatabase(
			$this->options->get( 'CreateWikiDatabase' )
		);

		$row = $this->dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $wiki ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			throw new InvalidArgumentException( "Wiki '$wiki' cannot be found." );
		}

		$this->dbname = $row->wiki_dbname;
		$this->sitename = $row->wiki_sitename;
		$this->language = $row->wiki_language;
		$this->creation = $row->wiki_creation;
		$this->url = $row->wiki_url;
		$this->dbcluster = $row->wiki_dbcluster ?? 'c1';
		$this->category = $row->wiki_category;

		$this->deleted = (bool)$row->wiki_deleted;
		$this->locked = (bool)$row->wiki_locked;

		if ( $this->options->get( 'CreateWikiUsePrivateWikis' ) ) {
			$this->private = (bool)$row->wiki_private;
		}

		if ( $this->options->get( 'CreateWikiUseClosedWikis' ) ) {
			$this->closed = (bool)$row->wiki_closed;
		}

		if ( $this->options->get( 'CreateWikiUseInactiveWikis' ) ) {
			$this->inactive = (bool)$row->wiki_inactive;
			$this->inactiveExempt = (bool)$row->wiki_inactive_exempt;
			$this->inactiveExemptReason = $row->wiki_inactive_exempt_reason ?? null;
		}

		if ( $this->options->get( 'CreateWikiUseExperimental' ) ) {
			$this->experimental = (bool)$row->wiki_experimental;
		}

		return $this;
	}

	public function getCreationDate(): ?string {
		return $this->creation;
	}

	public function getDBname(): string {
		return $this->dbname;
	}

	public function getSitename(): string {
		return $this->sitename;
	}

	public function setSitename( string $sitename ): void {
		$this->trackChange( 'sitename', $this->sitename, $sitename );
		$this->sitename = $sitename;
		$this->newRows['wiki_sitename'] = $sitename;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	public function setLanguage( string $lang ): void {
		$this->trackChange( 'language', $this->language, $lang );
		$this->language = $lang;
		$this->newRows['wiki_language'] = $lang;
	}

	public function isInactive(): bool {
		return $this->inactive;
	}

	public function markInactive(): void {
		$this->trackChange( 'inactive', 0, 1 );
		$this->inactive = true;
		$this->newRows += [
			'wiki_inactive' => 1,
			'wiki_inactive_timestamp' => $this->dbr->timestamp(),
		];
	}

	public function markActive(): void {
		$this->trackChange( 'active', 0, 1 );
		$this->hooks[] = 'CreateWikiStateOpen';
		$this->inactive = false;
		$this->closed = false;
		$this->newRows += [
			'wiki_closed' => 0,
			'wiki_closed_timestamp' => null,
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null,
		];
	}

	public function isInactiveExempt(): bool {
		return $this->inactiveExempt;
	}

	public function markExempt(): void {
		$this->trackChange( 'inactive-exempt', 0, 1 );
		$this->inactiveExempt = true;
		$this->newRows['wiki_inactive_exempt'] = 1;
	}

	public function unExempt(): void {
		$this->trackChange( 'inactive-exempt', 1, 0 );
		$this->inactiveExempt = false;
		$this->newRows['wiki_inactive_exempt'] = 0;

		$this->inactiveExemptReason = null;
		$this->newRows['wiki_inactive_exempt_reason'] = null;
	}

	public function setInactiveExemptReason( string $reason ): void {
		$reason = ( $reason === '' ) ? null : $reason;

		$this->trackChange( 'inactive-exempt-reason', $this->inactiveExemptReason, $reason );

		$this->inactiveExemptReason = $reason;
		$this->newRows['wiki_inactive_exempt_reason'] = $reason;
	}

	public function getInactiveExemptReason(): ?string {
		return $this->inactiveExemptReason;
	}

	public function isPrivate(): bool {
		return $this->private;
	}

	public function markPrivate(): void {
		$this->trackChange( 'private', 0, 1 );
		$this->private = true;
		$this->newRows['wiki_private'] = 1;
		$this->hooks[] = 'CreateWikiStatePrivate';
	}

	public function markPublic(): void {
		$this->trackChange( 'public', 0, 1 );
		$this->private = false;
		$this->newRows['wiki_private'] = 0;
		$this->hooks[] = 'CreateWikiStatePublic';
	}

	public function isClosed(): bool {
		return $this->closed;
	}

	public function markClosed(): void {
		$this->trackChange( 'closed', 0, 1 );
		$this->closed = true;
		$this->newRows += [
			'wiki_closed' => 1,
			'wiki_closed_timestamp' => $this->dbr->timestamp(),
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null,
		];
		$this->hooks[] = 'CreateWikiStateClosed';
	}

	public function isDeleted(): bool {
		return $this->deleted;
	}

	public function delete(): void {
		$this->trackChange( 'deleted', 0, 1 );
		$this->log = 'delete';
		$this->deleted = true;
		$this->newRows += [
			'wiki_deleted' => 1,
			'wiki_deleted_timestamp' => $this->dbr->timestamp(),
			'wiki_closed' => 0,
			'wiki_closed_timestamp' => null,
		];
	}

	public function undelete(): void {
		$this->trackChange( 'deleted', 1, 0 );
		$this->log = 'undelete';
		$this->deleted = false;
		$this->newRows += [
			'wiki_deleted' => 0,
			'wiki_deleted_timestamp' => null,
		];
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	public function lock(): void {
		$this->trackChange( 'locked', 0, 1 );
		$this->log = 'lock';
		$this->locked = true;
		$this->newRows['wiki_locked'] = 1;
	}

	public function unlock(): void {
		$this->trackChange( 'locked', 1, 0 );
		$this->log = 'unlock';
		$this->locked = false;
		$this->newRows['wiki_locked'] = 0;
	}

	public function getCategory(): string {
		return $this->category;
	}

	public function setCategory( string $category ): void {
		$this->trackChange( 'category', $this->category, $category );
		$this->category = $category;
		$this->newRows['wiki_category'] = $category;
	}

	public function getServerName(): ?string {
		return $this->url;
	}

	public function setServerName( string $server ): void {
		$server = ( $server === '' ) ? null : $server;

		$this->trackChange( 'servername', $this->url, $server );

		$this->url = $server;
		$this->newRows['wiki_url'] = $server;
	}

	public function getDBCluster(): string {
		return $this->dbcluster;
	}

	public function setDBCluster( string $dbcluster ): void {
		$this->trackChange( 'dbcluster', $this->dbcluster, $dbcluster );
		$this->dbcluster = $dbcluster;
		$this->newRows['wiki_dbcluster'] = $dbcluster;
	}

	public function isExperimental(): bool {
		return $this->experimental;
	}

	public function markExperimental(): void {
		$this->trackChange( 'experimental', 0, 1 );
		$this->experimental = true;
		$this->newRows['wiki_experimental'] = 1;
	}

	public function unMarkExperimental(): void {
		$this->trackChange( 'experimental', 1, 0 );
		$this->experimental = false;
		$this->newRows['wiki_experimental'] = 0;
	}

	public function commit(): void {
		if ( !empty( $this->changes ) ) {
			if ( $this->newRows ) {
				$dbw = $this->connectionProvider->getPrimaryDatabase(
					$this->options->get( 'CreateWikiDatabase' )
				);

				$dbw->update(
					'cw_wikis',
					$this->newRows,
					[ 'wiki_dbname' => $this->dbname ]
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
			$data = $this->dataFactory->newInstance( $this->dbname );
			$data->resetDatabaseLists( isNewChanges: true );
			$data->resetWikiData( isNewChanges: true );

			if ( $this->log === null ) {
				$this->log = 'settings';
				$this->logParams = [
					'5::changes' => implode( ', ', array_keys( $this->changes ) )
				];
			}
		}
	}

	public function trackChange( string $field, int|string|null $oldValue, int|string|null $newValue ): void {
		$this->changes[$field] = [
			'old' => $oldValue,
			'new' => $newValue
		];
	}

	public function makeLog( string $log, array $logParams ): void {
		$this->log = $log;
		$this->logParams = $logParams;
	}

	public function addNewRow( string $row, mixed $value ): void {
		$this->newRows[$row] = $value;
	}
}
