<?php

namespace Miraheze\CreateWiki\RequestWiki;

use OOUI\FieldsetLayout;
use OOUI\LabelElement;
use OOUI\PanelLayout;

class RequestWikiHistory {

	public static function showHistorySection(array $historyEntries, $out) {

		$historyFieldset = new FieldsetLayout([
			'label' => 'History',
			'items' => [
				/*new LabelElement([
					'label' => 'History of actions',
				]),*/
				self::createHistoryTable($historyEntries),
			]
		]);

		// Add the fieldset to your output
		$out->addHTML($historyFieldset->toHTML());
	}

	private static function createHistoryTable($entries) {
		// Start building the HTML table
		$tableHTML = '<table class="oo-ui-table oo-ui-table-striped">';
		$tableHTML .= '<thead><tr>';
		$tableHTML .= '<th>Timestamp</th>';
		$tableHTML .= '<th>User</th>';
		$tableHTML .= '<th>Action</th>';
		$tableHTML .= '<th>Details</th>';
		$tableHTML .= '</tr></thead>';
		$tableHTML .= '<tbody>';

		// Loop through each entry and create table rows
		foreach ($entries as $entry) {
			$tableHTML .= '<tr>';
			$tableHTML .= '<td>' . htmlspecialchars($this->context->getLanguage()->timeanddate($entry['timestamp'], true)) . '</td>';
			$tableHTML .= '<td>' . htmlspecialchars($entry['user']->getName()) . '</td>';
			$tableHTML .= '<td>' . htmlspecialchars($entry['action']) . '</td>';
			$tableHTML .= '<td>' . htmlspecialchars($entry['details']) . '</td>';
			$tableHTML .= '</tr>';
		}

		$tableHTML .= '</tbody></table>';

		// Return the completed table HTML
		return $tableHTML;
	}
}
