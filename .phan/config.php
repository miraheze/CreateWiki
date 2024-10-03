<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.0';

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

$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
];

return $cfg;
