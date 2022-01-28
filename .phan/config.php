<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/Echo',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/Echo',
	]
);

// We intentionally explicitly specified this so that it picks up on deprecations, etc..
$cfg['suppress_issue_types'] = [
	'PhanTypeArraySuspiciousNullable',
	'SecurityCheck-LikelyFalsePositive',
];

return $cfg;
