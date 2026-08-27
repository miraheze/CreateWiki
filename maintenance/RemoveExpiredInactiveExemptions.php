<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiNotificationsManager;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use function date;
use function wfMessage;

/**
 * Maintenance script for removing expired inactivity exemptions from wikis.
 *
 * @author Reception123
 */
class RemoveExpiredInactiveExemptions extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
	private CreateWikiNotificationsManager $notificationsManager;
	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct() {
		parent::__construct();

		$this->addOption( 'write',
			'Remove wikis whose inactive exemption has expired.',
			false, false
		);

		$this->addDescription( 'Script to remove expired inactivity exemptions from wikis.' );
		$this->requireExtension( 'CreateWiki' );
	}

	private function initServices(): void {
		$services = $this->getServiceContainer();
		$this->databaseUtils = $services->get( 'CreateWikiDatabaseUtils' );
		$this->notificationsManager = $services->get( 'CreateWikiNotificationsManager' );
		$this->remoteWikiFactory = $services->get( 'RemoteWikiFactory' );
	}

	public function execute(): void {
		$this->initServices();
		$dbr = $this->databaseUtils->getGlobalReplicaDB();

		$wikis = $dbr->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [
				$dbr->expr( 'wiki_inactive_exempt', '=', 1 ),
				$dbr->expr( 'wiki_inactive_exempt_expiry', '!=', 'infinity' ),
				$dbr->expr( 'wiki_inactive_exempt_expiry', '!=', null ),
				$dbr->expr( 'wiki_inactive_exempt_expiry', '<', $dbr->timestamp( date( 'YmdHis' ) ) ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		foreach ( $wikis as $wiki ) {
			$remoteWiki = $this->remoteWikiFactory->newInstance( $wiki );
			$expiry = $remoteWiki->getInactiveExemptExpiry();

			if ( $this->hasOption( 'write' ) ) {
				$remoteWiki->unExempt();
				$remoteWiki->setInactiveExemptReason( '' );
				$remoteWiki->commit();

				$this->notifyBureaucrats( $wiki );
				$this->output( "$wiki had its inactive exemption removed (expired: $expiry).\n" );
			} else {
				$this->output( "$wiki has an expired inactive exemption (expired: $expiry).\n" );
			}
		}
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

// @codeCoverageIgnoreStart
return RemoveExpiredInactiveExemptions::class;
// @codeCoverageIgnoreEnd
