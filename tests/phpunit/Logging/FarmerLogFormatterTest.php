<?php

namespace Miraheze\CreateWiki\Tests\Logging;

use MediaWiki\Logging\DatabaseLogEntry;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\TitleValue;
use MediaWikiIntegrationTestCase;
use Miraheze\CreateWiki\Logging\FarmerLogFormatter;
use function html_entity_decode;
use const ENT_QUOTES;
use const NS_SPECIAL;

/**
 * @group CreateWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\CreateWiki\Logging\FarmerLogFormatter
 */
class FarmerLogFormatterTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setUserLang( 'en' );
		$this->overrideConfigValue( MainConfigNames::LanguageCode, 'en' );
	}

	private function newFormatter( string $type, string $action, array $params ): FarmerLogFormatter {
		$entry = new ManualLogEntry( $type, $action );
		$entry->setPerformer( $this->getTestUser()->getUser() );
		$entry->setTarget( new TitleValue( NS_SPECIAL, 'RequestWikiQueue/7' ) );
		$entry->setParameters( $params );

		$logId = $entry->insert( $this->getDb() );
		$logEntry = DatabaseLogEntry::newFromId( $logId, $this->getDb() );
		$this->assertNotNull( $logEntry );

		$formatter = $this->getServiceContainer()->getLogFormatterFactory()
			->newFromEntry( $logEntry );

		$this->assertInstanceOf( FarmerLogFormatter::class, $formatter );
		return $formatter;
	}

	/**
	 * @covers ::formatParameterValue
	 * @covers ::formatRequestLink
	 */
	public function testRequestActionLinksRequest(): void {
		$formatter = $this->newFormatter( 'farmer', 'requestapprove', [
			'4:request-link:id' => 7,
		] );

		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'RequestWikiQueue/7', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
		$this->assertStringContainsString( 'approved', $html );
	}

	/**
	 * @covers ::formatParameterValue
	 * @covers ::formatRequestLink
	 */
	public function testSuppressionLogLinksRequest(): void {
		$this->setGroupPermissions( '*', 'createwiki-suppressionlog', true );

		$formatter = $this->newFormatter( 'farmersuppression', 'delete', [
			'4:request-link:id' => 7,
		] );

		$this->assertStringContainsString( '>#7</a>', $formatter->getActionText() );
	}

	/**
	 * @covers ::formatParameterValue
	 * @covers ::formatRequestLink
	 */
	public function testPlainTextHasNoHtml(): void {
		$formatter = $this->newFormatter( 'farmer', 'comment', [
			'4:request-link:id' => 7,
		] );

		$text = $formatter->getPlainActionText();
		$this->assertStringContainsString( '[[Special:RequestWikiQueue/7]]', $text );
		$this->assertStringNotContainsString( '<a ', $text );
	}

	/**
	 * @covers ::formatParameterValue
	 */
	public function testRequestWikiEntryKeepsOtherParameters(): void {
		$formatter = $this->newFormatter( 'farmer', 'requestwiki', [
			'4::sitename' => 'Test Wiki',
			'5::language' => 'en',
			'6::private' => 0,
			'7:request-link:id' => 7,
		] );

		$html = html_entity_decode( $formatter->getActionText(), ENT_QUOTES );
		$this->assertStringContainsString( '"Test Wiki"', $html );
		$this->assertStringContainsString( '>#7</a>', $html );
	}

	/**
	 * @covers ::formatParameterValue
	 */
	public function testStoredHtmlIsNotRendered(): void {
		$formatter = $this->newFormatter( 'farmer', 'createwiki', [
			'4::wiki' => '<script>alert(1)</script>',
		] );

		$this->assertStringNotContainsString( '<script>', $formatter->getActionText() );
	}
}
