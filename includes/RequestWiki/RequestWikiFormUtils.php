<?php

namespace Miraheze\CreateWiki\RequestWiki;

use function array_keys;
use function array_merge;
use function array_search;
use function array_slice;

class RequestWikiFormUtils {

	public static function fieldExists(
		array &$formDescriptor,
		string $fieldKey
	): bool {
		return isset( $formDescriptor[$fieldKey] );
	}

	public static function reorderSections(
		array &$formDescriptor,
		array $newSectionOrder
	): void {
		$sections = [];
		$noSectionFields = [];

		foreach ( $formDescriptor as $key => $field ) {
			$section = $field['section'] ?? null;
			if ( $section ) {
				$sections[$section][$key] = $field;
				continue;
			}

			$noSectionFields[$key] = $field;
		}

		$formDescriptor = [];

		foreach ( $newSectionOrder as $section ) {
			if ( isset( $sections[$section] ) ) {
				$formDescriptor += $sections[$section];
				unset( $sections[$section] );
			}
		}

		foreach ( $sections as $remainingFields ) {
			$formDescriptor += $remainingFields;
		}

		$formDescriptor += $noSectionFields;
	}

	public static function addFieldToBeginning(
		array &$formDescriptor,
		string $newKey,
		array $newField
	): void {
		$formDescriptor = [ $newKey => $newField ] + $formDescriptor;
	}

	public static function addFieldToEnd(
		array &$formDescriptor,
		string $newKey,
		array $newField
	): void {
		$formDescriptor += [ $newKey => $newField ];
	}

	public static function removeFieldByKey(
		array &$formDescriptor,
		string $key
	): void {
		if ( $formDescriptor[$key] ?? false ) {
			unset( $formDescriptor[$key] );
		}
	}

	public static function moveFieldToSection(
		array &$formDescriptor,
		string $fieldKey,
		string $newSection
	): void {
		if ( $formDescriptor[$fieldKey] ?? false ) {
			$formDescriptor[$fieldKey]['section'] = $newSection;
		}
	}

	public static function insertFieldAfter(
		array &$formDescriptor,
		string $afterKey,
		string $newKey,
		array $newField
	): void {
		// Find the position of the target field
		$pos = array_search( $afterKey, array_keys( $formDescriptor ), true );

		if ( $pos === false ) {
			// If the target field is not found, add to the end
			$formDescriptor[$newKey] = $newField;
			return;
		}

		// Split the array and insert the new field after the target field
		$formDescriptor = array_slice( $formDescriptor, 0, $pos + 1, true ) +
			[ $newKey => $newField ] +
			array_slice( $formDescriptor, $pos + 1, null, true );
	}

	public static function insertFieldAtBeginningOfSection(
		array &$formDescriptor,
		string $section,
		string $newKey,
		array $newField
	): void {
		$firstKeyInSection = null;

		// Find the first field in the specified section
		foreach ( $formDescriptor as $key => $field ) {
			if ( ( $field['section'] ?? '' ) === $section ) {
				$firstKeyInSection = $key;
				break;
			}
		}

		if ( $firstKeyInSection === null ) {
			// If no fields found for the section, append the new field at the end
			$formDescriptor[$newKey] = $newField;
		} else {
			// Insert the new field before the first field in the section
			$pos = array_search( $firstKeyInSection, array_keys( $formDescriptor ), true );
			$formDescriptor = array_slice( $formDescriptor, 0, $pos, true ) +
				[ $newKey => $newField ] +
				array_slice( $formDescriptor, $pos, null, true );
		}
	}

	public static function insertFieldAtEndOfSection(
		array &$formDescriptor,
		string $section,
		string $newKey,
		array $newField
	): void {
		$lastKeyInSection = null;

		// Iterate over the form descriptor to find the last field in the specified section
		foreach ( $formDescriptor as $key => $field ) {
			if ( ( $field['section'] ?? '' ) === $section ) {
				$lastKeyInSection = $key;
			}
		}

		if ( $lastKeyInSection === null ) {
			// If no fields found for the section, append the new field at the end
			$formDescriptor[$newKey] = $newField;
		} else {
			// Insert the new field after the last field in the section
			$pos = array_search( $lastKeyInSection, array_keys( $formDescriptor ), true );
			$formDescriptor = array_slice( $formDescriptor, 0, $pos + 1, true ) +
				[ $newKey => $newField ] +
				array_slice( $formDescriptor, $pos + 1, null, true );
		}
	}

	public static function cloneFieldToSection(
		array &$formDescriptor,
		string $fieldKey,
		string $newKey,
		string $newSection
	): void {
		if ( $formDescriptor[$fieldKey] ?? false ) {
			$clonedField = $formDescriptor[$fieldKey];
			$clonedField['section'] = $newSection;

			// Insert the cloned field at the end of the specified section
			self::insertFieldAtEndOfSection(
				$formDescriptor,
				$newSection,
				$newKey,
				$clonedField
			);
		}
	}

	public static function reorderFieldsInSection(
		array &$formDescriptor,
		string $section,
		array $newOrder
	): void {
		$fieldsInSection = [];

		// Collect fields in the specified section
		foreach ( $formDescriptor as $key => $field ) {
			if ( ( $field['section'] ?? '' ) === $section ) {
				$fieldsInSection[$key] = $field;
				// Remove it from original position
				unset( $formDescriptor[$key] );
			}
		}

		// Add them back to the descriptor in the new order
		foreach ( $newOrder as $key ) {
			if ( $fieldsInSection[$key] ?? false ) {
				$formDescriptor[$key] = $fieldsInSection[$key];
			}
		}

		// Append any fields that were not included in the new order array
		foreach ( $fieldsInSection as $key => $field ) {
			if ( !isset( $formDescriptor[$key] ) ) {
				$formDescriptor[$key] = $field;
			}
		}
	}

	public static function updateFieldProperties(
		array &$formDescriptor,
		string $fieldKey,
		array $newProperties
	): void {
		if ( $formDescriptor[$fieldKey] ?? false ) {
			$formDescriptor[$fieldKey] = array_merge(
				$formDescriptor[$fieldKey],
				$newProperties
			);
		}
	}

	public static function unsetFieldProperty(
		array &$formDescriptor,
		string $fieldKey,
		string $propertyKey
	): void {
		if (
			isset( $formDescriptor[$fieldKey] ) &&
			isset( $formDescriptor[$fieldKey][$propertyKey] )
		) {
			unset( $formDescriptor[$fieldKey][$propertyKey] );
		}
	}

	public static function getFieldsInSection(
		array &$formDescriptor,
		string $section
	): array {
		$fieldsInSection = [];

		foreach ( $formDescriptor as $key => $field ) {
			if ( ( $field['section'] ?? '' ) === $section ) {
				$fieldsInSection[$key] = $field;
			}
		}

		return $fieldsInSection;
	}
}
