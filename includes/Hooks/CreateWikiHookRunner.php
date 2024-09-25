<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\HookContainer\HookContainer;

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
	public function onCreateWikiDataFactoryBuilder( $wiki, $dbr, &$data ): void {
		$this->container->run(
			'CreateWikiDataFactoryBuilder',
			[ $wiki, $dbr, &$data ]
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
	public function onCreateWikiGenerateDatabaseLists( &$databaseLists ): void {
		$this->container->run(
			'CreateWikiGenerateDatabaseLists',
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

	// Deprecated aliases
	public function onCreateWikiJsonBuilder( $wiki, $dbr, &$data ): void {
		$this->onCreateWikiDataFactoryBuilder( $wiki, $dbr, $data );
	}

	public function onCreateWikiJsonGenerateDatabaseList( &$databaseLists ): void {
		$this->onCreateWikiGenerateDatabaseLists( $databaseLists );
	}
}
