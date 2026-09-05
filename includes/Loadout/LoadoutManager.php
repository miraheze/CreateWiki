<?php

namespace Miraheze\CreateWiki\Loadout;

use Exception;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Shell\Shell;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Maintenance\ImportLoadoutXmlDump;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Psr\Log\LoggerInterface;
use function array_keys;
use function count;
use function file_exists;
use function is_readable;

class LoadoutManager {

	/** The key the selected loadout is stored under in cw_extra. */
	public const string FIELD_NAME = 'loadout';

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::LoadoutConfigs,
	];

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ServiceOptions $options,
		// ModuleFactory if ManageWiki is installed. null otherwise.
		private readonly ?ModuleFactory $moduleFactory,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function isEnabled(): bool {
		return count( $this->options->get( ConfigNames::LoadoutConfigs ) ) > 0;
	}

	/**
	 * @return string[] The names of all configured loadouts.
	 */
	public function getLoadoutNames(): array {
		$loadouts = $this->options->get( ConfigNames::LoadoutConfigs );
		'@phan-var array<string, array> $loadouts';
		return array_keys( $loadouts );
	}

	/**
	 * Whether the request's loadout imports an XML dump, which provides a custom main page.
	 */
	public function willImportXml( array $extra ): bool {
		$loadoutName = $extra[self::FIELD_NAME] ?? '';
		if ( !$loadoutName ) {
			return false;
		}

		$loadouts = $this->options->get( ConfigNames::LoadoutConfigs );
		$loadout = $loadouts[ $loadoutName ] ?? [];
		$xmlFile = $loadout['xml'] ?? '';
		return (bool)$xmlFile;
	}

	public function applyLoadout( string $loadout, string $dbname ): void {
		$loadouts = $this->options->get( ConfigNames::LoadoutConfigs );
		if ( !isset( $loadouts[$loadout] ) ) {
			$this->logger->error(
				'Invalid loadout {loadout} specified for wiki {dbname}',
				[
					'dbname' => $dbname,
					'loadout' => $loadout,
				]
			);
			return;
		}

		$loadoutConfig = $loadouts[$loadout];

		if ( $loadoutConfig['extensions'] ?? [] ) {
			$this->addExtensions( $dbname, $loadoutConfig['extensions'] );
		}

		if ( $loadoutConfig['settings'] ?? [] ) {
			$this->modifySettings( $dbname, $loadoutConfig['settings'] );
		}

		// No XML file is fine, sometimes we just want to tweak extensions and/or settings.
		if ( $loadoutConfig['xml'] ?? '' ) {
			$this->importXmlDump( $dbname, $loadoutConfig['xml'] );
		}
	}

	private function addExtensions( string $dbname, array $extensions ): void {
		if ( $this->moduleFactory === null ) {
			return;
		}

		try {
			$mwExtensions = $this->moduleFactory->extensions( $dbname );
			$mwExtensions->add( $extensions );
			$mwExtensions->commit();
		} catch ( Exception $e ) {
			$this->logger->error(
				'Failed to enable loadout extensions for wiki {dbname}: {exception}',
				[
					'dbname' => $dbname,
					'exception' => $e->getMessage(),
					'extensions' => $extensions,
				]
			);
		}
	}

	private function modifySettings( string $dbname, array $settings ): void {
		if ( $this->moduleFactory === null ) {
			return;
		}

		try {
			$mwSettings = $this->moduleFactory->settings( $dbname );
			$mwSettings->modify( $settings, default: null );
			$mwSettings->commit();
		} catch ( Exception $e ) {
			$this->logger->error(
				'Failed to set loadout settings for wiki {dbname}: {exception}',
				[
					'dbname' => $dbname,
					'exception' => $e->getMessage(),
					'settings' => $settings,
				]
			);
		}
	}

	private function importXmlDump( string $dbname, string $xmlPath ): void {
		if ( !file_exists( $xmlPath ) || !is_readable( $xmlPath ) ) {
			$this->logger->error(
				'XML dump file {path} not found or not readable for wiki {dbname}',
				[
					'dbname' => $dbname,
					'path' => $xmlPath,
				]
			);
			return;
		}

		// The import needs to be done in a separate process because the
		// database lists in the current process do not contain the new wiki.
		$result = Shell::makeScriptCommand(
			ImportLoadoutXmlDump::class,
			[
				'--wiki', $dbname,
				'--xml', $xmlPath,
			]
		)->limits( [
			'memory' => 0,
			'filesize' => 0,
			'time' => 0,
			'walltime' => 0,
		] )->execute();

		if ( $result->getExitCode() !== 0 ) {
			$this->logger->error(
				'Failed to import the XML dump for wiki {dbname}: {error}',
				[
					'dbname' => $dbname,
					'error' => $result->getStderr(),
				]
			);
		}
	}
}
