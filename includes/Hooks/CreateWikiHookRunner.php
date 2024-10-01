<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\HookContainer\HookContainer;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IReadableDatabase;

class CreateWikiHookRunner implements
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
	CreateWikiWritePersistentModelHook
{

	private HookContainer $container;

	/**
	 * @param HookContainer $container
	 */
	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/** @inheritDoc */
	public function onCreateWikiCreation( string $dbname, bool $private ): void {
		$this->container->run(
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
		$this->container->run(
			'CreateWikiDataFactoryBuilder',
			[ $wiki, $dbr, &$cacheArray ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiDeletion(
		DBConnRef $cwdb,
		string $dbname
	): void {
		$this->container->run(
			'CreateWikiDeletion',
			[ $cwdb, $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiGenerateDatabaseLists( array &$databaseLists ): void {
		$this->container->run(
			'CreateWikiGenerateDatabaseLists',
			[ &$databaseLists ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiReadPersistentModel( string &$pipeline ): void {
		$this->container->run(
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
		$this->container->run(
			'CreateWikiRename',
			[ $cwdb, $oldDbName, $newDbName ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateClosed( string $dbname ): void {
		$this->container->run(
			'CreateWikiStateClosed',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateOpen( string $dbname ): void {
		$this->container->run(
			'CreateWikiStateOpen',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePrivate( string $dbname ): void {
		$this->container->run(
			'CreateWikiStatePrivate',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePublic( string $dbname ): void {
		$this->container->run(
			'CreateWikiStatePublic',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiTables( array &$cTables ): void {
		$this->container->run(
			'CreateWikiTables',
			[ &$cTables ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiWritePersistentModel( string $pipeline ): bool {
		return $this->container->run(
			'CreateWikiWritePersistentModel',
			[ $pipeline ]
		);
	}
}
