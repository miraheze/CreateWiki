<?php

namespace Miraheze\CreateWiki\Hooks;

use MediaWiki\HookContainer\HookContainer;

class CreateWikiHookRunner implements
	CreateWikiCreationHook,
	CreateWikiDeletionHook,
	CreateWikiJsonBuilderHook,
	CreateWikiRenameHook,
	CreateWikiStateClosedHook,
	CreateWikiStateOpenHook,
	CreateWikiStatePrivateHook,
	CreateWikiStatePublicHook,
	CreateWikiTablesHook
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
	public function onCreateWikiJsonBuilder( $wiki, $dbr, &$jsonArray ): void {
		$this->container->run(
			'CreateWikiJsonBuilder',
			[ $wiki, $dbr, &$jsonArray ]
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
	public function onCreateWikiTables( $cTables ): void {
		$this->container->run(
			'CreateWikiTables',
			[ $cTables ]
		);
	}
}
