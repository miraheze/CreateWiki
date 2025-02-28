<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['minimum_target_php_version'] = '8.1';

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
	'SecurityCheck-LikelyFalsePositive',
];

$cfg['strict_method_checking'] = true;
$cfg['strict_object_checking'] = true;
$cfg['strict_param_checking'] = true;
$cfg['strict_property_checking'] = true;
$cfg['strict_return_checking'] = true;

return $cfg;
