<?php

namespace Miraheze\CreateWiki\Maintenance;

use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class EmailBureaucrats extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Sends an email to all bureaucrats on the given wiki.' );

		$this->addOption( 'subject', 'The email subject.', true, true );
		$this->addOption( 'body',
			'The email body. Either directly or a file with the email contents to be sent. ' .
			'$1, if given, will be replaced with the wiki database name. ' .
			'$2, if given, will be replaced with the wiki server URL.',
		true, true );

		$this->addOption( 'parse-wikitext',
			'Whether to parse wikitext in --body. Only works if HTML email is enabled.'
		);

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$server = $this->getConfig()->get( MainConfigNames::Server );

		$body = $this->getOption( 'body' );
		if ( is_file( $body ) && filesize( $body ) > 0 ) {
			$body = file_get_contents( $body );
		}

		$bodyMessage = new RawMessage( $body, [ $dbname, $server ] );

		$bodyHtml = $bodyMessage->text();
		$bodyText = $bodyMessage->text();
		if ( $this->hasOption( 'parse-wikitext' ) ) {
			$bodyHtml = $bodyMessage->parse();
		}

		$notificationData = [
			'type' => 'custom-email',
			'subject' => $this->getOption( 'subject' ),
			'body' => [
				'html' => $bodyHtml,
				'text' => $bodyText,
			],
		];

		$this->getServiceContainer()->get( 'CreateWikiNotificationsManager' )
			->notifyBureaucrats( $notificationData, $dbname );
	}
}

// @codeCoverageIgnoreStart
return EmailBureaucrats::class;
// @codeCoverageIgnoreEnd
