<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use stdClass;
use Wikimedia\Rdbms\SelectQueryBuilder;
use function strtotime;
use function wfMessage;

class DeclineStaleWikiRequests extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
	private WikiRequestManager $wikiRequestManager;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Marks stale wiki requests as abandoned'. );
		$this->addOption( 'days', 'Number of days without a response before a request is marked as abandoned.', true, true );
		$this->addOption( 'dry-run', 'Show which requests would be marked as abandoned without making changes.', false, false );
		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
		$this->wikiRequestManager = $services->get( 'WikiRequestManager' );
	}

	public function execute(): void {
		$this->initServices();
		$days = (int)$this->getOption( 'days' );
		if ( $days <= 0 ) {
			$this->fatalError( '--days must be a positive integer.' );
		}

		$dbr = $this->databaseUtils->getCentralWikiReplicaDB();
		$cutoff = $dbr->timestamp( strtotime( "-$days days" ) );

		$requestIds = $dbr->newSelectQueryBuilder()
			->select( 'cw_id' )
			->from( 'cw_requests' )
			->where( [ 'cw_status' => 'moredetails' ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$declined = 0;
		foreach ( $requestIds as $id ) {
			$id = (int)$id;
			$this->wikiRequestManager->loadFromId( $id );
			$requesterId = $this->wikiRequestManager->getRequester()->getId();

			$comments = $dbr->newSelectQueryBuilder()
				->select( [ 'cw_comment_user', 'cw_comment_timestamp' ] )
				->from( 'cw_comments' )
				->where( [ 'cw_id' => $id ] )
				->orderBy( 'cw_comment_timestamp', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ )
				->fetchResultSet();

			$lastReviewerTs = null;
			$requesterReplied = false;

			foreach ( $comments as $row ) {
				if ( !$row instanceof stdClass ) {
					continue;
				}

				if ( (int)$row->cw_comment_user === $requesterId ) {
					if ( $lastReviewerTs === null ) {
						$requesterReplied = true;
					}
					break;
				}

				if ( $lastReviewerTs === null ) {
					$lastReviewerTs = $row->cw_comment_timestamp;
				}
			}

			if ( $requesterReplied || $lastReviewerTs === null || $lastReviewerTs >= $cutoff ) {
				continue;
			}

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Would mark request #$id as abandoned (last reviewer comment: $lastReviewerTs)\n" );
				continue;
			}

			$systemUser = User::newSystemUser( 'CreateWiki Extension', [ 'steal' => true ] );
			$message = wfMessage( 'createwiki-decline-stale-reason' )->inContentLanguage()->text();

			$this->wikiRequestManager->startQueryBuilder();
			$this->wikiRequestManager->abandon( $message, $systemUser );
			$this->wikiRequestManager->tryExecuteQueryBuilder();

			$this->output( "Marked request #$id\n as abandoned" );
			$declined++;
		}

		$this->output( "Done. Marked $declined request(s) as abandoned.\n" );
	}
}

// @codeCoverageIgnoreStart
return DeclineStaleWikiRequests::class;
// @codeCoverageIgnoreEnd
