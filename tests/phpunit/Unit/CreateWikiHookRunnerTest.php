<?php

namespace Miraheze\CreateWiki\Tests\Unit;

use Generator;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

/**
 * @covers \Miraheze\CreateWiki\Hooks\CreateWikiHookRunner
 */
class CreateWikiHookRunnerTest extends HookRunnerTestBase {

	/** @inheritDoc */
	public static function provideHookRunners(): Generator {
		yield CreateWikiHookRunner::class => [ CreateWikiHookRunner::class ];
	}
}
