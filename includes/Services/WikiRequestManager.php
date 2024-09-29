<?php

namespace Miraheze\CreateWiki\Services;

use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\CreateWiki\Jobs\CreateWikiJob;
use Miraheze\CreateWiki\Jobs\RequestWikiAIJob;
use RuntimeException;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Rdbms\UpdateQueryBuilder;

class WikiRequestManager {

	public const CONSTRUCTOR_OPTIONS = [
		'CreateWikiAIThreshold',
		'CreateWikiGlobalWiki',
		'CreateWikiUseJobQueue',
	];

	private ServiceOptions $options;
	private IDatabase $dbw;

	private ?UpdateQueryBuilder $queryBuilder = null;

	private WikiManagerFactory $wikiManagerFactory;

	private stdClass|bool $row;
	private LinkRenderer $linkRenderer;
	private UserFactory $userFactory;
	private CreateWikiNotificationsManager $notificationsManager;
	private IConnectionProvider $connectionProvider;
	private JobQueueGroupFactory $jobQueueGroupFactory;

	private int $ID;
	private array $changes = [];

	public function __construct(
		IConnectionProvider $connectionProvider,
		CreateWikiNotificationsManager $notificationsManager,
		JobQueueGroupFactory $jobQueueGroupFactory,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		WikiManagerFactory $wikiManagerFactory,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->connectionProvider = $connectionProvider;
		$this->notificationsManager = $notificationsManager;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->linkRenderer = $linkRenderer;
		$this->userFactory = $userFactory;
		$this->wikiManagerFactory = $wikiManagerFactory;
		$this->options = $options;
	}

	public function fromID( int $requestID ): void {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->options->get( 'CreateWikiGlobalWiki' )
		);

