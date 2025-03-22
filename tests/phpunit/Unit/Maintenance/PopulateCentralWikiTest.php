<?php

namespace Miraheze\CreateWiki\Tests\Unit\Maintenance;

use MediaWikiUnitTestCase;
use Miraheze\CreateWiki\Maintenance\PopulateCentralWiki;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \Miraheze\CreateWiki\Maintenance\PopulateCentralWiki
 * @group CreateWiki
 */
class PopulateCentralWikiTest extends MediaWikiUnitTestCase {

	public function testGetUpdateKey() {
		// Verifies that the update key does not change without deliberate meaning, as it could
		// cause the script to be unnecessarily re-run on a new call to update.php.
		$objectUnderTest = new PopulateCentralWiki();
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			PopulateCentralWiki::class,
			$objectUnderTest->getUpdateKey(),
			'::getUpdateKey did not return the expected key.'
		);
	}
}
