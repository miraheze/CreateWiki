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

// We explicitly specify this so that it
// picks up on deprecations, and other issues
$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
	'PhanTypeArraySuspiciousNullable',
	'SecurityCheck-LikelyFalsePositive',
];

return $cfg;
