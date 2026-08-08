<?php

namespace Miraheze\CreateWiki\Jobs;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use function wfMessage;

class InactiveExemptExpiryJob extends Job implements GenericParameterJob {

	public const JOB_NAME = 'InactiveExemptExpiryJob';

	public function __construct(
		array $params,
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiNotificationsManager $notificationsManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->removeDuplicates = true;
	}

	/** @inheritDoc */
	public function run(): bool {
		$dbw = $this->databaseUtils->getGlobalPrimaryDB();

		$expiredWikis = $dbw->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [
				$dbw->expr( 'wiki_inactive_exempt', '=', 1 ),
				$dbw->expr( 'wiki_inactive_exempt_expiry', '!=', 'infinity' ),
				$dbw->expr( 'wiki_inactive_exempt_expiry', '!=', null ),
				$dbw->expr( 'wiki_inactive_exempt_expiry', '<', $dbw->timestamp() ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		if ( !$expiredWikis ) {
			return true;
		}

		$dbw->newUpdateQueryBuilder()
			->update( 'cw_wikis' )
			->set( [
				'wiki_inactive_exempt' => 0,
				'wiki_inactive_exempt_reason' => null,
				'wiki_inactive_exempt_expiry' => null,
			] )
			->where( [ 'wiki_dbname' => $expiredWikis ] )
			->caller( __METHOD__ )
			->execute();

		foreach ( $expiredWikis as $dbname ) {
			$this->notifyBureaucrats( $dbname );
		}

		return true;
	}

	private function notifyBureaucrats( string $dbname ): void {
		$notificationData = [
			'type' => 'inactive-exempt-expiry',
			'subject' => wfMessage( 'createwiki-inactive-exempt-expiry-email-subject', $dbname )
				->inContentLanguage()->text(),
			'body' => [
				'html' => wfMessage( 'createwiki-inactive-exempt-expiry-email-body' )
					->inContentLanguage()->parse(),
				'text' => wfMessage( 'createwiki-inactive-exempt-expiry-email-body' )
					->inContentLanguage()->text(),
			],
		];

		$this->notificationsManager->notifyBureaucrats( $notificationData, $dbname );
	}
}
