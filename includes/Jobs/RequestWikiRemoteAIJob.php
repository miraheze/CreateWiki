<?php

namespace Miraheze\CreateWiki\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Hooks\CreateWikiHookRunner;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private Config $config;
	private CreateWikiHookRunner $hookRunner;
	private WikiRequestManager $wikiRequestManager;
	private Client $httpClient;
	private LoggerInterface $logger;
	private string $baseApiUrl;
	private string $apiKey;

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

		$proxy = $this->config->get( 'HTTPProxy' );

		$guzzleOptions = [
			'base_uri' => 'https://api.openai.com/v1',
		];

		if ( !empty( $proxy ) ) {
			$guzzleOptions['proxy'] = $proxy;
		}

		$this->httpClient = new Client( $guzzleOptions );

		$this->baseApiUrl = 'https://api.openai.com/v1';
		$this->apiKey = $this->config->get( ConfigNames::OpenAIAPIKey );

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
		try {
			// Step 1: Create a new thread
			$threadResponse = $this->createRequest( "/threads", 'POST', [
				'json' => [ "messages" => [ [ "role" => "user", "content" => $reason ] ] ],
			] );
			$threadData = json_decode( $threadResponse->getBody()->getContents(), true );
			$threadId = $threadData['id'] ?? null;

			if ( !$threadId ) {
				$this->logger->error( 'OpenAI did not return threadId! Instead returned: ' . json_encode( $threadData ) );
				return null;
			}

			// Step 2: Run the message
			$runResponse = $this->createRequest( "/threads/$threadId/run", 'POST', [
				'json' => [ "assistant_id" => $this->config->get( ConfigNames::OpenAIAssistantID ) ],
			] );
			$runData = json_decode( $runResponse->getBody()->getContents(), true );
			$runId = $runData['id'] ?? null;

			if ( !$runId ) {
				$this->logger->error( 'OpenAI did not return a runId. Instead returned: ' . json_encode( $runData ) );
				return null;
			}

			// Step 3: Poll the status of the run
			$status = 'running';
			while ( $status === 'running' ) {
				sleep( 3 );
				$statusResponse = $this->createRequest( "/threads/$threadId/runs/$runId" );
				$statusData = json_decode( $statusResponse->getBody()->getContents(), true );
				$status = $statusData['status'] ?? 'failed';

				if ( $status === 'failed' ) {
					$this->logger->error( 'Run ' . $runId . ' failed! OpenAI returned: ' . json_encode( $statusData ) );
					return null;
				}
			}

			// Step 4: Query for messages in the thread
			$messagesResponse = $this->createRequest( "/threads/$threadId/messages" );
			$messagesData = json_decode( $messagesResponse->getBody()->getContents(), true );

			$finalResponseContent = $messagesData['messages'][0]['content'] ?? null;
			return json_decode( $finalResponseContent, true );
		} catch ( RequestException $e ) {
			$this->logger->error( 'HTTP request failed: ' . $e->getMessage() );
			return null;
		}
	}

	private function createRequest( string $endpoint, string $method = 'GET', array $options = [] ): ?ResponseInterface {
		$url = $this->baseApiUrl . $endpoint;

		// Set default headers and merge with any additional options
		$options['headers'] = array_merge( [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type'  => 'application/json',
			'OpenAI-Beta'   => 'assistants=v2',
		], $options['headers'] ?? [] );

		return $this->httpClient->request( $method, $url, $options );
	}
}
