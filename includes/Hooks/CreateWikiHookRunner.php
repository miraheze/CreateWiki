<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\HookContainer\HookContainer;
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
	public function onCreateWikiDeletion( $cwdb, $dbname ): void {
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