		$this->ID = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_requests' )
			->field( '*' )
			->where( [ 'cw_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	public function exists(): bool {
		return (bool)$this->row;
	}

	public function addComment(
		string $comment,
		User $user,
		bool $log,
		string $type
	) {
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'cw_comments' )
			->row( [
				'cw_id' => $this->ID,
				'cw_comment' => $comment,
				'cw_comment_timestamp' => $this->dbw->timestamp(),
				'cw_comment_user' => $user->getId(),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->sendNotification( $comment, $type, $user );

		if ( $log ) {
			$this->log( $user, 'comment' );
		}
	}

	private function sendNotification(
		string $comment,
		string $type,
		User $user
	): void {
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->ID )->getFullURL();

		$involvedUsers = array_values( array_filter(
			array_diff( $this->getInvolvedUsers(), [ $user ] )
		) );

		$notificationData = [
			'type' => "request-{$type}",
			'extra' => [
				'request-url' => $requestLink,
				'comment' => $comment,
			],
		];

		$this->notificationsManager->sendNotification( $notificationData, $involvedUsers );
	}

	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_comments' )
			->field( '*' )
			->where( [ 'cw_id' => $this->ID ] )
			->orderBy( 'cw_comment_timestamp', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$comments = [];
		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromId( $row->cw_comment_user );

			$comments[] = [
				'comment' => $row->cw_comment,
				'timestamp' => $row->cw_comment_timestamp,
				'user' => $user,
			];
		}

		return $comments;
	}

	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
	}

	public function approve( User $user, string $comment ): void {
		if ( $this->options->get( 'CreateWikiUseJobQueue' ) ) {
			$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
			$jobQueueGroup->push(
				new JobSpecification(
					CreateWikiJob::JOB_NAME,
					[
						'id' => $this->ID,
						'dbname' => $this->getDBname(),
						'sitename' => $this->getSitename(),
						'language' => $this->getLanguage(),
						'private' => $this->isPrivate(),
						'category' => $this->getCategory(),
						'requester' => $this->getRequester()->getName(),
						'creator' => $user->getName(),
					]
				)
			);

			$this->setStatus( 'approved' );

			$this->addComment(
				comment: 'Request approved. ' . $comment,
				user: $user,
				log: false,
				type: 'comment'
			);

			$this->log( $user, 'requestapprove' );

			if ( !is_int( $this->options->get( 'CreateWikiAIThreshold' ) ) ) {
				$this->tryAutoCreate();
			}
		} else {
			$wikiManager = $this->wikiManagerFactory->newInstance( $this->getDBname() );
			// This runs checkDatabaseName and if it returns a
			// non-null value it is returning an error.
			$notCreated = $wikiManager->create(
				$this->getSitename(),
				$this->getLanguage(),
				$this->isPrivate(),
				$this->getCategory(),
				$this->getRequester()->getName(),
				$user->getName(),
				"[[Special:RequestWikiQueue/{$this->ID}|Requested]]"
			);

			if ( $notCreated ) {
				$this->log( $user, 'create-failure' );
				throw new RuntimeException( $notCreated );
			} else {
				$this->setStatus( 'approved' );
				$this->addComment(
					comment: 'Request approved and wiki created. ' . $comment,
					user: $user,
					log: false,
					type: 'comment'
				);
			}
		}
	}

	public function decline( string $comment, User $user ): void {
		if ( $this->getStatus() === 'approved' ) {
			return;
		}

		$this->setStatus( 'declined' );

		$this->addComment(
			comment: $comment,
			user: $user,
			log: false,
			type: 'declined'
		);

		$this->log( $user, 'requestdecline' );

		if ( !is_int( $this->options->get( 'CreateWikiAIThreshold' ) ) ) {
			$this->tryAutoCreate();
		}
	}

	public function onhold( string $comment, User $user ): void {
		if ( $this->getStatus() === 'approved' ) {
			return;
		}

		$this->setStatus( 'onhold' );

		$this->addComment(
			comment: $comment,
			user: $user,
			log: false,
			type: 'comment'
		);

		$this->log( $user, 'requestonhold' );
	}

	public function moredetails( string $comment, User $user ): void {
		if ( $this->getStatus() === 'approved' ) {
			return;
		}

		$this->setStatus( 'moredetails' );

		$this->addComment(
			comment: $comment,
			user: $user,
			log: false,
			type: 'moredetails'
		);

		$this->log( $user, 'requestmoredetails' );
	}

	public function log( User $user, string $action ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestWikiQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry( 'farmer', $action );

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::id' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	private function suppressionLog( User $user, string $action ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestWikiQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry( 'farmersuppression', $action );

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::id' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function suppress(
		User $user,
		int $level,
		bool $log
	): void {
		if ( $level === $this->visibility ) {
			// Nothing to do, the wiki request already has the requested suppression level
			return;
		}

		$this->setVisibility( $level );

		if ( $log ) {
			switch ( $level ) {
				case 0:
					$this->suppressionLog( $user, 'public' );
					break;

				case 1:
					$this->suppressionLog( $user, 'delete' );
					break;

				case 2:
					$this->suppressionLog( $user, 'suppress' );
					break;
			}
		}
	}

	public function getVisibility(): int {
		return $this->row->cw_visibility;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromId( $this->row->cw_user );
	}

	public function getStatus(): string {
		return $this->row->cw_status;
	}

	public function getSitename(): string {
		return $this->row->cw_sitename;
	}

	public function getLanguage(): string {
		return $this->row->cw_language;
	}

	public function getTimestamp(): string {
		return $this->row->cw_timestamp;
	}

	public function getUrl(): string {
		return $this->row->cw_url;
	}

	public function getCategory(): string {
		return $this->row->cw_category;
	}

	public function getDescription(): string {
		$comment = explode( "\n", $this->row->cw_comment, 2 );
		$purposeCheck = explode( ':', $comment[0], 2 );

		if ( $purposeCheck[0] === 'Purpose' ) {
			return $comment[1];
		}

		return $this->row->cw_comment;
	}

	public function getPurpose(): ?string {
		$comment = explode( "\n", $this->row->cw_comment, 2 );
		$purposeCheck = explode( ':', $comment[0], 2 );

		if ( $purposeCheck[0] === 'Purpose' ) {
			return trim( $purposeCheck[1] );
		}

		return null;
	}

	public function isPrivate(): bool {
		return (bool)$this->row->cw_private;
	}

	public function isBio(): bool {
		return (bool)$this->row->cw_bio;
	}

	public function isLocked(): bool {
		return (bool)$this->row->cw_locked;
	}

	public function startQueryBuilder(): void {
		$this->queryBuilder ??= $this->dbw->newUpdateQueryBuilder()
			->update( 'cw_requests' )
			->where( [ 'cw_id' => $this->ID ] )
			->caller( __METHOD__ );
	}

	public function checkQueryBuilder(): void {
		if ( !$this->queryBuilder ) {
			throw new RuntimeException(
				'queryBuilder not yet initialized, you must first call startQueryBuilder()'
			);
		}
	}

	public function setPrivate( bool $private ): void {
		$this->checkQueryBuilder();
		if ( $private !== $this->isPrivate() ) {
			$this->trackChange( 'private', $this->isPrivate(), $private );
			$this->queryBuilder->set( [ 'cw_private' => (int)$private ] );
		}
	}

	public function setBio( bool $bio ): void {
		$this->checkQueryBuilder();
		if ( $bio !== $this->isBio() ) {
			$this->trackChange( 'bio', $this->isBio(), $bio );
			$this->queryBuilder->set( [ 'cw_bio' => (int)$bio ] );
		}
	}

	public function setLocked( bool $locked ): void {
		$this->checkQueryBuilder();
		if ( $locked !== $this->isLocked() ) {
			$this->trackChange( 'locked', $this->isLocked(), $locked );
			$this->queryBuilder->set( [ 'cw_locked' => (int)$locked ] );
		}
	}

	public function setVisibility( int $visibility ): void {
		$this->checkQueryBuilder();
		if ( $visibility !== $this->getVisibility() ) {
			// TODO:
			/* if ( !in_array( $visibility, self::VISIBILITIES ) ) {
				throw new InvalidArgumentException( 'Can not set an unsupported visibility type.' );
			} */

			$this->trackChange( 'visibility', $this->getVisibility(), $visibility );
			$this->queryBuilder->set( [ 'cw_visibility' => $visibility ] );
		}
	}

	public function setCategory( string $category ): void {
		$this->checkQueryBuilder();
		if ( $category !== $this->getCategory() ) {
			// TODO:
			/* if ( !in_array( $category, $this->options->get( 'CreateWikiCategories' ) ) ) {
				throw new InvalidArgumentException( 'Can not set an unsupported category.' );
			} */

			$this->trackChange( 'category', $this->getCategory(), $category );
			$this->queryBuilder->set( [ 'cw_category' => $category ] );
		}
	}

	public function setSitename( string $sitename ): void {
		$this->checkQueryBuilder();
		if ( $sitename !== $this->getSitename() ) {
			$this->trackChange( 'sitename', $this->getSitename(), $sitename );
			$this->queryBuilder->set( [ 'cw_sitename' => $sitename ] );
		}
	}

	public function setLanguage( string $language ): void {
		$this->checkQueryBuilder();
		if ( $language !== $this->getLanguage() ) {
			$this->trackChange( 'language', $this->getLanguage(), $language );
			$this->queryBuilder->set( [ 'cw_language' => $language ] );
		}
	}

	public function setStatus( string $status ): void {
		$this->checkQueryBuilder();
		if ( $status !== $this->getStatus() ) {
			// TODO:
			/* if ( !in_array( $status, self::SUPPORTED_STATUSES ) ) {
				throw new InvalidArgumentException( 'Can not set an unsupported status.' );
			} */

			$this->trackChange( 'status', $this->getStatus(), $status );
			$this->queryBuilder->set( [ 'cw_status' => $status ] );
		}
	}

	public function tryExecuteQueryBuilder(): void {
		$this->checkQueryBuilder();
		if ( $this->changes ) {
			$this->queryBuilder->execute();
		}

		$this->clearQueryBuilder();
		$this->clearChanges();
	}

	public function clearQueryBuilder(): void {
		$this->queryBuilder = null;
	}

	public function clearChanges(): void {
		$this->changes = [];
	}

	public function trackChange( string $field, mixed $oldValue, mixed $newValue ): void {
		$this->changes[$field] = [
			'old' => $oldValue,
			'new' => $newValue
		];
	}

	public function getChanges(): array {
		return $this->changes;
	}

	public function tryAutoCreate(): void {
		$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
		$jobQueueGroup->push(
			new JobSpecification(
				RequestWikiAIJob::JOB_NAME,
				[
					'description' => $this->getDescription(),
					'id' => $this->ID,
				]
			)
		);
	}
}
