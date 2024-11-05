<?php

namespace Miraheze\CreateWiki\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;
	private LoggerInterface $logger;

	private int $id;
	private string $reason;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		CreateWikiHookRunner $hookRunner,
		WikiRequestManager $wikiRequestManager
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
		$this->wikiRequestManager = $wikiRequestManager;
		$this->logger = LoggerFactory::getInstance( 'CreateWiki' );

		$this->id = $params['id'];
		$this->reason = $params['reason'];
	}

	public function run(): bool {
		$this->wikiRequestManager->loadFromID( $this->id );
		$this->logger->debug( 'Loaded request' . $this->id . 'for AI approval.' );

		// Make API request to ChatGPT's Assistant API
		$this->logger->debug( 'Began query to OpenAI for' . $this->id );
		$apiResponse = $this->queryChatGPT( $this->reason );

		if ( $apiResponse ) {
			$outcome = $apiResponse['outcome'] ?? 'reject';
			$this->logger->debug( 'AI outcome for' . $this->id . 'was' . $apiResponse['outcome'] );

			if ( $outcome === 'approve' ) {
				// Start query builder so that it can set the status
				$this->wikiRequestManager->startQueryBuilder();

				$this->wikiRequestManager->approve(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: 'Request automatically approved!'
				);

				// Execute query builder to commit the status change
				$this->wikiRequestManager->tryExecuteQueryBuilder();

				$this->logger->debug( 'Wiki request' . $this->id . 'automatically approved by AI decision.' );
			} elseif ( $outcome === 'revise' ) {
				$this->wikiRequestManager->startQueryBuilder();

				$this->wikiRequestManager->moredetails(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: 'Your request needs further details.'
				);

				$this->wikiRequestManager->tryExecuteQueryBuilder();

				$this->logger->debug( 'Wiki request' . $this->id . 'needs more details.' );
			} elseif ( $outcome === 'reject' ) {
				$this->wikiRequestManager->startQueryBuilder();

				$this->wikiRequestManager->decline(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: 'We couldn\'t approve your request at this time.'
				);

				$this->wikiRequestManager->tryExecuteQueryBuilder();

				$this->logger->debug( 'Wiki request' . $this->id . 'rejected by AI decision.' );
			} else {
				$this->wikiRequestManager->addComment(
					comment: rtrim( 'This request could not be automatically approved. Your request has been queued for manual review.' ),
					user: User::newSystemUser( 'CreateWiki Extension' ),
					log: false,
					type: 'comment',
					// Use all involved users
					notifyUsers: []
				);

				$this->logger->debug( 'Wiki request' . $this->id . 'could not be approved. Check logs for details.' );
			}
		}

		return true;
	}

	private function queryChatGPT( string $reason ): ?array {
		$baseApiUrl = 'https://api.openai.com/v1';
		$apiKey = $this->config->get( ConfigNames::OpenAIAPIKey );

		// Step 1: Create a new thread
		$threadRequest = new MWHttpRequest( "$baseApiUrl/threads", [
			'method' => 'POST',
			'postData' => json_encode( [ "messages" => [ [ "role" => "user", "content" => $reason ] ] ] ),
		], __METHOD__ );
		$threadRequest->setHeader( 'Authorization', 'Bearer ' . $apiKey );
		$threadRequest->setHeader( 'Content-Type', 'application/json' );
		$threadRequest->setHeader( 'OpenAI-Beta', 'assistants=v2' );

		$threadResponse = $threadRequest->execute();
		$this->logger->debug( 'Queried OpenAI for a decision.' );

		$threadData = json_decode( $threadResponse, true );
		$threadId = $threadData['id'] ?? null;

		if ( !$threadId ) {
			$this->logger->error( 'OpenAI did not return threadId! Instead returned: ' . json_encode( $threadData ) );
			return null;
		}

		// Step 2: Run the message
		$runRequest = new MWHttpRequest( "$baseApiUrl/threads/$threadId/run", [
			'method' => 'POST',
			'postData' => json_encode( [ "assistant_id" => $this->config->get( ConfigNames::OpenAIAssistantID ) ] ),
		], __METHOD__ );
		$runRequest->setHeader( 'Authorization', 'Bearer ' . $apiKey );
		$runRequest->setHeader( 'Content-Type', 'application/json' );
		$runRequest->setHeader( 'OpenAI-Beta', 'assistants=v2' );

		$runResponse = $runRequest->execute();
		$runData = json_decode( $runResponse, true );
		$runId = $runData['id'] ?? null;

		if ( !$runId ) {
			$this->logger->error( 'OpenAI did not return a runId. Instead returned: ' . json_encode( $runData ) );
			return null;
		}

		// Step 3: Poll the status of the run
		$status = 'running';

		while ( $status === 'running' ) {
			sleep( 3 );

			$statusRequest = new MWHttpRequest( "$baseApiUrl/threads/$threadId/runs/$runId", [], __METHOD__ );
			$statusRequest->setHeader( 'Authorization', 'Bearer ' . $apiKey );
			$statusRequest->setHeader( 'OpenAI-Beta', 'assistants=v2' );

			$statusResponse = $statusRequest->execute();
			$statusData = json_decode( $statusResponse, true );
			$status = $statusData['status'] ?? 'failed';

			if ( $status === 'failed' ) {
				$this->logger->error( 'Run ' . $runId . ' failed! OpenAI returned: ' . json_encode( $statusData ) );
				return null;
			}
		}

		// Step 4: Query for messages in the thread
		$messagesRequest = new MWHttpRequest( "$baseApiUrl/threads/$threadId/messages", [], __METHOD__ );
		$messagesRequest->setHeader( 'Authorization', 'Bearer ' . $apiKey );
		$messagesRequest->setHeader( 'Content-Type', 'application/json' );
		$messagesRequest->setHeader( 'OpenAI-Beta', 'assistants=v2' );

		$messagesResponse = $messagesRequest->execute();
		$messagesData = json_decode( $messagesResponse, true );

		$finalResponseContent = $messagesData['messages'][0]['content'] ?? null;
		return json_decode( $finalResponseContent, true );
	}
}
