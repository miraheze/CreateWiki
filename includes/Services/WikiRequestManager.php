<?php

namespace Miraheze\CreateWiki\Services;

use InvalidArgumentException;
use JobSpecification;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Jobs\CreateWikiJob;
use Miraheze\CreateWiki\Jobs\RequestWikiAIJob;
use Miraheze\CreateWiki\Jobs\RequestWikiRemoteAIJob;
use RuntimeException;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Rdbms\UpdateQueryBuilder;
use function array_column;
use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function json_decode;
use function json_encode;
use function preg_replace;
use function rtrim;
use function trim;
use const ENT_QUOTES;

class WikiRequestManager {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::AIThreshold,
		ConfigNames::Categories,
		ConfigNames::DatabaseSuffix,
		ConfigNames::OpenAIConfig,
		ConfigNames::Purposes,
		ConfigNames::Subdomain,
		ConfigNames::UseJobQueue,
	];

	public const REOPEN_STATUS_CONDS = [
		'declined' => [ 'edit' ],
		'moredetails' => [ 'comment', 'edit' ],
	];

	public const VISIBILITY_PUBLIC = 0;
	public const VISIBILITY_DELETE_REQUEST = 1;
	public const VISIBILITY_SUPPRESS_REQUEST = 2;

	public const VISIBILITY_CONDS = [
		self::VISIBILITY_PUBLIC => 'public',
		self::VISIBILITY_DELETE_REQUEST => 'createwiki-deleterequest',
		self::VISIBILITY_SUPPRESS_REQUEST => 'createwiki-suppressrequest',
	];

	private IDatabase $dbw;
	private stdClass|false $row;

	private int $ID;
	private array $changes = [];

	private ?UpdateQueryBuilder $queryBuilder = null;

	public function __construct(
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiNotificationsManager $notificationsManager,
		private readonly CreateWikiValidator $validator,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly PermissionManager $permissionManager,
		private readonly UserFactory $userFactory,
		private readonly WikiManagerFactory $wikiManagerFactory,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function loadFromID( int $requestID ): void {
		$this->ID = $requestID;
		$this->dbw = $this->databaseUtils->getCentralWikiPrimaryDB();
		$this->row = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_requests' )
			->field( '*' )
			->where( [ 'cw_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	public function exists(): bool {
		return $this->row !== false;
	}

	public function createNewRequestAndLog(
		array $data,
		array $extraData,
		User $user
	): void {
		$this->dbw = $this->databaseUtils->getCentralWikiPrimaryDB();

		$subdomain = $this->validator->getValidSubdomain( $data['subdomain'] );

		$dbname = $subdomain . $this->options->get( ConfigNames::DatabaseSuffix );
		$url = $subdomain . '.' . $this->options->get( ConfigNames::Subdomain );

		$comment = $data['reason'];
		if ( $this->options->get( ConfigNames::Purposes ) && ( $data['purpose'] ?? '' ) ) {
			$comment = implode( "\n", [ 'Purpose: ' . $data['purpose'], $data['reason'] ] );
		}

		$jsonExtra = json_encode( $extraData );
		if ( $jsonExtra === false ) {
			throw new RuntimeException( 'Can not set invalid JSON data to cw_extra.' );
		}

		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'cw_requests' )
			->ignore()
			->row( [
				'cw_comment' => $comment,
				'cw_dbname' => $dbname,
				'cw_language' => $data['language'],
				'cw_private' => $data['private'] ?? 0,
				'cw_status' => 'inreview',
				'cw_sitename' => $data['sitename'],
				'cw_timestamp' => $this->dbw->timestamp(),
				'cw_url' => $url,
				'cw_user' => $user->getId(),
				'cw_category' => $data['category'] ?? '',
				'cw_visibility' => 0,
				'cw_bio' => $data['bio'] ?? 0,
				'cw_extra' => $jsonExtra,
			] )
			->caller( __METHOD__ )
			->execute();

		$this->ID = $this->dbw->insertId();

		if ( $this->options->get( ConfigNames::AIThreshold ) > 0 ) {
			$this->tryAutoCreate( $data['reason'] );
		} elseif (
			$this->options->get( ConfigNames::OpenAIConfig )['apikey'] &&
			$this->options->get( ConfigNames::OpenAIConfig )['assistantid']
		) {
			$this->evaluateWithOpenAI();
		}

		$this->logNewRequest( $data, $user );
	}

	public function isDuplicateRequest( string $sitename ): bool {
		$dbr = $this->databaseUtils->getCentralWikiReplicaDB();
		$duplicate = $dbr->newSelectQueryBuilder()
			->table( 'cw_requests' )
			->field( '*' )
			->where( [
				'cw_sitename' => $sitename,
				'cw_status' => 'inreview',
			] )
			->caller( __METHOD__ )
			->fetchRow();

		return (bool)$duplicate;
	}

	public function addComment(
		string $comment,
		UserIdentity $user,
		bool $log,
		string $type,
		array $notifyUsers
	): void {
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

		if ( !$notifyUsers ) {
			// If notifyUsers is passed an empty array,
			// notify all involved, except exclude the
			// actor so that users don't get notified
			// of their own actions.
			$notifyUsers = array_values( array_filter(
				array_diff(
					$this->getInvolvedUsers(),
					[ $this->userFactory->newFromUserIdentity( $user ) ]
				)
			) );
		}

		$this->sendNotification( $comment, $type, $notifyUsers );

		if ( $log ) {
			$this->log( $user, 'comment' );
		}
	}

	/**
	 * @return array<int, array{comment: string, timestamp: string, user: User}>
	 */
	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_comments' )
			->field( '*' )
			->where( [ 'cw_id' => $this->ID ] )
			->orderBy( 'cw_comment_timestamp', SelectQueryBuilder::SORT_ASC )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$comments = [];
		foreach ( $res as $row ) {
			if ( !$row instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

			$user = $this->userFactory->newFromId( $row->cw_comment_user );

			$comments[] = [
				'comment' => $row->cw_comment,
				'timestamp' => $row->cw_comment_timestamp,
				'user' => $user,
			];
		}

		return $comments;
	}

	private function sendNotification(
		string $comment,
		string $type,
		array $notifyUsers
	): void {
		$requestLink = SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$this->ID )->getFullURL();

		$notificationData = [
			'type' => "request-{$type}",
			'extra' => [
				'request-url' => $requestLink,
				'comment' => $comment,
			],
		];

		$this->notificationsManager->sendNotification( $notificationData, $notifyUsers );
	}

	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
	}

	public function getFilteredInvolvedUsers( UserIdentity $actor ): array {
		return array_values( array_filter(
			array_diff(
				$this->getInvolvedUsers(),
				[ $this->getRequester() ],
				[ $this->userFactory->newFromUserIdentity( $actor ) ]
			)
		) );
	}

	public function addRequestHistory(
		string $action,
		string $details,
		User $user
	): void {
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'cw_history' )
			->row( [
				'cw_id' => $this->ID,
				'cw_history_action' => $action,
				'cw_history_actor' => $user->getActorId(),
				'cw_history_details' => $details,
				'cw_history_timestamp' => $this->dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return array<int, array{action: string, details: string, timestamp: string, user: User}>
	 */
	public function getRequestHistory(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->table( 'cw_history' )
			->field( '*' )
			->where( [ 'cw_id' => $this->ID ] )
			->orderBy( 'cw_history_timestamp', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchResultSet();

		$history = [];
		foreach ( $res as $row ) {
			if ( !$row instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

			$user = $this->userFactory->newFromActorId( $row->cw_history_actor );

			$history[] = [
				'action' => $row->cw_history_action,
				'details' => $row->cw_history_details,
				'timestamp' => $row->cw_history_timestamp,
				'user' => $user,
			];
		}

		return $history;
	}

	public function getVisibleRequestsByUser(
		User $requester,
		UserIdentity $user
	): array {
		$dbr = $this->databaseUtils->getCentralWikiReplicaDB();

		$userID = $requester->getId();
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cw_id', 'cw_visibility' ] )
			->from( 'cw_requests' )
			->where( [ 'cw_user' => $userID ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$requests = [];
		foreach ( $res as $row ) {
			if ( !$row instanceof stdClass ) {
				// Skip unexpected row
				continue;
			}

			if ( !$this->isVisibilityAllowed( $row->cw_visibility, $user ) ) {
				continue;
			}

			$visibility = self::VISIBILITY_CONDS[$row->cw_visibility];

			$requests[] = [
				'id' => (int)$row->cw_id,
				'visibility' => $visibility,
			];
		}

		return $requests;
	}

	public function isVisibilityAllowed( int $visibility, UserIdentity $user ): bool {
		// T12010: 3 is a legacy suppression level,
		// treat is as a suppressed wiki request
		// hidden from everyone.
		if ( $visibility >= 3 ) {
			return false;
		}

		// Everyone can view public requests.
		if ( $visibility === self::VISIBILITY_PUBLIC ) {
			return true;
		}

		return $this->permissionManager->userHasRight(
			$user, self::VISIBILITY_CONDS[$visibility]
		);
	}

	public function getAllowedVisibilities( UserIdentity $user ): array {
		$allowedVisibilities = [];

		foreach ( self::VISIBILITY_CONDS as $visibility => $condition ) {
			if ( $this->isVisibilityAllowed( $visibility, $user ) ) {
				$allowedVisibilities[] = $visibility;
			}
		}

		return $allowedVisibilities;
	}

	public function approve( UserIdentity $user, string $comment ): void {
		if ( $this->getStatus() === 'approved' ) {
			return;
		}

		if ( $this->options->get( ConfigNames::UseJobQueue ) ) {
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
						'extra' => $this->getAllExtraData(),
					]
				)
			);

			$this->setStatus( 'approved' );

			$this->addComment(
				comment: rtrim( 'Request approved. ' . $comment ),
				user: $user,
				log: false,
				type: 'comment',
				// Use all involved users
				notifyUsers: []
			);

			$this->log( $user, 'requestapprove' );

			if ( $this->options->get( ConfigNames::AIThreshold ) === 0 ) {
				$this->tryAutoCreate( $this->getReason() );
			}
		} else {
			$wikiManager = $this->wikiManagerFactory->newInstance( $this->getDBname() );
			// This runs validateDatabaseName and if it returns a
			// non-null value it is returning an error.
			$notCreated = $wikiManager->create(
				sitename: $this->getSitename(),
				language: $this->getLanguage(),
				private: $this->isPrivate(),
				category: $this->getCategory(),
				requester: $this->getRequester()->getName(),
				actor: $user->getName(),
				extra: $this->getAllExtraData(),
				reason: "[[Special:RequestWikiQueue/{$this->ID}|Requested]]"
			);

			if ( $notCreated ) {
				$this->log( $user, 'create-failure' );
				throw new RuntimeException( $notCreated );
			} else {
				$this->setStatus( 'approved' );
				$this->addComment(
					comment: rtrim( 'Request approved and wiki created. ' . $comment ),
					user: $user,
					log: false,
					type: 'comment',
					// Use all involved users
					notifyUsers: []
				);
			}
		}
	}

	public function decline( string $comment, UserIdentity $user ): void {
		if ( $this->getStatus() === 'approved' || $this->getStatus() === 'declined' ) {
			return;
		}

		$this->setStatus( 'declined' );

		$this->addComment(
			comment: $comment,
			user: $user,
			log: false,
			type: 'declined',
			notifyUsers: [ $this->getRequester() ]
		);

		// We exclude the actor and the requester.
		// The actor shouldn't be notified of own actions.
		// The requester has already been notified via addComment.
		$notifyUsers = $this->getFilteredInvolvedUsers( actor: $user );
		if ( $notifyUsers ) {
			$this->sendNotification(
				comment: $comment,
				type: 'comment',
				notifyUsers: $notifyUsers
			);
		}

		$this->log( $user, 'requestdecline' );

		if ( $this->options->get( ConfigNames::AIThreshold ) === 0 ) {
			$this->tryAutoCreate( $this->getReason() );
		}
	}

	public function onhold( string $comment, UserIdentity $user ): void {
		if ( $this->getStatus() === 'approved' || $this->getStatus() === 'onhold' ) {
			return;
		}

		$this->setStatus( 'onhold' );

		$this->addComment(
			comment: $comment,
			user: $user,
			log: false,
			type: 'comment',
			notifyUsers: [ $this->getRequester() ]
		);

		// We exclude the actor and the requester.
		// The actor shouldn't be notified of own actions.
		// The requester has already been notified via addComment.
		$notifyUsers = $this->getFilteredInvolvedUsers( actor: $user );
		if ( $notifyUsers ) {
			$this->sendNotification(
				comment: $comment,
				type: 'comment',
				notifyUsers: $notifyUsers
			);
		}

		$this->log( $user, 'requestonhold' );
	}

	public function moredetails( string $comment, UserIdentity $user ): void {
		if ( $this->getStatus() === 'approved' || $this->getStatus() === 'moredetails' ) {
			return;
		}

		$this->setStatus( 'moredetails' );

		$this->addComment(
			comment: $comment,
			user: $user,
			log: false,
			type: 'moredetails',
			notifyUsers: [ $this->getRequester() ]
		);

		// We exclude the actor and the requester.
		// The actor shouldn't be notified of own actions.
		// The requester has already been notified via addComment.
		$notifyUsers = $this->getFilteredInvolvedUsers( actor: $user );
		if ( $notifyUsers ) {
			$this->sendNotification(
				comment: $comment,
				type: 'comment',
				notifyUsers: $notifyUsers
			);
		}

		$this->log( $user, 'requestmoredetails' );
	}

	public function log( UserIdentity $user, string $action ): void {
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

	private function logNewRequest(
		array $data,
		UserIdentity $user
	): void {
		$requestWikiLink = SpecialPage::getTitleValueFor( 'RequestWiki' );
		$logEntry = new ManualLogEntry( 'farmer', 'requestwiki' );

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestWikiLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::sitename' => $data['sitename'],
				'5::language' => $data['language'],
				'6::private' => (int)( $data['private'] ?? 0 ),
				'7::id' => "#{$this->ID}",
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	private function logSuppression(
		UserIdentity $user,
		string $action
	): void {
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
		UserIdentity $user,
		int $level,
		bool $log
	): void {
		if ( $level === $this->getVisibility() ) {
			// Nothing to do, the wiki request already has the requested suppression level
			return;
		}

		$this->setVisibility( $level );

		if ( $log ) {
			switch ( $level ) {
				case self::VISIBILITY_PUBLIC:
					$this->logSuppression( $user, 'public' );
					break;

				case self::VISIBILITY_DELETE_REQUEST:
					$this->logSuppression( $user, 'delete' );
					break;

				case self::VISIBILITY_SUPPRESS_REQUEST:
					$this->logSuppression( $user, 'suppress' );
					break;
			}
		}
	}

	private function getRowObject(): stdClass {
		if ( $this->row === false ) {
			// Skip unexpected row
			throw new RuntimeException( '$this->row is false' );
		}

		return $this->row;
	}

	public function canCommentReopen(): bool {
		return in_array( 'comment', self::REOPEN_STATUS_CONDS[$this->getStatus()] ?? [], true );
	}

	public function canEditReopen(): bool {
		return in_array( 'edit', self::REOPEN_STATUS_CONDS[$this->getStatus()] ?? [], true );
	}

	public function getID(): int {
		return $this->ID;
	}

	public function getDBname(): string {
		return $this->getRowObject()->cw_dbname;
	}

	public function getVisibility(): int {
		return $this->getRowObject()->cw_visibility;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromId( $this->getRowObject()->cw_user );
	}

	public function getStatus(): string {
		return $this->getRowObject()->cw_status;
	}

	public function getSitename(): string {
		return $this->getRowObject()->cw_sitename;
	}

	public function getLanguage(): string {
		return $this->getRowObject()->cw_language;
	}

	public function getTimestamp(): string {
		return $this->getRowObject()->cw_timestamp;
	}

	public function getUrl(): string {
		return $this->getRowObject()->cw_url;
	}

	public function getCategory(): string {
		return $this->getRowObject()->cw_category;
	}

	public function getReason(): string {
		$comment = explode( "\n", $this->getRowObject()->cw_comment, 2 );
		$purposeCheck = explode( ':', $comment[0], 2 );

		if ( $purposeCheck[0] === 'Purpose' ) {
			return $comment[1];
		}

		return $this->getRowObject()->cw_comment;
	}

	public function getPurpose(): ?string {
		$comment = explode( "\n", $this->getRowObject()->cw_comment, 2 );
		$purposeCheck = explode( ':', $comment[0], 2 );

		if ( $purposeCheck[0] === 'Purpose' ) {
			return trim( $purposeCheck[1] );
		}

		return null;
	}

	public function isPrivate(): bool {
		return (bool)$this->getRowObject()->cw_private;
	}

	public function isBio(): bool {
		return (bool)$this->getRowObject()->cw_bio;
	}

	public function isLocked(): bool {
		return (bool)$this->getRowObject()->cw_locked;
	}

	public function getAllExtraData(): array {
		return (array)json_decode( $this->getRowObject()->cw_extra ?: '[]', true );
	}

	public function getExtraFieldData( string $field ): mixed {
		$extra = $this->getAllExtraData();
		return $extra[$field] ?? null;
	}

	public function startQueryBuilder(): void {
		$this->clearChanges();
		$this->queryBuilder ??= $this->dbw->newUpdateQueryBuilder()
			->update( 'cw_requests' )
			->where( [ 'cw_id' => $this->ID ] )
			->caller( __METHOD__ );
	}

	private function getQueryBuilder(): UpdateQueryBuilder {
		if ( $this->queryBuilder === null ) {
			throw new RuntimeException(
				'Attempted to access queryBuilder before startQueryBuilder() was called.'
			);
		}

		return $this->queryBuilder;
	}

	public function setPrivate( bool $private ): void {
		if ( $private === $this->isPrivate() ) {
			return;
		}

		$this->trackChange( 'private', $this->isPrivate(), $private );
		$this->getQueryBuilder()->set( [ 'cw_private' => (int)$private ] );
	}

	public function setBio( bool $bio ): void {
		if ( $bio === $this->isBio() ) {
			return;
		}

		$this->trackChange( 'bio', $this->isBio(), $bio );
		$this->getQueryBuilder()->set( [ 'cw_bio' => (int)$bio ] );
	}

	public function setLocked( bool $locked ): void {
		if ( $locked === $this->isLocked() ) {
			return;
		}

		$this->trackChange( 'locked', $this->isLocked(), $locked );
		$this->getQueryBuilder()->set( [ 'cw_locked' => (int)$locked ] );
	}

	public function setVisibility( int $visibility ): void {
		if ( $visibility === $this->getVisibility() ) {
			return;
		}

		if ( !array_key_exists( $visibility, self::VISIBILITY_CONDS ) ) {
			throw new InvalidArgumentException( 'Cannot set an unsupported visibility type.' );
		}

		$this->trackChange( 'visibility', $this->getVisibility(), $visibility );
		$this->getQueryBuilder()->set( [ 'cw_visibility' => $visibility ] );
	}

	public function setCategory( string $category ): void {
		if ( $category === $this->getCategory() ) {
			return;
		}

		if ( !in_array( $category, $this->options->get( ConfigNames::Categories ), true ) ) {
			throw new InvalidArgumentException( 'Cannot set an unsupported category.' );
		}

		$this->trackChange( 'category', $this->getCategory(), $category );
		$this->getQueryBuilder()->set( [ 'cw_category' => $category ] );
	}

	public function setSitename( string $sitename ): void {
		if ( $sitename === $this->getSitename() ) {
			return;
		}

		$this->trackChange( 'sitename', $this->getSitename(), $sitename );
		$this->getQueryBuilder()->set( [ 'cw_sitename' => $sitename ] );
	}

	public function setLanguage( string $language ): void {
		if ( $language === $this->getLanguage() ) {
			return;
		}

		$this->trackChange( 'language', $this->getLanguage(), $language );
		$this->getQueryBuilder()->set( [ 'cw_language' => $language ] );
	}

	public function setReasonAndPurpose( string $reason, string $purpose ): void {
		$hasReasonChanged = $reason !== $this->getReason();
		$hasPurposeChanged = $purpose && $purpose !== $this->getPurpose();

		if ( !$hasReasonChanged && !$hasPurposeChanged ) {
			return;
		}

		if ( $hasReasonChanged ) {
			$this->trackChange( 'reason', $this->getReason(), $reason );
		}

		if ( $hasPurposeChanged ) {
			$this->trackChange( 'purpose', $this->getPurpose(), $purpose );
		}

		$newComment = $purpose ? "Purpose: $purpose\n" : '';
		$newComment .= $reason;

		$this->getQueryBuilder()->set( [ 'cw_comment' => $newComment ] );
	}

	public function setUrl( string $url ): void {
		if ( $url === $this->getUrl() ) {
			return;
		}

		$subdomain = $this->validator->getValidSubdomain( $url );
		$dbname = $subdomain . $this->options->get( ConfigNames::DatabaseSuffix );
		$url = $subdomain . '.' . $this->options->get( ConfigNames::Subdomain );

		$this->trackChange( 'url', $this->getUrl(), $url );
		$this->getQueryBuilder()->set( [
			'cw_dbname' => $dbname,
			'cw_url' => $url,
		] );
	}

	public function setExtraFieldsData( array $fieldsData ): void {
		$extra = $this->getAllExtraData();
		$hasChanges = false;

		foreach ( $fieldsData as $field => $value ) {
			if ( $value === $this->getExtraFieldData( $field ) ) {
				continue;
			}

			$this->trackChange( $field, $this->getExtraFieldData( $field ), $value );
			$extra[$field] = $value;
			$hasChanges = true;
		}

		if ( !$hasChanges ) {
			return;
		}

		$newExtra = json_encode( $extra );
		if ( $newExtra === false ) {
			throw new RuntimeException( 'Cannot set invalid JSON data to cw_extra.' );
		}

		$this->getQueryBuilder()->set( [ 'cw_extra' => $newExtra ] );
	}

	public function setStatus( string $status ): void {
		if ( $status === $this->getStatus() ) {
			return;
		}

		$this->trackChange( 'status', $this->getStatus(), $status );
		$this->getQueryBuilder()->set( [ 'cw_status' => $status ] );
	}

	public function tryExecuteQueryBuilder(): void {
		if ( $this->changes ) {
			$this->getQueryBuilder()->execute();
		}

		$this->clearQueryBuilder();
	}

	public function clearQueryBuilder(): void {
		$this->queryBuilder = null;
	}

	public function clearChanges(): void {
		$this->changes = [];
	}

	public function trackChange( string $field, mixed $oldValue, mixed $newValue ): void {
		if ( $this->queryBuilder === null ) {
			// If query builder is not set we don't want changes
			return;
		}

		// Make sure boolean, array, and null values save to changes as a string
		// We need this so that getChangeMessage properly displays them.

		if ( is_bool( $oldValue ) || is_array( $oldValue ) || $oldValue === null ) {
			$oldValue = json_encode( $oldValue );
		}

		if ( is_bool( $newValue ) || is_array( $newValue ) || $newValue === null ) {
			$newValue = json_encode( $newValue );
		}

		$this->changes[$field] = [
			'old' => $this->escape( $oldValue ),
			'new' => $this->escape( $newValue ),
		];
	}

	public function hasChanges(): bool {
		return (bool)$this->changes;
	}

	public function getChangeMessage(): string {
		$messages = [];

		$prefix = count( $this->changes ) > 1 ? '* ' : '';
		foreach ( $this->changes as $field => $change ) {
			$oldValue = $this->formatValue( $change['old'] );
			$newValue = $this->formatValue( $change['new'] );

			$messages[] = "{$prefix}Field ''{$field}'' changed:\n" .
				"*{$prefix}'''Old value''': {$oldValue}\n" .
				"*{$prefix}'''New value''': {$newValue}";
		}

		return implode( "\n", $messages );
	}

	private function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}

	private function formatValue( string $value ): string {
		$value = rtrim( $value );
		$value = preg_replace( "/\n+/", "\n", $value );
		$lines = explode( "\n", $value );

		$prefix = count( $this->changes ) > 1 ? '*' : '';
		foreach ( $lines as $index => $line ) {
			if ( $index > 0 ) {
				$lines[$index] = $prefix . ': ' . $line;
			}
		}

		return implode( "\n", $lines );
	}

	private function tryAutoCreate( string $reason ): void {
		$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
		$jobQueueGroup->push(
			new JobSpecification(
				RequestWikiAIJob::JOB_NAME,
				[
					'id' => $this->ID,
					'reason' => $reason,
				]
			)
		);
	}

	private function evaluateWithOpenAI(): void {
		$jobQueueGroup = $this->jobQueueGroupFactory->makeJobQueueGroup();
		$jobQueueGroup->push(
			new JobSpecification(
				RequestWikiRemoteAIJob::JOB_NAME,
				[ 'id' => $this->ID ]
			)
		);
	}
}
