<?php

// phpcs:disable MediaWiki.Commenting.FunctionComment.MissingDocumentationPublic

namespace Miraheze\CreateWiki;

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;

class RemoteWiki {

	private RemoteWikiFactory $factory;

	public array $changes = [];
	public array $logParams = [];
	public array $newRows = [];

	public ?string $log = null;

	public function __construct( string $wiki, CreateWikiHookRunner $hookRunner ) {
		$factory = MediaWikiServices::getInstance()->get( 'RemoteWikiFactory' );
		$this->factory = $factory->newInstance( $wiki );
	}

	public function getCreationDate() {
		return $this->factory->getCreationDate();
	}

	public function getDBname() {
		return $this->factory->getDBname();
	}

	public function getSitename() {
		return $this->factory->getSitename();
	}

	public function setSitename( string $sitename ) {
		$this->factory->setSitename( $sitename );
	}

	public function getLanguage() {
		return $this->factory->getLanguage();
	}

	public function setLanguage( string $lang ) {
		$this->factory->setLanguage( $lang );
	}

	public function isInactive() {
		return $this->factory->getInactiveTimestamp();
	}

	public function markInactive() {
		$this->factory->markInactive();
	}

	public function markActive() {
		$this->factory->markActive();
	}

	public function isInactiveExempt() {
		return $this->factory->isInactiveExempt();
	}

	public function markExempt() {
		$this->factory->markExempt();
	}

	public function unExempt() {
		$this->factory->unExempt();
	}

	public function setInactiveExemptReason( string $reason ) {
		$this->factory->setInactiveExemptReason( $reason );
	}

	public function getInactiveExemptReason() {
		return $this->factory->getInactiveExemptReason();
	}

	public function isPrivate() {
		return $this->factory->isPrivate();
	}

	public function markPrivate() {
		$this->factory->markPrivate();
	}

	public function markPublic() {
		$this->factory->markPublic();
	}

	public function isClosed() {
		return $this->factory->getClosedTimestamp();
	}

	public function markClosed() {
		$this->factory->markClosed();
	}

	public function isDeleted() {
		return $this->factory->isDeleted();
	}

	public function delete() {
		$this->factory->delete();
	}

	public function undelete() {
		$this->factory->undelete();
	}

	public function isLocked() {
		return $this->factory->isLocked();
	}

	public function lock() {
		$this->factory->lock();
	}

	public function unlock() {
		$this->factory->unlock();
	}

	public function getCategory() {
		return $this->factory->getCategory();
	}

	public function setCategory( string $category ) {
		$this->factory->setCategory( $category );
	}

	public function getServerName() {
		return $this->factory->getServerName();
	}

	public function setServerName( string $server ) {
		$this->factory->setServerName( $server );
	}

	public function getDBCluster() {
		return $this->factory->getDBCluster();
	}

	public function setDBCluster( string $dbcluster ) {
		$this->factory->setDBCluster( $dbcluster );
	}

	public function isExperimental() {
		return $this->factory->isExperimental();
	}

	public function markExperimental() {
		$this->factory->markExperimental();
	}

	public function unMarkExperimental() {
		$this->factory->unMarkExperimental();
	}

	public function commit() {
		if ( $this->changes ) {
			foreach ( $this->changes as $field => $value ) {
				$this->factory->trackChange( $field, $value['old'], $value['new'] );
			}

			if ( $this->newRows ) {
				foreach ( $this->newRows as $row => $value ) {
					$this->factory->addNewRow( $row, $value );
				}
			}

			if ( $this->log || $this->logParams ) {
				$this->factory->makeLog( $this->log, $this->logParams );
			}
		}

		$this->factory->commit();
		$this->log = $this->factory->getLogAction();
		$this->logParams = $this->factory->getLogParams();
	}
}
