<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\HookContainer\HookContainer;

class CreateWikiHookRunner implements
	CreateWikiCreationHook,
	CreateWikiDeletionHook,
	CreateWikiGetDatabaseTableHook,
	CreateWikiJsonBuilderHook,
	CreateWikiJsonGenerateDatabaseListHook,
	CreateWikiReadPersistentModelHook,
	CreateWikiRenameHook,
	CreateWikiStateClosedHook,
	CreateWikiStateOpenHook,
	CreateWikiStatePrivateHook,
	CreateWikiStatePublicHook,
	CreateWikiTablesHook,
	CreateWikiWritePersistentModelHook
{
	/**
	 * @var HookContainer
	 */
	private $container;

	/**
	 * @param HookContainer $container
	 */
	public function __construct( HookContainer $container ) {
		$this->container = $container;
	}

	/** @inheritDoc */
	public function onCreateWikiCreation( $wiki, $private ): void {
		$this->container->run(
			'CreateWikiCreation',
			[ $wiki, $private ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiDeletion( $cwdb, $wiki ): void {
		$this->container->run(
			'CreateWikiDeletion',
			[ $cwdb, $wiki ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiGetDatabaseTable( &$table ): void {
		$this->container->run(
			'CreateWikiGetDatabaseTable',
			[ &$table ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiJsonBuilder( $wiki, $dbr, &$jsonArray ): void {
		$this->container->run(
			'CreateWikiJsonBuilder',
			[ $wiki, $dbr, &$jsonArray ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiJsonGenerateDatabaseList( &$databaseLists ): void {
		$this->container->run(
			'CreateWikiJsonGenerateDatabaseList',
			[ &$databaseLists ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiReadPersistentModel( &$pipeline ): void {
		$this->container->run(
			'CreateWikiReadPersistentModel',
			[ &$pipeline ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiRename( $cwdb, $old, $new ): void {
		$this->container->run(
			'CreateWikiRename',
			[ $cwdb, $old, $new ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateClosed( $dbname ): void {
		$this->container->run(
			'CreateWikiStateClosed',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStateOpen( $dbname ): void {
		$this->container->run(
			'CreateWikiStateOpen',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePrivate( $dbname ): void {
		$this->container->run(
			'CreateWikiStatePrivate',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiStatePublic( $dbname ): void {
		$this->container->run(
			'CreateWikiStatePublic',
			[ $dbname ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiTables( &$cTables ): void {
		$this->container->run(
			'CreateWikiTables',
			[ &$cTables ]
		);
	}

	/** @inheritDoc */
	public function onCreateWikiWritePersistentModel( $pipeline ): bool {
		return $this->container->run(
			'CreateWikiWritePersistentModel',
			[ $pipeline ]
		);
	}
}
