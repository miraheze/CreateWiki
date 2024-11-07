<?php

namespace Miraheze\CreateWiki\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\CreateWikiRegexConstraint;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;

class RequestWikiRemoteAIJob extends Job {

	public const JOB_NAME = 'RequestWikiRemoteAIJob';

	private Config $config;
	private WikiRequestManager $wikiRequestManager;
	private HttpRequestFactory $httpRequestFactory;
	private LoggerInterface $logger;
	private string $baseApiUrl;
	private string $apiKey;

	private int $id;
	private string $reason;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		WikiRequestManager $wikiRequestManager,
		HttpRequestFactory $httpRequestFactory
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->config = $configFactory->makeConfig( 'CreateWiki' );
		$this->wikiRequestManager = $wikiRequestManager;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->logger = LoggerFactory::getInstance( 'CreateWiki' );

		$this->baseApiUrl = 'https://api.openai.com/v1';
		$this->apiKey = $this->config->get( ConfigNames::OpenAIConfig )['apikey'];

		$this->id = $params['id'];
		$this->reason = $params['reason'];
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();

		$this->wikiRequestManager->loadFromID( $this->id );
		$this->logger->debug( "Loaded request {$this->id} for AI approval." );

		if ( !$this->canAutoApprove() ) {
			$this->logger->debug( "Wiki request {$this->id} was not auto-evaluated due to denylist." );
			return true;
		}

		// Initiate OpenAI query for decision
		$this->logger->info( "Querying OpenAI for decision on wiki request {$this->id}..." );
		$apiResponse = $this->queryOpenAI( $this->reason );

		if ( !$apiResponse ) {
			return true;
		}

		// Extract response details with default fallbacks
		$outcome = $apiResponse['recommendation']['outcome'] ?? 'reject';
		$comment = $apiResponse['recommendation']['public_comment'] ?? 'No comment provided. Please check logs.';

		$this->logger->info( "AI decision: {$outcome} for request {$this->id}. Comment: {$comment}" );

