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

	public function __construct(
		private readonly HookContainer $hookContainer
	) {
	}

	/** @inheritDoc */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiAfterCreationWithExtraData',
			[ $extraData, $dbname ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void {
		$this->hookContainer->run(
			'CreateWikiCreationExtraFields',
			[ &$extraFields ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiCreation( string $dbname, bool $private ): void {
		$this->hookContainer->run(
			'CreateWikiCreation',
			[ $dbname, $private ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiDataFactoryBuilder(
		string $dbname,
		IReadableDatabase $dbr,
		array &$cacheArray
	): void {
		$this->hookContainer->run(
			'CreateWikiDataFactoryBuilder',
			[ $dbname, $dbr, &$cacheArray ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiDeletion(
		DBConnRef $cwdb,
		string $dbname
	): void {
		$this->hookContainer->run(
			'CreateWikiDeletion',
			[ $cwdb, $dbname ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void {
		$this->hookContainer->run(
			'CreateWikiGenerateDatabaseLists',
			[ &$databaseLists ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void {
		$this->hookContainer->run(
			'CreateWikiReadPersistentModel',
			[ &$pipeline ],
			[ 'abortable' => false ]
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
			[ $cwdb, $oldDbName, $newDbName ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateClosed( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStateClosed',
			[ $dbname ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateOpen( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStateOpen',
			[ $dbname ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePrivate( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStatePrivate',
			[ $dbname ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePublic( string $dbname ): void {
		$this->hookContainer->run(
			'CreateWikiStatePublic',
			[ $dbname ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiTables( array &$tables ): void {
		$this->hookContainer->run(
			'CreateWikiTables',
			[ &$tables ],
			[ 'abortable' => false ]
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
			[ &$formDescriptor ],
			[ 'abortable' => false ]
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
			[ &$formDescriptor, $user, $wikiRequestManager ],
			[ 'abortable' => false ]
		);
	}
}
