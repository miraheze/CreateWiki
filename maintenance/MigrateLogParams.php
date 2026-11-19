<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Logging\LogEntryBase;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\WikiMap\WikiMap;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use function array_keys;
use function is_array;
use function is_object;
use function max;
use function preg_match;
use function str_ends_with;

class MigrateLogParams extends LoggedUpdateMaintenance {

	private const array LOG_TYPES = [ 'farmer', 'farmersuppression' ];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Rewrites old wiki request log entries so they store the request ID ' .
			'instead of rendered HTML or a formatted number.'
		);

		$this->setBatchSize( 200 );
		$this->requireExtension( 'CreateWiki' );
	}

	/** @inheritDoc */
	protected function getUpdateKey(): string {
		return 'CreateWiki-MigrateLogParams';
	}

	/** @inheritDoc */
	protected function doDBUpdates(): bool {
		$dbw = $this->getPrimaryDB( 'virtual-createwiki-central' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbw->getDomainID() ) ) {
			$this->output( "Wiki request logs are not stored on this wiki. Skipping.\n" );
			return true;
		}

		$batchSize = max( 1, $this->getBatchSize() );

		$lastId = 0;
		$migrated = 0;
		$skipped = 0;

		do {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'log_id', 'log_title', 'log_params' ] )
				->from( 'logging' )
				->where( [ 'log_type' => self::LOG_TYPES ] )
				->andWhere( $dbw->expr( 'log_id', '>', $lastId ) )
				->orderBy( 'log_id' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();

			$count = 0;
			$this->beginTransactionRound( __METHOD__ );

			foreach ( $res as $row ) {
				'@phan-var stdClass $row';
				$count++;
				$lastId = (int)$row->log_id;

				// @phan-suppress-next-line SecurityCheck-PHPSerializeInjection Classes are not allowed
				$params = LogEntryBase::extractParams( (string)$row->log_params );
				if ( !is_array( $params ) || !$this->needsMigration( $params ) ) {
					continue;
				}

				$converted = $this->convert( $params, (string)$row->log_title );
				if ( $converted === null ) {
					$this->output( "Could not find the request ID for log entry {$row->log_id}. Skipping.\n" );
					$skipped++;
					continue;
				}

				$this->store( $converted, (int)$row->log_id, $dbw );
				$migrated++;
			}

			$this->commitTransactionRound( __METHOD__ );
		} while ( $count >= $batchSize );

		$this->output( "Migrated $migrated log entries. Skipped $skipped.\n" );

		return true;
	}

	/** @param array<string|int, mixed> $params */
	private function needsMigration( array $params ): bool {
		foreach ( array_keys( $params ) as $key ) {
			if ( str_ends_with( (string)$key, '::id' ) ) {
				return true;
			}
		}

		return false;
	}

	private function convert( array $params, string $title ): ?array {
		$converted = [];

		foreach ( $params as $key => $value ) {
			$key = (string)$key;
			if ( !str_ends_with( $key, '::id' ) ) {
				$converted[$key] = $value;
				continue;
			}

			$requestId = $this->getRequestId( $value, $title );
			if ( $requestId === null ) {
				return null;
			}

			$index = (int)$key;
			$converted["$index:request-link:id"] = $requestId;
		}

		return $converted;
	}

	private function store( array $params, int $logId, IDatabase $dbw ): void {
		$blob = LogEntryBase::makeParamBlob( $params );

		$dbw->newUpdateQueryBuilder()
			->update( 'logging' )
			->set( [ 'log_params' => $blob ] )
			->where( [ 'log_id' => $logId ] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newUpdateQueryBuilder()
			->update( 'recentchanges' )
			->set( [ 'rc_params' => $blob ] )
			->where( [
				'rc_source' => RecentChange::SRC_LOG,
				'rc_logid' => $logId,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Requests are stored either as '#123' or as a rendered link to the request.
	 */
	private function getRequestId( mixed $value, string $title ): ?int {
		// Old entries hold a ScalarParam, which unserializes to an incomplete class.
		$text = match ( true ) {
			is_array( $value ) => (string)( $value['raw'] ?? '' ),
			is_object( $value ) => (string)( ( (array)$value )['value'] ?? '' ),
			default => (string)$value,
		};

		if ( preg_match( '/^#?(\d+)$/', $text, $matches ) ) {
			return (int)$matches[1];
		}

		if ( preg_match( '!/(\d+)$!', $title, $matches ) ) {
			return (int)$matches[1];
		}

		if ( preg_match( '/#(\d+)\s*<\/a>/', $text, $matches ) ) {
			return (int)$matches[1];
		}

		return null;
	}
}

// @codeCoverageIgnoreStart
return MigrateLogParams::class;
// @codeCoverageIgnoreEnd
