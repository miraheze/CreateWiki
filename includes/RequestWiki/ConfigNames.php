<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\CreateWiki\RequestWiki;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const Enabled = 'RequestWiki';

	public const ConfirmAgreement = 'RequestWikiConfirmAgreement';

	public const ConfirmEmail = 'RequestWikiConfirmEmail';

	public const MinimumLength = 'RequestWikiMinimumLength';

	public const UseDescriptions = 'RequestWikiUseDescriptions';
}
