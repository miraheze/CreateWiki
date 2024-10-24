<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IReadableDatabase;

class CreateWikiHookRunner implements
	CreateWikiAfterCreationWithExtraDataHook,
	CreateWikiCreationExtraFieldsHook,
	CreateWikiCreationHook,
	CreateWikiDataFactoryBuilderHook,
	CreateWikiDeletionHook,
	CreateWikiGenerateDatabaseListsHook,
	CreateWikiReadPersistentModelHook,
	CreateWikiRenameHook,
	CreateWikiStateClosedHook,
	CreateWikiStateOpenHook,
	CreateWikiStatePrivateHook,
	CreateWikiStatePublicHook,
	CreateWikiTablesHook,
	CreateWikiWritePersistentModelHook,
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook
{

	private HookContainer $hookContainer;

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/** @inheritDoc */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiAfterCreationWithExtraData',
			[ $extraData, $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void {
		$this->hookContainer->run(
			'CreateWikiCreationExtraFields',
			[ &$extraFields ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiCreation( string $dbname, bool $private ): void {
		$this->hookContainer->run(
			'CreateWikiCreation',
			[ $dbname, $private ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiDataFactoryBuilder(
		string $wiki,
		IReadableDatabase $dbr,
		array &$cacheArray
	): void {
		$this->hookContainer->run(
			'CreateWikiDataFactoryBuilder',
			[ $wiki, $dbr, &$cacheArray ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiDeletion(
		DBConnRef $cwdb,
		string $dbname
	): void {
		$this->hookContainer->run(
			'CreateWikiDeletion',
			[ $cwdb, $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void {
		$this->hookContainer->run(
			'CreateWikiGenerateDatabaseLists',
			[ &$databaseLists ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void {
		$this->hookContainer->run(
			'CreateWikiReadPersistentModel',
			[ &$pipeline ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiRename(
		DBConnRef $cwdb,
		string $oldDbName,
		string $newDbName
	): void {
		$this->hookContainer->run(
			'CreateWikiRename',
			[ $cwdb, $oldDbName, $newDbName ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateClosed( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStateClosed',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateOpen( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStateOpen',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePrivate( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStatePrivate',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePublic( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStatePublic',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiTables( array &$cTables ): void {
		$this->hookContainer->run(
			'CreateWikiTables',
			[ &$cTables ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiWritePersistentModel( string $pipeline ): bool {
		return $this->hookContainer->run(
			'CreateWikiWritePersistentModel',
			[ $pipeline ]
		);
	}

	/** @inheritDoc */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		$this->hookContainer->run(
			'RequestWikiFormDescriptorModify',
			[ &$formDescriptor ]
		);
	}

	/** @inheritDoc */
	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void {
		$this->hookContainer->run(
			'RequestWikiQueueFormDescriptorModify',
			[ &$formDescriptor, $user, $wikiRequestManager ]
		);
	}
}
