<?php

namespace Miraheze\CreateWiki\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Http\HttpRequestFactory;
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
	private HttpRequestFactory $httpRequestFactory;
	private LoggerInterface $logger;

	private int $id;
	private string $reason;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		CreateWikiHookRunner $hookRunner,
		WikiRequestManager $wikiRequestManager,
		HttpRequestFactory $httpRequestFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
		$this->wikiRequestManager = $wikiRequestManager;
		$this->httpRequestFactory = $httpRequestFactory;
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
		$threadResponse = $this->httpRequestFactory->post(
			$baseApiUrl . '/threads',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
					'OpenAI-Beta' => 'assistants=v2'
				],
				'postData' => json_encode( [ "messages" => [ [ "role" => "user", "content" => $reason ] ] ] ),
			],
			__METHOD__
		);
		$this->logger->debug( 'Queried OpenAI for a decision.' );

		$threadData = json_decode( $threadResponse, true );
		$this->logger->debug( 'OpenAI replied with' . $threadData );
		$threadId = $threadData['id'] ?? null;

		if ( !$threadId ) {
			$this->logger->error( 'OpenAI did not return threadId! Instead returned: ' . json_encode( $threadData ) );
			return null;
		}

		// Step 2: Run the message
		$runResponse = $this->httpRequestFactory->post(
			$baseApiUrl . '/threads/' . $threadId . '/run',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
					'OpenAI-Beta' => 'assistants=v2'
				],
				'postData' => json_encode( [ "assistant_id" => $this->config->get( ConfigNames::OpenAIAssistantID ) ] ),
			],
			__METHOD__
		);
		$this->logger->debug( 'Queried OpenAI to run the message.' );

		if ( $runResponse === null ) {
			$this->logger->error( 'OpenAI did not return a runResponse.' );
			return null;
		}

		$runData = json_decode( $runResponse, true );
		$this->logger->debug( 'OpenAI replied with' . $runData );
		$runId = $runData['id'] ?? null;

		if ( !$runId ) {
			$this->logger->error( 'OpenAI did not return a runId. Instead returned: ' . json_encode( $runData ) );
			return null;
		}

		// Step 3: Poll the status of the run
		$status = 'running';
		$this->logger->debug( 'Status of run is ' . $status );

		while ( $status === 'running' ) {
			// Add delay between polls to avoid excessive requests
			sleep( 3 );

			$this->logger->debug( 'Querying status of wiki request decision for ' . $runId );

			$statusResponse = $this->httpRequestFactory->get(
				$baseApiUrl . '/threads/' . $threadId . '/runs/' . $runId,
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $apiKey,
						'OpenAI-Beta' => 'assistants=v2'
					],
				],
				__METHOD__
			);

			if ( $statusResponse === null ) {
				$this->logger->error( 'OpenAI did not return a statusResponse.' );
				return null;
			}

			$statusData = json_decode( $statusResponse, true );
			$this->logger->debug( 'OpenAI replied with' . $statusData );
			$status = $statusData['status'] ?? 'failed';

			if ( $status === 'completed' ) {
				$this->logger->debug( 'Run {$runId} was successful.' );
				break;
			} elseif ( $status === 'failed' ) {
				$this->logger->error( 'Run ' . $runId . ' failed! OpenAI returned: ' . json_encode( $statusData ) );
				return null;
			}
		}

		// Step 4: Query for messages in the thread to get the final response
		$messagesResponse = $this->httpRequestFactory->get(
			$baseApiUrl . '/threads/' . $threadId . '/messages',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
					'OpenAI-Beta' => 'assistants=v2'
				]
				],
			__METHOD__
		);
		$this->logger->debug( 'Queried OpenAI for final descision message.' );

		if ( $messagesResponse === null ) {
			$this->logger->debug( 'OpenAI did not return a messagesResponse.' );
			return null;
		}

		$messagesData = json_decode( $messagesResponse, true );
		$this->logger->debug( 'OpenAI replied with' . $messagesData );

		$finalResponseContent = $messagesData['messages'][0]['content'] ?? null;

/*		// Step 6: Delete the thread
		$deleteThreadUrl = wfAppendQuery( "$baseApiUrl/threads/$threadId", [] );
		$deleteResponse = $this->httpRequestFactory->delete(
			$baseApiUrl . '/threads/' . $threadId,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
					'OpenAI-Beta' => 'assistants=v2'
				]
			],
			__METHOD__
		);

		if ( $deleteResponse === null ) {
			$this->logger->error( 'Failed to delete thread ' . $threadId . '.' );
		} else {
			$this->logger->debug( 'Successfully deleted {$threadId}.' );
		}*/

		// Assuming the response contains an "outcome" field for simplicity
		return json_decode( $finalResponseContent, true );
	}
}
