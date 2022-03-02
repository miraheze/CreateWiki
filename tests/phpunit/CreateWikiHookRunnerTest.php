<?php

namespace Miraheze\CreateWiki\Tests;

use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;

/**
 * @covers \Miraheze\CreateWiki\Hooks\CreateWikiHookRunner
 */
class CreateWikiHookRunnerTest extends HookRunnerTestBase {

	public function provideHookRunners() {
		yield CreateWikiHookRunner::class => [ CreateWikiHookRunner::class ];
	}
}
