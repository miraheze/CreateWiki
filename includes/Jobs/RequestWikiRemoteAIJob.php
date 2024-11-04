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

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;
	private HttpRequestFactory $httpRequestFactory;
	private LoggerFactory $loggerFactory;

	private int $id;
	private string $reason;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		CreateWikiHookRunner $hookRunner,
		WikiRequestManager $wikiRequestManager,
		HttpRequestFactory $httpRequestFactory,
		LoggerFactory $loggerFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
		$this->wikiRequestManager = $wikiRequestManager;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->loggerFactory = $loggerFactory;

		$this->id = $params['id'];
		$this->reason = $params['reason'];
	}

	public function run(): bool {
		$this->wikiRequestManager->loadFromID( $this->id );

		// Make API request to ChatGPT's Assistant API
		$apiResponse = $this->queryChatGPT( $this->reason );

		if ( $apiResponse ) {
			$outcome = $apiResponse['outcome'] ?? 'reject';

			if ( $outcome === 'approve' ) {
				// Start query builder so that it can set the status
				$this->wikiRequestManager->startQueryBuilder();

				$this->wikiRequestManager->approve(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: 'Request automatically approved!'
				);

				// Execute query builder to commit the status change
				$this->wikiRequestManager->tryExecuteQueryBuilder();

				$this->loggerFactory->getInstance( 'CreateWiki' )->debug( 'Wiki request' . $this->id . 'automatically approved by AI decision.' );
			} elseif ( $outcome === 'revise' ) {
				$this->wikiRequestManager->moredetails(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: 'Your request needs further details.'
				);
			} elseif ( $outcome === 'reject' ) {
				$this->wikiRequestManager->decline(
					user: User::newSystemUser( 'CreateWiki Extension' ),
					comment: 'We couldn\'t approve your request at this time.'
				);
			}
		}

		return true;
	}

	private function queryChatGPT( string $reason ): ?array {
		$baseApiUrl = 'https://api.openai.com/v2/assistants';
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

		$threadData = json_decode( $threadResponse, true );
		$threadId = $threadData['id'] ?? null;

		if ( !$threadId ) {
			$this->loggerFactory->getInstance( 'CreateWiki' )->error( 'OpenAI did not return a threadId! Instead returned: {$threadData}' );
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

		if ( $runResponse === null ) {
			$this->loggerFactory->getInstance( 'CreateWiki' )->error( 'OpenAI did not return a runResponse.' );
			return null;
		}

		$runData = json_decode( $threadResponse, true );
		$runId = $threadData['id'] ?? null;
		if ( !$runId ) {
			$this->loggerFactory->getInstance( 'CreateWiki' )->error( 'OpenAI did not return a runId. Instead returned: {$runData}' );
			return null;
		}

		// Step 3: Poll the status of the run
		$status = 'running';
		while ( $status === 'running' ) {
			sleep( 3 ); // Add delay between polls to avoid excessive requests

			$this->loggerFactory->getInstance( 'CreateWiki' )->debug( 'Querying status of wiki request decision for ' . $runId );

			$statusResponse = $this->httpRequestFactory->get(
				$baseApiUrl . '/threads/' . $threadId . '/runs/' . '$runId',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $apiKey,
						'OpenAI-Beta' => 'assistants=v2'
					],
				],
				__METHOD__
			);

			if ( $statusResponse === null ) {
				$this->loggerFactory->getInstance( 'CreateWiki' )->error( 'OpenAI did not return a statusResponse.' );
				return null;
			}

			$statusData = json_decode( $statusResponse, true );
			$status = $statusData['status'] ?? 'failed';

			if ( $status === 'completed' ) {
				$this->loggerFactory->getInstance( 'CreateWiki' )->debug( 'Run {$runId} was successful.' );
				break;
			} elseif ( $status === 'failed' ) {
				$this->loggerFactory->getInstance( 'CreateWiki' )->error( 'Run {$runId} failed! OpenAI returned: {$statusData}' );
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

		if ( $messagesResponse === null ) {
			$this->loggerFactory->getInstance( 'CreateWiki' )->debug( 'OpenAI did not return a messagesResponse.' );
			return null;
		}

		$messagesData = json_decode( $messagesResponse, true );
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
			$this->loggerFactory->getInstance( 'CreateWiki' )->error( 'Failed to delete thread {$threadId}.' );
		} else {
			$this->loggerFactory->getInstance( 'CreateWiki' )->debug( 'Successfully deleted {$threadId}.' );
		}*/

		// Assuming the response contains an "outcome" field for simplicity
		return json_decode( $finalResponseContent, true );
	}
}