		if ( $this->config->get( ConfigNames::OpenAIConfig )['dryrun'] ) {
			return $this->handleDryRun( $outcome, $comment );
		} else {
			return $this->handleLiveRun( $outcome, $comment );
		}
	}

	private function handleDryRun( string $outcome, string $comment ): bool {
		$services = MediaWikiServices::getInstance();
		$outcomeMessage = Message::newFromKey( "requestwikiqueue-$outcome" )->text();
		$commentText = Message::newFromKey( 'requestwiki-ai-decision-dryrun' )
		->params( $outcomeMessage, $comment )
		->inLanguage( $services->getContentLanguage() )
		->text();

		$this->wikiRequestManager->addComment(
			comment: $commentText,
			user: User::newSystemUser( 'CreateWiki AI' ),
			log: true,
			type: 'comment',
			notifyUsers: []
		);

		$dryRunMessages = [
			'approve' => "Wiki request {$this->id} was approved by AI but not automatically created.",
			'moredetails' => "Wiki request {$this->id} needs revision but was not automatically marked.",
			'decline' => "Wiki request {$this->id} was declined by AI but not automatically marked.",
			'onhold' => "Wiki request {$this->id} requires manual review.",
		];

		$this->logger->debug( "DRY RUN: " . ( $dryRunMessages[$outcome] ?? "Unknown outcome for request {$this->id}." ), [
			'id' => $this->id,
			'reasoning' => $comment,
		] );

		return true;
	}

	private function handleLiveRun( string $outcome, string $comment ): bool {
		$services = MediaWikiServices::getInstance();
		$systemUser = User::newSystemUser( 'CreateWiki AI' );
		$commentText = Message::newFromKey( "requestwiki-ai-decision-$outcome" )
		->params( $comment )
		->inLanguage( $services->getContentLanguage() )
		->text();

		switch ( $outcome ) {
			case 'approve':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->approve(
					user: $systemUser,
					comment: $commentText
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$this->logger->debug( "Request {$this->id} auto-approved by AI.\nReason: $comment" );
				break;

			case 'moredetails':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->moredetails(
					user: $systemUser,
					comment: $commentText
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$this->logger->debug( "Request {$this->id} requires more details.\nReason: $comment" );
				break;

			case 'decline':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->decline(
					user: $systemUser,
					comment: $commentText
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				$this->logger->debug( "Request {$this->id} declined by AI.\nReason: $comment" );
				break;

			case 'onhold':
			default:
				$this->wikiRequestManager->addComment(
					comment: $commentText,
					user: $systemUser,
					log: false,
					type: 'comment',
					notifyUsers: []
				);
				$this->logger->debug( "Request {$this->id} queued for manual review." );
				break;
		}

		return true;
	}

	private function queryOpenAI( string $reason ): ?array {
		try {
			$sanitizedReason = trim( str_replace( [ "\r\n", "\r" ], "\n", $reason ) );

			// Step 1: Create a new thread
			$threadData = $this->createRequest( "/threads", 'POST', [
				"messages" => [ [ "role" => "user", "content" => $sanitizedReason ] ]
			] );

			$threadId = $threadData['id'] ?? null;

			$this->logger->debug( 'Stage 1 for AI decision: Created thread.' );

			$this->logger->debug( 'OpenAI returned for stage 1: ' . json_encode( $threadData ) );

			if ( !$threadId ) {
				$this->logger->error( 'OpenAI did not return a threadId!' );
				return null;
			}

			// Step 2: Run the message
			$runData = $this->createRequest( "/threads/$threadId/runs", 'POST', [
				"assistant_id" => $this->config->get( ConfigNames::OpenAIConfig )['assistantid']
			] );

			$runId = $runData['id'] ?? null;

			$this->logger->debug(
				'Stage 2 for AI decision of #{id}: Message ran.',
				[
					'id' => $this->id
				]
			);

			$this->logger->debug(
				'OpenAI returned the following data for stage 2 of #{id}: {runData}',
				[
					'id' => $this->id,
					'runData' => json_encode( $runData ),
				]
			);

			$this->logger->debug( 'OpenAI returned for stage 2: ' . json_encode( $runData ) );

			if ( !$runId ) {
				$this->logger->error( 'OpenAI did not return a runId!' );
				return null;
			}

			// Step 3: Poll the status of the run
			$status = 'running';
			$this->logger->debug( 'Stage 3 for AI decision: Polling status...' );

			while ( $status === 'running' ) {
				sleep( 5 );

				$this->logger->debug( 'Sleeping for 5 seconds...' );
				$statusData = $this->createRequest( "/threads/$threadId/runs/$runId" );
				$status = $statusData['status'] ?? 'failed';

				$this->logger->debug(
					'Stage 2 for AI decision of #{id}: Retrieved run status for {runId}',
					[
						'id' => $this->id,
						'runId' => $runId
					]
				);

				$this->logger->debug(
					'OpenAI returned the following data for stage 3 of #{id}: {statusData}',
					[
						'id' => $this->id,
						'statusData' => json_encode( $statusData ),
					]
				);

				if ( $status === 'in_progress' ) {
					$status = 'running';
				} elseif ( $status === 'failed' ) {
					$this->logger->error(
						'Run {runId} failed for #{id}! OpenAI returned {statusData}',
						[
							'id' => $this->id,
							'runId' => $runid,
							'statusData' => json_encode( $statusData )
						]
					);
					return null;
				}
			}

			// Step 4: Query for messages in the thread
			$messagesData = $this->createRequest( "/threads/$threadId/messages" );

			$this->logger->debug(
				'Stage 4 for AI decision of #{id}: Queried for messages in thread {threadId}.',
				[
					'id' => $this->id,
					'threadId' => $threadId
				]
			);

			$this->logger->debug(
				'OpenAI returned the following data for stage 4 of #{id}: {messagesData}',
				[
					'id' => $this->id,
					'messagesData' => json_encode( $messagesData ),
				]
			);

			$finalResponseContent = $messagesData['data'][0]['content'][0]['text']['value'] ?? '';
			return json_decode( $finalResponseContent, true );
		} catch ( \Exception $e ) {
			$this->logger->error( 'HTTP request failed: ' . $e->getMessage() );
			return null;
		}
	}

	private function createRequest( string $endpoint, string $method = 'GET', array $data = [] ): ?array {
		$url = $this->baseApiUrl . $endpoint;

		$this->logger->debug( 'Creating HTTP request to OpenAI...' );

		// Create a multi-client
		$requestOptions = [
			'url' => $url,
			'method' => $method,
			'headers' => [
				'Authorization'	=> 'Bearer ' . $this->apiKey,
				'Content-Type'	=> 'application/json',
				'OpenAI-Beta'	=> 'assistants=v2',
			],
		];

		if ( $method === 'POST' ) {
			$requestOptions['body'] = json_encode( $data );
			$this->logger->debug( 'POST request detected. Attaching POST data to body...' );
		}

		$request = $this->httpRequestFactory->createMultiClient( [ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ] )
			->run(
				$requestOptions,
				[ 'reqTimeout' => '15' ]
			);

			$this->logger->debug(
				'HTTP request for #{id} to OpenAI executed. Response was: {request}',
				[
					'id' => $this->id,
					'request' => json_encode( $request )
				]
			);

		if ( $request['code'] !== 200 ) {
			$this->logger->error( 'Request to ' . $url . ' failed with status ' . json_encode( $request['code'] ) );
			return null;
		}

		return json_decode( $request['body'], true );
	}

	private function canAutoApprove(): bool {
		$filter = CreateWikiRegexConstraint::regexFromArray(
			$this->config->get( ConfigNames::AutoApprovalFilter ), '/(', ')+/',
			ConfigNames::AutoApprovalFilter
		);

		$this->logger->debug(
			'Checking wiki request #{id} against the auto approval denylist filter...',
			[
				'id' => $this->id
			]
		);

		if ( preg_match( $filter, strtolower( $this->reason ) ) ) {
			$this->logger->debug(
				'Wiki request #{id} matched against the auto approval denylist filter! A manual review is required.',
				[
					'id' => $this->id
				]
			);
			return false;
		}

		$this->logger->debug(
			'Wiki request #{id} passed the auto approval filter review!',
			[
				'id' => $this->id
			]
		);

		return true;
	}
}
