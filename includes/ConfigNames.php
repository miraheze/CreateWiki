<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
namespace Miraheze\CreateWiki;

/**
 * A class containing constants representing the names of configuration variables,
 * to protect against typos.
 */
class ConfigNames {

	public const AIThreshold = 'CreateWikiAIThreshold';

	public const AutoApprovalFilter = 'CreateWikiAutoApprovalFilter';

	public const CacheDirectory = 'CreateWikiCacheDirectory';

	public const CacheType = 'CreateWikiCacheType';

	public const CannedResponses = 'CreateWikiCannedResponses';

	public const Categories = 'CreateWikiCategories';

	public const Collation = 'CreateWikiCollation';

	public const Containers = 'CreateWikiContainers';

	public const DatabaseClusters = 'CreateWikiDatabaseClusters';

	public const DatabaseClustersInactive = 'CreateWikiDatabaseClustersInactive';

	public const DatabaseSuffix = 'CreateWikiDatabaseSuffix';

	public const DisallowedSubdomains = 'CreateWikiDisallowedSubdomains';

	public const EmailNotifications = 'CreateWikiEmailNotifications';

	public const EnableManageInactiveWikis = 'CreateWikiEnableManageInactiveWikis';

	public const EnableRESTAPI = 'CreateWikiEnableRESTAPI';

	public const InactiveExemptReasonOptions = 'CreateWikiInactiveExemptReasonOptions';

	public const NotificationEmail = 'CreateWikiNotificationEmail';

	public const OpenAIConfig = 'CreateWikiOpenAIConfig';

	public const PersistentModelFile = 'CreateWikiPersistentModelFile';

	public const Purposes = 'CreateWikiPurposes';

	public const RequestCountWarnThreshold = 'CreateWikiRequestCountWarnThreshold';

	public const ShowBiographicalOption = 'CreateWikiShowBiographicalOption';

	public const SQLFiles = 'CreateWikiSQLFiles';

	public const StateDays = 'CreateWikiStateDays';

	public const Subdomain = 'CreateWikiSubdomain';

	public const UseClosedWikis = 'CreateWikiUseClosedWikis';

	public const UseEchoNotifications = 'CreateWikiUseEchoNotifications';

	public const UseExperimental = 'CreateWikiUseExperimental';

	public const UseInactiveWikis = 'CreateWikiUseInactiveWikis';

	public const UseJobQueue = 'CreateWikiUseJobQueue';

	public const UsePrivateWikis = 'CreateWikiUsePrivateWikis';

	/**
	 * RequestWiki config
	 */

	public const RequestWikiConfirmAgreement = 'RequestWikiConfirmAgreement';

	public const RequestWikiConfirmEmail = 'RequestWikiConfirmEmail';

	public const RequestWikiMinimumLength = 'RequestWikiMinimumLength';
}
