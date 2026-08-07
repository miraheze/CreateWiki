<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use function date;

/**
 * Maintenance script for removing expired inactive exemptions from wikis.
 *
 * @author Reception123
 */
class RemoveExpiredInactiveExemptions extends Maintenance {

	private CreateWikiDatabaseUtils $databaseUtils;
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
		$this->remoteWikiFactory = $services->get( 'RemoteWikiFactory' );
	}

	public function execute(): void {
		$this->initServices();
		$dbr = $this->databaseUtils->getGlobalReplicaDB();

		$wikis = $dbr->newSelectQueryBuilder()
			->select( 'wiki_dbname' )
			->from( 'cw_wikis' )
			->where( [
				'wiki_inactive_exempt' => 1,
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

				$this->output( "$wiki had its inactive exemption removed (expired: $expiry).\n" );
			} else {
				$this->output( "$wiki has an expired inactive exemption (expired: $expiry).\n" );
			}
		}
	}
}

// @codeCoverageIgnoreStart
return RemoveExpiredInactiveExemptions::class;
// @codeCoverageIgnoreEnd
