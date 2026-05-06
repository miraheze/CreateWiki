<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Rdbms\SelectQueryBuilder;
use stdClass;
use function strtotime;

class DeclineStaleWikiRequests extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
	private WikiRequestManager $wikiRequestManager;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Declines wiki requests stuck in "needs more details" with no response past the given number of days.' );
		$this->addOption( 'days', 'Number of days without a response before a request is declined.', true, true );
		$this->addOption( 'dry-run', 'Show which requests would be declined without making changes.', false, false );
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
				$this->output( "Would decline request #$id (last reviewer comment: $lastReviewerTs)\n" );
				continue;
			}

			$systemUser = User::newSystemUser( 'CreateWiki Extension', [ 'steal' => true ] );
			$this->wikiRequestManager->startQueryBuilder();
			$this->wikiRequestManager->decline( 'We haven\'t heard back from you so this request will be closed. If you\'re still interested in this wiki, please respond to the questions in comments above. You can reopen the request on the "Edit request" tab to put your request back in the review queue. Thank you.', $systemUser );
			$this->wikiRequestManager->tryExecuteQueryBuilder();

			$this->output( "Declined request #$id\n" );
			$declined++;
		}

		$this->output( "Done. Declined $declined request(s).\n" );
	}
}

// @codeCoverageIgnoreStart
return DeclineStaleRequests::class;
// @codeCoverageIgnoreEnd
