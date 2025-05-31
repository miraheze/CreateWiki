<?php

namespace Miraheze\CreateWiki\Helpers;

use JobSpecification;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Jobs\SetContainersAccessJob;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiDataFactory;
use UnexpectedValueException;
use Wikimedia\Rdbms\IReadableDatabase;
use function array_keys;
use function array_merge;
use function implode;
use function json_decode;
use function json_encode;

class RemoteWiki {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::Categories,
		ConfigNames::DatabaseClusters,
		ConfigNames::DatabaseClustersInactive,
		ConfigNames::InactiveExemptReasonOptions,
		ConfigNames::UseClosedWikis,
		ConfigNames::UseExperimental,
		ConfigNames::UseInactiveWikis,
		ConfigNames::UsePrivateWikis,
	];

	private readonly IReadableDatabase $dbr;

	private array $changes = [];
	private array $logParams = [];
	private array $newRows = [];
	private array $hooks = [];
	private array $extra;

	private string $sitename;
	private string $language;
	private string $dbcluster;
	private string $category;
	private string $creation;
	private ?string $url;

	private bool $deleted;
	private bool $locked;

	private bool $private = false;
	private bool $closed = false;
	private bool $inactive = false;
	private bool $inactiveExempt = false;
	private bool $experimental = false;
	private bool $resetDatabaseLists = true;
	private ?string $inactiveExemptReason = null;

	private ?string $deletedTimestamp;
	private ?string $closedTimestamp = null;
	private ?string $inactiveTimestamp = null;

	private ?string $log = null;

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiDataFactory $dataFactory,
		private readonly CreateWikiHookRunner $hookRunner,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		protected readonly ServiceOptions $options,
		protected readonly string $dbname
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->dbr = $this->databaseUtils->getGlobalReplicaDB();
		$row = $this->dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cw_wikis' )
			->where( [ 'wiki_dbname' => $this->dbname ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			throw new MissingWikiError( $this->dbname );
		}

		$this->sitename = $row->wiki_sitename;
		$this->language = $row->wiki_language;
		$this->creation = $row->wiki_creation;
		$this->url = $row->wiki_url;
		$this->dbcluster = $row->wiki_dbcluster ?? 'c1';
		$this->category = $row->wiki_category;

		$this->deleted = (bool)$row->wiki_deleted;
		$this->locked = (bool)$row->wiki_locked;

		$this->deletedTimestamp = $row->wiki_deleted_timestamp;

		$this->extra = (array)json_decode( $row->wiki_extra ?: '[]', true );

		if ( $this->options->get( ConfigNames::UsePrivateWikis ) ) {
			$this->private = (bool)$row->wiki_private;
		}

		if ( $this->options->get( ConfigNames::UseClosedWikis ) ) {
			$this->closed = (bool)$row->wiki_closed;
			$this->closedTimestamp = $row->wiki_closed_timestamp;
		}

		if ( $this->options->get( ConfigNames::UseInactiveWikis ) ) {
			$this->inactive = (bool)$row->wiki_inactive;
			$this->inactiveTimestamp = $row->wiki_inactive_timestamp;
			$this->inactiveExempt = (bool)$row->wiki_inactive_exempt;
			$this->inactiveExemptReason = $row->wiki_inactive_exempt_reason ?? null;
		}

		if ( $this->options->get( ConfigNames::UseExperimental ) ) {
			$this->experimental = (bool)$row->wiki_experimental;
		}
	}

	public function getCreationDate(): string {
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
		$this->inactiveTimestamp = $this->dbr->timestamp();

		$this->newRows['wiki_inactive'] = 1;
		$this->newRows['wiki_inactive_timestamp'] = $this->inactiveTimestamp;
	}

	public function markActive(): void {
		$this->trackChange( 'active', 0, 1 );
		$this->hooks[] = 'CreateWikiStateOpen';
		$this->inactive = false;
		$this->closed = false;
		$this->closedTimestamp = null;
		$this->inactiveTimestamp = null;

		$this->newRows = array_merge( $this->newRows, [
			'wiki_closed' => 0,
			'wiki_closed_timestamp' => null,
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null,
		] );
	}

	public function getInactiveTimestamp(): ?string {
		return $this->inactiveTimestamp;
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
		$reason = $reason === '' ? null : $reason;

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
		$this->hooks[] = 'CreateWikiStatePrivate';
		$this->private = true;
		$this->newRows['wiki_private'] = 1;

		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->dbname )->push(
			new JobSpecification(
				SetContainersAccessJob::JOB_NAME,
				[ 'private' => true ]
			)
		);
	}

	public function markPublic(): void {
		$this->trackChange( 'public', 0, 1 );
		$this->hooks[] = 'CreateWikiStatePublic';
		$this->private = false;
		$this->newRows['wiki_private'] = 0;

		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->dbname )->push(
			new JobSpecification(
				SetContainersAccessJob::JOB_NAME,
				[ 'private' => false ]
			)
		);
	}

	public function isClosed(): bool {
		return $this->closed;
	}

	public function markClosed(): void {
		$this->trackChange( 'closed', 0, 1 );
		$this->hooks[] = 'CreateWikiStateClosed';
		$this->closed = true;
		$this->inactive = false;
		$this->closedTimestamp = $this->dbr->timestamp();
		$this->inactiveTimestamp = null;

		$this->newRows = array_merge( $this->newRows, [
			'wiki_closed' => 1,
			'wiki_closed_timestamp' => $this->closedTimestamp,
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null,
		] );
	}

	public function getClosedTimestamp(): ?string {
		return $this->closedTimestamp;
	}

	public function isDeleted(): bool {
		return $this->deleted;
	}

	public function delete(): void {
		$this->trackChange( 'deleted', 0, 1 );
		$this->log = 'delete';
		$this->closed = false;
		$this->inactive = false;
		$this->deleted = true;
		$this->deletedTimestamp = $this->dbr->timestamp();
		$this->closedTimestamp = null;
		$this->inactiveTimestamp = null;

		$this->newRows = array_merge( $this->newRows, [
			'wiki_deleted' => 1,
			'wiki_deleted_timestamp' => $this->deletedTimestamp,
			'wiki_closed' => 0,
			'wiki_closed_timestamp' => null,
			'wiki_inactive' => 0,
			'wiki_inactive_timestamp' => null,
		] );
	}

	public function undelete(): void {
		$this->trackChange( 'deleted', 1, 0 );
		$this->log = 'undelete';
		$this->deleted = false;
		$this->deletedTimestamp = null;

		$this->newRows['wiki_deleted'] = 0;
		$this->newRows['wiki_deleted_timestamp'] = null;
	}

	public function getDeletedTimestamp(): ?string {
		return $this->deletedTimestamp;
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

	public function getServerName(): string {
		return $this->url ?? '';
	}

	public function setServerName( string $server ): void {
		$server = $server === '' ? null : $server;

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

	public function getExtraFieldData( string $field, mixed $default ): mixed {
		return $this->extra[$field] ?? $default;
	}

	public function setExtraFieldData( string $field, mixed $value, mixed $default ): void {
		if ( $value === $this->getExtraFieldData( $field, $default ) ) {
			return;
		}

		$extra = $this->extra;
		$extra[$field] = $value;

		$newExtra = json_encode( $extra );

		if ( $newExtra === false ) {
			// Can not set invalid JSON data to wiki_extra.
			return;
		}

		$this->extra = $extra;
		$this->trackChange( $field, $this->getExtraFieldData( $field, $default ), $value );
		$this->newRows['wiki_extra'] = $newExtra;
	}

	public function disableResetDatabaseLists(): void {
		$this->resetDatabaseLists = false;
	}

	public function trackChange( string $field, mixed $oldValue, mixed $newValue ): void {
		$this->changes[$field] = [
			'old' => $oldValue,
			'new' => $newValue,
		];
	}

	public function getErrors(): array {
		// This class doesn't produce errors, but the method
		// may be called by consumers, so return an empty array.
		return [];
	}

	public function hasChanges(): bool {
		return (bool)$this->changes;
	}

	public function setLogAction( string $action ): void {
		$this->log = $action;
	}

	public function getLogAction(): string {
		return $this->log ?? 'settings';
	}

	public function addLogParam( string $param, mixed $value ): void {
		$this->logParams[$param] = $value;
	}

	public function getLogParams(): array {
		return $this->logParams;
	}

	public function commit(): void {
		if ( $this->hasChanges() ) {
			if ( $this->newRows ) {
				$dbw = $this->databaseUtils->getGlobalPrimaryDB();
				$dbw->newUpdateQueryBuilder()
					->update( 'cw_wikis' )
					->set( $this->newRows )
					->where( [ 'wiki_dbname' => $this->dbname ] )
					->caller( __METHOD__ )
					->execute();
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
						throw new UnexpectedValueException( "Unsupported hook: $hook" );
				}
			}

			$data = $this->dataFactory->newInstance( $this->dbname );
			if ( $this->resetDatabaseLists ) {
				$data->resetDatabaseLists( isNewChanges: true );
			}

			$data->resetWikiData( isNewChanges: true );

			if ( $this->log === null ) {
				$this->log = 'settings';
				$this->logParams = [
					'5::changes' => implode( ', ', array_keys( $this->changes ) ),
				];
			}
		}
	}
}
