<?php

namespace Miraheze\CreateWiki\Tests\RequestWiki;

use Generator;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;

/**
 * @coversDefaultClass \Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils
 */
class RequestWikiFormUtilsTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers ::fieldExists
	 * @dataProvider provideFieldExists
	 */
	public function testFieldExists(
		array $formDescriptor,
		string $fieldKey,
		bool $expected
	): void {
		$this->assertSame(
			$expected,
			RequestWikiFormUtils::fieldExists( $formDescriptor, $fieldKey )
		);
	}

	public function provideFieldExists(): Generator {
		yield 'field exists' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'field1',
			true
		];

		yield 'field does not exist' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'nonexistent',
			false
		];
	}

	/**
	 * @covers ::addFieldToBeginning
	 * @dataProvider provideAddFieldToBeginning
	 */
	public function testAddFieldToBeginning(
		array $formDescriptor,
		string $newKey,
		array $newField,
		array $expected
	): void {
		RequestWikiFormUtils::addFieldToBeginning( $formDescriptor, $newKey, $newField );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideAddFieldToBeginning(): Generator {
		yield 'add to empty form' => [
			[],
			'field1',
			[ 'type' => 'text' ],
			[ 'field1' => [ 'type' => 'text' ] ]
		];

		yield 'add to existing form' => [
			[ 'field2' => [ 'type' => 'checkbox' ] ],
			'field1',
			[ 'type' => 'text' ],
			[
				'field1' => [ 'type' => 'text' ],
				'field2' => [ 'type' => 'checkbox' ]
			]
		];
	}

	/**
	 * @covers ::removeFieldByKey
	 * @dataProvider provideRemoveFieldByKey
	 */
	public function testRemoveFieldByKey(
		array $formDescriptor,
		string $keyToRemove,
		array $expected
	): void {
		RequestWikiFormUtils::removeFieldByKey( $formDescriptor, $keyToRemove );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideRemoveFieldByKey(): Generator {
		yield 'remove existing field' => [
			[ 'field1' => [ 'type' => 'text' ], 'field2' => [ 'type' => 'checkbox' ] ],
			'field1',
			[ 'field2' => [ 'type' => 'checkbox' ] ]
		];

		yield 'remove non-existing field' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'field3',
			[ 'field1' => [ 'type' => 'text' ] ]
		];
	}

	/**
	 * @covers ::moveFieldToSection
	 * @dataProvider provideMoveFieldToSection
	 */
	public function testMoveFieldToSection(
		array $formDescriptor,
		string $fieldKey,
		string $newSection,
		array $expected
	): void {
		RequestWikiFormUtils::moveFieldToSection( $formDescriptor, $fieldKey, $newSection );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideMoveFieldToSection(): Generator {
		yield 'move existing field' => [
			[ 'field1' => [ 'type' => 'text', 'section' => 'oldSection' ] ],
			'field1',
			'newSection',
			[ 'field1' => [ 'type' => 'text', 'section' => 'newSection' ] ]
		];

		yield 'field does not exist' => [
			[ 'field1' => [ 'type' => 'text', 'section' => 'oldSection' ] ],
			'field2',
			'newSection',
			[ 'field1' => [ 'type' => 'text', 'section' => 'oldSection' ] ]
		];
	}

	/**
	 * @covers ::insertFieldAfter
	 * @dataProvider provideInsertFieldAfter
	 */
	public function testInsertFieldAfter(
		array $formDescriptor,
		string $afterKey,
		string $newKey,
		array $newField,
		array $expected
	): void {
		RequestWikiFormUtils::insertFieldAfter( $formDescriptor, $afterKey, $newKey, $newField );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideInsertFieldAfter(): Generator {
		yield 'insert after existing key' => [
			[ 'field1' => [ 'type' => 'text' ], 'field3' => [ 'type' => 'checkbox' ] ],
			'field1',
			'field2',
			[ 'type' => 'email' ],
			[
				'field1' => [ 'type' => 'text' ],
				'field2' => [ 'type' => 'email' ],
				'field3' => [ 'type' => 'checkbox' ]
			]
		];

		yield 'afterKey not found' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'fieldX',
			'field2',
			[ 'type' => 'email' ],
			[
				'field1' => [ 'type' => 'text' ],
				'field2' => [ 'type' => 'email' ]
			]
		];
	}

	/**
	 * @covers ::insertFieldAtBeginningOfSection
	 * @dataProvider provideInsertFieldAtBeginningOfSection
	 */
	public function testInsertFieldAtBeginningOfSection(
		array $formDescriptor,
		string $section,
		string $newKey,
		array $newField,
		array $expected
	): void {
		RequestWikiFormUtils::insertFieldAtBeginningOfSection( $formDescriptor, $section, $newKey, $newField );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideInsertFieldAtBeginningOfSection(): Generator {
		yield 'section exists' => [
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section2' ]
			],
			'section1',
			'field0',
			[ 'type' => 'password', 'section' => 'section1' ],
			[
				'field0' => [ 'type' => 'password', 'section' => 'section1' ],
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section2' ]
			]
		];

		yield 'section does not exist' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'sectionX',
			'field0',
			[ 'type' => 'password', 'section' => 'sectionX' ],
			[
				'field1' => [ 'type' => 'text' ],
				'field0' => [ 'type' => 'password', 'section' => 'sectionX' ]
			]
		];
	}

	/**
	 * @covers ::insertFieldAtEndOfSection
	 * @dataProvider provideInsertFieldAtEndOfSection
	 */
	public function testInsertFieldAtEndOfSection(
		array $formDescriptor,
		string $section,
		string $newKey,
		array $newField,
		array $expected
	): void {
		RequestWikiFormUtils::insertFieldAtEndOfSection( $formDescriptor, $section, $newKey, $newField );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideInsertFieldAtEndOfSection(): Generator {
		yield 'section exists' => [
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section2' ]
			],
			'section1',
			'field4',
			[ 'type' => 'number', 'section' => 'section1' ],
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field4' => [ 'type' => 'number', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section2' ]
			]
		];

		yield 'section does not exist' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'sectionX',
			'field4',
			[ 'type' => 'number', 'section' => 'sectionX' ],
			[
				'field1' => [ 'type' => 'text' ],
				'field4' => [ 'type' => 'number', 'section' => 'sectionX' ]
			]
		];
	}

	/**
	 * @covers ::cloneFieldToSection
	 * @dataProvider provideCloneFieldToSection
	 */
	public function testCloneFieldToSection(
		array $formDescriptor,
		string $fieldKey,
		string $newKey,
		string $newSection,
		array $expected
	): void {
		RequestWikiFormUtils::cloneFieldToSection( $formDescriptor, $fieldKey, $newKey, $newSection );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideCloneFieldToSection(): Generator {
		yield 'clone existing field' => [
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section2' ]
			],
			'field1',
			'field1_clone',
			'section2',
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section2' ],
				'field1_clone' => [ 'type' => 'text', 'section' => 'section2' ]
			]
		];

		yield 'field to clone does not exist' => [
			[ 'field2' => [ 'type' => 'checkbox', 'section' => 'section2' ] ],
			'fieldX',
			'fieldX_clone',
			'section2',
			[ 'field2' => [ 'type' => 'checkbox', 'section' => 'section2' ] ]
		];
	}

	/**
	 * @covers ::reorderFieldsInSection
	 * @dataProvider provideReorderFieldsInSection
	 */
	public function testReorderFieldsInSection(
		array $formDescriptor,
		string $section,
		array $newOrder,
		array $expected
	): void {
		RequestWikiFormUtils::reorderFieldsInSection( $formDescriptor, $section, $newOrder );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideReorderFieldsInSection(): Generator {
		yield 'reorder fields' => [
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section1' ],
				'field4' => [ 'type' => 'number', 'section' => 'section2' ]
			],
			'section1',
			[ 'field3', 'field1', 'field2', 'field4' ],
			[
				'field3' => [ 'type' => 'email', 'section' => 'section1' ],
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field4' => [ 'type' => 'number', 'section' => 'section2' ]
			]
		];

		yield 'new order missing fields' => [
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section1' ]
			],
			'section1',
			[ 'field2' ],
			[
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section1' ]
			]
		];
	}

	/**
	 * @covers ::updateFieldProperties
	 * @dataProvider provideUpdateFieldProperties
	 */
	public function testUpdateFieldProperties(
		array $formDescriptor,
		string $fieldKey,
		array $newProperties,
		array $expected
	): void {
		RequestWikiFormUtils::updateFieldProperties( $formDescriptor, $fieldKey, $newProperties );
		$this->assertSame( $expected, $formDescriptor );
	}

	public function provideUpdateFieldProperties(): Generator {
		yield 'update existing field' => [
			[ 'field1' => [ 'type' => 'text', 'label' => 'Field 1' ] ],
			'field1',
			[ 'label' => 'Updated Field 1', 'default' => 'default value' ],
			[
				'field1' => [
					'type' => 'text',
					'label' => 'Updated Field 1',
					'default' => 'default value'
				]
			]
		];

		yield 'field does not exist' => [
			[ 'field1' => [ 'type' => 'text' ] ],
			'field2',
			[ 'label' => 'New Field' ],
			[ 'field1' => [ 'type' => 'text' ] ]
		];
	}

	/**
	 * @covers ::getFieldsInSection
	 * @dataProvider provideGetFieldsInSection
	 */
	public function testGetFieldsInSection(
		array $formDescriptor,
		string $section,
		array $expected
	): void {
		$result = RequestWikiFormUtils::getFieldsInSection( $formDescriptor, $section );
		$this->assertSame( $expected, $result );
	}

	public function provideGetFieldsInSection(): Generator {
		yield 'fields in section' => [
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ],
				'field3' => [ 'type' => 'email', 'section' => 'section2' ]
			],
			'section1',
			[
				'field1' => [ 'type' => 'text', 'section' => 'section1' ],
				'field2' => [ 'type' => 'checkbox', 'section' => 'section1' ]
			]
		];

		yield 'no fields in section' => [
			[ 'field1' => [ 'type' => 'text', 'section' => 'section1' ] ],
			'sectionX',
			[]
		];
	}
}
