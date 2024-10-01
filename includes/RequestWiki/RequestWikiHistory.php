<?php

namespace Miraheze\CreateWiki\RequestWiki;

use OOUI\FieldsetLayout;
use OOUI\LabelElement;
use OOUI\PanelLayout;
use OOUI\Element;

class RequestWikiHistory {

    public static function showHistorySection(array $historyEntries, $out) {
        $out->enableOOUI();
        $historyFieldset = new FieldsetLayout([
            'label' => 'History',
            'items' => [
                new Element([
                    'content' => 'History of actions',
                    'classes' => ['oo-ui-labelElement'],
                ]),
                self::createHistoryTable($historyEntries),
            ]
        ]);

        return $historyFieldset->toString();
    }

    private static function createHistoryTable(array $entries) {
        $tableHTML = '<table class="oo-ui-table oo-ui-table-striped">';
        $tableHTML .= '<thead><tr>';
        $tableHTML .= '<th>Timestamp</th>';
        $tableHTML .= '<th>User</th>';
        $tableHTML .= '<th>Action</th>';
        $tableHTML .= '<th>Details</th>';
        $tableHTML .= '</tr></thead>';
        $tableHTML .= '<tbody>';

        foreach ($entries as $entry) {
            $tableHTML .= '<tr>';
            $tableHTML .= '<td>' . htmlspecialchars($entry['timestamp']) . '</td>';
            $tableHTML .= '<td>' . htmlspecialchars($entry['user']->getName()) . '</td>';
            $tableHTML .= '<td>' . htmlspecialchars($entry['action']) . '</td>';
            $tableHTML .= '<td>' . htmlspecialchars($entry['details']) . '</td>';
            $tableHTML .= '</tr>';
        }

        $tableHTML .= '</tbody></table>';

        $panel = new PanelLayout([
            'content' => $tableHTML,
            'classes' => ['oo-ui-panel', 'oo-ui-panel-framed'],
        ]);

        return $panel;
    }
}
