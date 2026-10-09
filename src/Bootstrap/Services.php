<?php
declare(strict_types=1);
namespace ForumIntelligence\Bootstrap;
use ForumIntelligence\Application\Operations;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
final class Services {
	public readonly Store $store;
	public readonly Access $access;
	public readonly Queue $queue;
	public readonly \ForumIntelligence\Domain\Solutions\Solutions $solutions;
	public readonly Operations $operations;
	public readonly \ForumIntelligence\Rest\Routes $routes;
	private array $services = array();
	public function __construct() {
		$this->store      = new Store();
		$this->access     = new Access();
		$this->queue      = new Queue( $this->store );
		$this->solutions  = new \ForumIntelligence\Domain\Solutions\Solutions( $this->store, $this->access );
		$this->operations = new Operations();
		$this->routes     = new \ForumIntelligence\Rest\Routes( $this->operations, $this->solutions );
	}
	public function enabled( string $module ): bool {
		return (bool) ( get_option( 'fi_settings', array() )['modules'][ $module ] ?? in_array( $module, array( 'engagement', 'moderation', 'knowledge', 'relationships', 'notifications', 'reputation', 'polls', 'composer' ), true ) );
	}
	public function get( string $name ): mixed {
		return $this->services[ $name ] ?? null; }
	public function register(): void {
		$s         = $this->store;
		$a         = $this->access;
		$q         = $this->queue;
		$solutions = $this->solutions;
		$ops       = $this->operations;
		add_action( 'fi_solution_changed', fn( int $topic )=>$solutions->forget( $topic ) );
		add_action( 'fi_signal_changed', fn( int $objectId )=>$solutions->forget( $a->topic( $objectId ) ) );
		$provider = new \ForumIntelligence\Infrastructure\AI\NullProvider();
		if ( ( get_option( 'fi_settings', array() )['ai_enabled'] ?? null ) === true ) {
			$provider = new \ForumIntelligence\Infrastructure\AI\OpenAICompatible( $s ); }
		$text      = apply_filters( 'fi_text_provider', $provider );
		$embedding = apply_filters( 'fi_embedding_provider', $provider );
		if ( ! $text instanceof \ForumIntelligence\Contracts\TextProvider ) {
			$text = $provider;
			$s->metric( 'provider_contract_failure' );
		}
		if ( ! $embedding instanceof \ForumIntelligence\Contracts\EmbeddingProvider ) {
			$embedding = $provider;
			$s->metric( 'provider_contract_failure' );
		}
		$settings    = get_option( 'fi_settings', array() );
		$summaryText = ( $settings['ai_enabled'] ?? null ) === true && ! empty( $settings['summary_model'] ) ? new \ForumIntelligence\Infrastructure\AI\OpenAICompatible( $s, 'summary' ) : $text;
		$summaryText = apply_filters( 'fi_summary_provider', $summaryText );
		if ( ! $summaryText instanceof \ForumIntelligence\Contracts\TextProvider ) {
			$summaryText = new \ForumIntelligence\Infrastructure\AI\NullProvider();
			$s->metric( 'provider_contract_failure' );
		}
		$moderationText     = ( $settings['ai_enabled'] ?? null ) === true && ! empty( $settings['moderation_model'] ) ? new \ForumIntelligence\Infrastructure\AI\OpenAICompatible( $s, 'moderation' ) : $text;
		$moderationProvider = apply_filters( 'fi_moderation_provider', new \ForumIntelligence\Infrastructure\AI\TextModerationProvider( $moderationText ) );
		if ( ! $moderationProvider instanceof \ForumIntelligence\Contracts\ModerationProvider ) {
			$moderationProvider = new \ForumIntelligence\Infrastructure\AI\NullProvider();
			$s->metric( 'provider_contract_failure' );
		}
		$rerankingText     = ( $settings['ai_enabled'] ?? null ) === true && ! empty( $settings['reranking_model'] ) ? new \ForumIntelligence\Infrastructure\AI\OpenAICompatible( $s, 'reranking' ) : $text;
		$rerankingProvider = apply_filters( 'fi_reranking_provider', new \ForumIntelligence\Infrastructure\AI\TextRerankingProvider( $rerankingText ) );
		if ( ! $rerankingProvider instanceof \ForumIntelligence\Contracts\RerankingProvider ) {
			$rerankingProvider = new \ForumIntelligence\Infrastructure\AI\NullProvider();
			$s->metric( 'provider_contract_failure' );
		}
		$localVectors = new \ForumIntelligence\Infrastructure\Search\LocalVectorStore( $s );
		$localVectors->register( $q );
		$vectors = apply_filters( 'fi_vector_store', $localVectors );
		if ( ! $vectors instanceof \ForumIntelligence\Contracts\VectorStore ) {
			$vectors = new \ForumIntelligence\Infrastructure\Search\LocalVectorStore( $s );
			$s->metric( 'vector_contract_failure' ); }
		$search = apply_filters( 'fi_search_engine', new \ForumIntelligence\Infrastructure\Search\HybridSearch( new \ForumIntelligence\Infrastructure\Search\LexicalSearch( $s, $a, $solutions ), $embedding, $vectors, $a, $solutions, $s ) );
		if ( ! $search instanceof \ForumIntelligence\Contracts\SearchEngine ) {
			$search = new \ForumIntelligence\Infrastructure\Search\LexicalSearch( $s, $a, $solutions );
			$s->metric( 'search_contract_failure' ); }
		$search                   = new \ForumIntelligence\Infrastructure\Search\RerankedSearch( $search, $rerankingProvider, $s, $a );
		$this->services['search'] = $search;
		$duplicates               = new \ForumIntelligence\Domain\Search\Duplicates( new \ForumIntelligence\Infrastructure\Search\LexicalSearch( $s, $a, $solutions ), $s, $a );
		$this->routes->query(
			'duplicates',
			fn( array $d, int $u ): array=>$duplicates->suggest( (string) ( $d['title'] ?? '' ), (string) ( $d['body'] ?? '' ), (int) ( $d['forum'] ?? 0 ), (string) ( $d['tags'] ?? '' ), (array) ( $d['fields'] ?? array() ), $u ),
			true,
			array(
				'title'  => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'body'   => array(
					'type'      => 'string',
					'maxLength' => 12000,
				),
				'forum'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'tags'   => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'fields' => array( 'type' => 'object' ),
			),
			'POST'
		);
		$ops->add( 'duplicate_feedback', fn( array $d, int $u ): array => $duplicates->feedback( (string) ( $d['offer'] ?? '' ), (string) ( $d['outcome'] ?? '' ), $u, (int) ( $d['object'] ?? 0 ) ) );
		$indexer = new \ForumIntelligence\Infrastructure\Search\Indexer( $s, $q, $a, $embedding, $vectors );
		$indexer->register();
		$this->services['indexer']         = $indexer;
		$answers                           = new \ForumIntelligence\Domain\AI\Answers( $search, ( $settings['ai_answers'] ?? true ) === true ? $text : new \ForumIntelligence\Infrastructure\AI\NullProvider(), $a, $s );
		$this->services['answers']         = $answers;
		$feedback                          = new \ForumIntelligence\Domain\AI\Feedback( $s, $a );
		$this->services['answer_feedback'] = $feedback;
		$ops->add( 'ai_feedback_review', fn( array $d, int $u ): array => $feedback->review( (int) ( $d['id'] ?? 0 ), (string) ( $d['state'] ?? '' ), $u, (int) ( $d['version'] ?? -1 ) ) );
		$this->routes->query(
			'solution_history',
			fn( array $d, int $u ): array=>$solutions->history( (int) ( $d['object'] ?? 0 ), $u, (int) ( $d['before'] ?? PHP_INT_MAX ) ),
			true,
			array(
				'before' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			)
		);
		$ops->add( 'solution', fn( array $d, int $u ): array=>$solutions->change( (int) ( $d['topic'] ?? 0 ), (int) ( $d['reply'] ?? 0 ), (string) ( $d['command'] ?? '' ), $u, isset( $d['version'] ) ? (int) $d['version'] : null ) );
		$ops->add( 'ask', fn( array $d, int $u ): array=>$answers->ask( (string) ( $d['question'] ?? '' ), $u ) );
		$ops->add( 'ai_feedback', fn( array $d, int $u ): array=>$answers->feedback( (int) ( $d['object'] ?? 0 ), (string) ( $d['value'] ?? '' ), $u, (string) ( $d['token'] ?? '' ) ) );
		$this->routes->query(
			'search',
			fn( array $d, int $u ): array=>$search->search( (string) ( $d['q'] ?? '' ), $d, $u ),
			true,
			array(
				'q'          => array(
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 500,
				),
				'forum'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 25,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 30,
				),
				'solved'     => array(
					'type' => 'integer',
					'enum' => array( 0, 1 ),
				),
				'sort'       => array(
					'type' => 'string',
					'enum' => array( 'recent', 'relevance' ),
				),
				'type'       => array(
					'type' => 'string',
					'enum' => array( 'topic', 'reply', 'forum' ),
				),
				'tag'        => array( 'type' => 'string' ),
				'author'     => array( 'type' => 'integer' ),
				'after'      => array( 'type' => 'string' ),
				'before'     => array( 'type' => 'string' ),
				'min_votes'  => array(
					'type'    => 'integer',
					'minimum' => -1000,
					'maximum' => 100000,
				),
				'unanswered' => array( 'type' => 'boolean' ),
				'explain'    => array( 'type' => 'boolean' ),
				'fields'     => array( 'type' => 'object' ),
			)
		);
		if ( $this->enabled( 'engagement' ) ) {
			$service                      = new \ForumIntelligence\Domain\Engagement\Engagement( $s, $a, $solutions );
			$this->services['engagement'] = $service;
			$ops->add( 'signal', fn( array $d, int $u ): array=>$service->cast( (int) ( $d['object'] ?? 0 ), $u, (string) ( $d['kind'] ?? '' ), (int) ( $d['value'] ?? 0 ) ) );
			$this->routes->query( 'signals', fn( array $d, int $u ): array=>$service->counts( (int) ( $d['object'] ?? 0 ), $u ), true );
		}
		if ( $this->enabled( 'moderation' ) ) {
			$service                 = new \ForumIntelligence\Domain\Moderation\Cases( $s, $a );
			$this->services['cases'] = $service;
			if ( ( $settings['ai_enabled'] ?? null ) === true && ( $settings['ai_moderation'] ?? true ) === true ) {
				$advisory = new \ForumIntelligence\Domain\Moderation\Advisory( $s, $q, $a, $moderationProvider );
				$advisory->register();
				$this->services['moderation_advisory'] = $advisory;
				$ops->add( 'moderation_advisory', fn( array $d, int $u ): array=>$advisory->request( (int) ( $d['case'] ?? 0 ), $u ) );
				$this->routes->query( 'moderation_advisory', fn( array $d, int $u ): ?array=>$advisory->view( (int) ( $d['case'] ?? 0 ), $u ) );}
			$ops->add( 'report', fn( array $d, int $u ): array=>$service->report( (int) ( $d['object'] ?? 0 ), $u, (string) ( $d['reason'] ?? '' ), (string) ( $d['notes'] ?? '' ) ) );
			$ops->add( 'moderate', fn( array $d, int $u ): array=>$service->resolve( (int) ( $d['case'] ?? 0 ), $u, (string) ( $d['command'] ?? '' ), (string) ( $d['notes'] ?? '' ), (int) ( $d['assignee'] ?? 0 ) ) );
			$this->routes->query( 'cases', fn( array $d, int $u ): array=>$service->listing( $u, (int) ( $d['page'] ?? 1 ), (string) ( $d['status'] ?? 'new' ) ) );
		}
		if ( $this->enabled( 'relationships' ) ) {
			$service                         = new \ForumIntelligence\Domain\Relationships\Relationships( $s, $a );
			$this->services['relationships'] = $service;
			$ops->add( 'relationship', fn( array $d, int $u ): array=>$service->change( (int) ( $d['source'] ?? 0 ), (int) ( $d['target'] ?? 0 ), (string) ( $d['type'] ?? '' ), (string) ( $d['status'] ?? '' ), $u ) );
			$this->routes->query( 'relationships', fn( array $d, int $u ): array=>$service->listing( (int) ( $d['object'] ?? 0 ), $u ), true );
		}
		if ( $this->enabled( 'knowledge' ) ) {
			$service                     = new \ForumIntelligence\Domain\Knowledge\Knowledge( $s, $q, $a, $summaryText );
			$this->services['knowledge'] = $service;
			$service->register();
			$ops->add( 'knowledge', fn( array $d, int $u ): array=>$service->command( (int) ( $d['topic'] ?? 0 ), (string) ( $d['command'] ?? '' ), (string) ( $d['text'] ?? '' ), $u ) );
			$this->routes->query( 'summary', fn( array $d, int $u ): ?array=>$service->summary( (int) ( $d['object'] ?? 0 ), $u ), true );
		}
		if ( $this->enabled( 'badges' ) ) {
			$badges                   = new \ForumIntelligence\Domain\Reputation\Badges( $s, $a );
			$this->services['badges'] = $badges;
			$ops->add( 'badge_preferences', fn( array $d, int $u ): array=>$badges->preference( $u, (bool) (int) ( $d['enabled'] ?? 0 ) ) );
			$badges->register( $q );
			$ops->add( 'badge_review', fn( array $d, int $u ): array=>$badges->review( (int) ( $d['member'] ?? 0 ), (string) ( $d['badge'] ?? '' ), (bool) (int) ( $d['active'] ?? 0 ), (string) ( $d['reason'] ?? '' ), $u ) );
			$this->routes->query(
				'badges',
				fn( array $d, int $u ): array=>$badges->listing( (int) ( $d['member'] ?? $u ), $u, (bool) ( $d['history'] ?? false ) ),
				true,
				array(
					'member'  => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'history' => array( 'type' => 'boolean' ),
				)
			);
		}
		if ( $this->enabled( 'reputation' ) ) {
			$service                      = new \ForumIntelligence\Domain\Reputation\Reputation( $s, $a );
			$this->services['reputation'] = $service;
			$service->register( $q );
			$ops->add( 'reputation_rebuild', fn( array $d, int $u ): array=>$service->rebuild( $u ) );
			add_action( 'fi_solution_changed', array( $service, 'solutionsChanged' ), 10, 4 );
			$ops->add( 'reputation', fn( array $d, int $u ): array=>$service->adjust( (int) ( $d['member'] ?? 0 ), (int) ( $d['points'] ?? 0 ), (string) ( $d['reason'] ?? '' ), $u ) );
			$this->routes->query( 'reputation', fn( array $d, int $u ): array=>$service->profile( (int) ( $d['member'] ?? $u ), $u ), true );
		}
		if ( $this->enabled( 'notifications' ) ) {
			$following                        = new \ForumIntelligence\Domain\Community\Following( $s, $a );
			$this->services['following']      = $following;
			$saved                            = new \ForumIntelligence\Domain\Community\SavedSearches( $s, $a, new \ForumIntelligence\Infrastructure\Search\LexicalSearch( $s, $a, $solutions ), $following );
			$this->services['saved_searches'] = $saved;
			$ops->add( 'saved_search', fn( array $d, int $u ): array=>$saved->save( $u, (string) ( $d['query'] ?? '' ), (array) ( $d['filters'] ?? array() ) ) );
			$ops->add( 'saved_search_alerts', fn( array $d, int $u ): array=>$saved->alerts( $u, (int) ( $d['id'] ?? 0 ), (bool) (int) ( $d['enabled'] ?? 0 ) ) );
			$ops->add( 'saved_search_remove', fn( array $d, int $u ): array=>$saved->remove( $u, (int) ( $d['id'] ?? 0 ) ) );
			$this->routes->query( 'saved_searches', fn( array $d, int $u ): array=>$saved->listing( $u ) );
			$this->routes->query( 'saved_search_feed', fn( array $d, int $u ): array=>$saved->feed( $u ) );
			$service                         = new \ForumIntelligence\Domain\Notifications\Notifications( $s, $q, $a, $following );
			$this->services['notifications'] = $service;
			if ( $this->get( 'knowledge' ) ) {
				( new \ForumIntelligence\Domain\Knowledge\ReviewNotifications( $s, $q, $service ) )->register(); }
			$service->register();
			$mentions = new \ForumIntelligence\Domain\Notifications\Mentions( $s, $a, $following, $service );
			$mentions->register();
			$this->services['mentions'] = $mentions;
			$this->routes->query(
				'mentions',
				fn( array $d, int $u ): array=>$mentions->lookup( (string) ( $d['q'] ?? '' ), (int) ( $d['object'] ?? 0 ), $u ),
				false,
				array(
					'q'      => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 2,
						'maxLength' => 30,
					),
					'object' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				)
			);
			$saved->register( $q, $service );
			$ops->add( 'follow', fn( array $d, int $u ): array=>$following->set( $u, (int) ( $d['target'] ?? 0 ), (string) ( $d['type'] ?? '' ), (string) ( $d['mode'] ?? '' ), (bool) (int) ( $d['enabled'] ?? 0 ) ) );
			$ops->add( 'notification', fn( array $d, int $u ): array=>$service->mark( (int) ( $d['id'] ?? 0 ), $u, (string) ( $d['command'] ?? '' ) ) );
			$ops->add( 'preferences', fn( array $d, int $u ): array=>$service->savePreferences( $u, (string) ( $d['email_mode'] ?? 'off' ), (array) ( $d['muted_categories'] ?? array() ) ) );
			$this->routes->query(
				'notifications',
				fn( array $d, int $u ): array=>$service->inbox( $u, (int) ( $d['page'] ?? 1 ), (bool) ( $d['unread'] ?? false ), (string) ( $d['kind'] ?? '' ), (string) ( $d['state'] ?? 'all' ) ),
				false,
				array(
					'unread' => array( 'type' => 'boolean' ),
					'kind'   => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), array_keys( \ForumIntelligence\Domain\Notifications\Notifications::categories() ) ),
					),
					'state'  => array(
						'type' => 'string',
						'enum' => array( 'all', 'read', 'unread' ),
					),
				)
			);
			$this->routes->query( 'notification_summary', fn( array $d, int $u ): array=>$service->summary( $u ) );
			$this->routes->query( 'preferences', fn( array $d, int $u ): array=>$service->preferences( $u ) );
			$this->routes->query( 'feed', fn( array $d, int $u ): array=>$following->feed( $u, (int) ( $d['page'] ?? 1 ), (string) ( $d['mode'] ?? 'relevant' ) ) );
		}
		if ( $this->enabled( 'polls' ) ) {
			$service                 = new \ForumIntelligence\Domain\Polls\Polls( $s, $a, $q );
			$this->services['polls'] = $service;
			$service->register();
			$ops->add( 'poll_create', fn( array $d, int $u ): array=>$service->create( (int) ( $d['topic'] ?? 0 ), $u, is_array( $d['choices'] ?? null ) ? $d['choices'] : explode( "\n", (string) ( $d['choices'] ?? '' ) ), (int) ( $d['maximum'] ?? 1 ), (string) ( $d['ends'] ?? '' ), (string) ( $d['visibility'] ?? 'voters' ), (bool) (int) ( $d['change'] ?? 0 ), (string) ( $d['starts'] ?? '' ) ) );
			$ops->add( 'poll_vote', fn( array $d, int $u ): array=>$service->vote( (int) ( $d['topic'] ?? 0 ), $u, (array) ( $d['choices'] ?? array() ) ) );
			$ops->add( 'poll_close', fn( array $d, int $u ): array=>$service->close( (int) ( $d['topic'] ?? 0 ), $u ) );
			$this->routes->query( 'poll', fn( array $d, int $u ): ?array=>$service->view( (int) ( $d['object'] ?? 0 ), $u ), true );
		}
		if ( ! $this->get( 'following' ) ) {
			$following                   = new \ForumIntelligence\Domain\Community\Following( $s, $a );
			$this->services['following'] = $following;
			$ops->add( 'follow', fn( array $d, int $u ): array=>$following->set( $u, (int) ( $d['target'] ?? 0 ), (string) ( $d['type'] ?? '' ), (string) ( $d['mode'] ?? '' ), (bool) (int) ( $d['enabled'] ?? 0 ) ) );
		}
		$discovery                   = new \ForumIntelligence\Domain\Community\Discovery( $s, $a, $this->get( 'following' ), $solutions, $this->get( 'reputation' ) );
		$this->services['discovery'] = $discovery;
		$this->routes->query(
			'discovery',
			fn( array $d, int $u ): array=>$discovery->topics( $u, (string) ( $d['mode'] ?? 'trending' ), (int) ( $d['page'] ?? 1 ) ),
			true,
			array(
				'mode' => array(
					'type' => 'string',
					'enum' => array( 'trending', 'help', 'solved', 'recent' ),
				),
			)
		);
		$this->routes->query(
			'experts',
			fn( array $d, int $u ): array=>$discovery->experts( $u, (string) ( $d['tag'] ?? '' ) ),
			true,
			array(
				'tag' => array(
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 100,
				),
			)
		);
		$this->optional();
		( new \ForumIntelligence\Privacy\Privacy( $s ) )->register();
		( new \ForumIntelligence\Health\Health( $s ) )->register();
		( new \ForumIntelligence\Cli\Commands( $this ) )->register();
		$this->routes->register();
		add_action(
			'fi_queue_tick',
			static function () use ( $q ): void {
				$q->run();
			}
		);
		add_action(
			'fi_queue_soon',
			static function () use ( $q ): void {
				$q->run();
			}
		);
		$ops->add(
			'reindex',
			function ( array $d, int $u ) use ( $indexer, $a ): array {
				$a->moderate( $u );
				if ( ! user_can( $u, 'manage_options' ) ) {
					throw new \ForumIntelligence\Domain\Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 );
				} $indexer->rebuild();
				return array( 'queued' => true );
			}
		);
		$ops->add(
			'retry_job',
			function ( array $d, int $u ) use ( $q ): array {
				$q->retry( (int) ( $d['id'] ?? 0 ), $u );
				return array( 'queued' => true );
			}
		);
	}
	private function optional(): void {
		$s   = $this->store;
		$a   = $this->access;
		$ops = $this->operations;
		if ( $this->enabled( 'composer' ) ) {
			$fields = new \ForumIntelligence\Domain\Composer\TopicFields( $s, $a );
			$fields->register();
			$this->services['fields'] = $fields;
			$drafts                   = new \ForumIntelligence\Domain\Composer\Drafts( $s, $a );
			$this->services['drafts'] = $drafts;
			$ops->add( 'field_config', fn( array $d, int $u ): array=>$fields->configure( (string) ( $d['json'] ?? '' ), $u ) );
			$ops->add( 'fields', fn( array $d, int $u ): array=>$fields->save( (int) ( $d['topic'] ?? 0 ), (array) ( $d['fields'] ?? array() ), $u ) );
			$ops->add( 'draft', fn( array $d, int $u ): array=>$drafts->save( (int) ( $d['object'] ?? $d['forum'] ?? 0 ), $u, (string) ( $d['title'] ?? '' ), (string) ( $d['body'] ?? '' ), (string) ( $d['type'] ?? 'topic' ), isset( $d['version'] ) ? (string) $d['version'] : null ) );
			$ops->add( 'offline_restore', fn( array $d, int $u ): array=>$drafts->offlineRestore( (int) ( $d['object'] ?? 0 ), $u, (string) ( $d['title'] ?? '' ), (string) ( $d['body'] ?? '' ), (string) ( $d['type'] ?? 'topic' ), (int) ( $d['owner'] ?? 0 ), (int) ( $d['blog'] ?? 0 ) ) );
			$ops->add( 'composer_preview', fn( array $d, int $u ): array=>$drafts->preview( (int) ( $d['object'] ?? 0 ), $u, (string) ( $d['title'] ?? '' ), (string) ( $d['body'] ?? '' ), (string) ( $d['type'] ?? 'topic' ), (int) ( $d['owner'] ?? 0 ), (int) ( $d['blog'] ?? 0 ) ) );
			$ops->add( 'draft_discard', fn( array $d, int $u ): array=>$drafts->discard( (int) ( $d['object'] ?? $d['forum'] ?? 0 ), $u, (string) ( $d['type'] ?? 'topic' ), isset( $d['version'] ) ? (string) $d['version'] : null ) );
			$ops->add( 'schedule', fn( array $d, int $u ): array=>$drafts->schedule( (int) ( $d['topic'] ?? 0 ), $u, (string) ( $d['date'] ?? '' ) ) );
			$templates                   = new \ForumIntelligence\Domain\Composer\Templates( $a );
			$this->services['templates'] = $templates;
			$ops->add( 'template_variants', fn( array $d, int $u ): array=>$templates->configure( $u, (string) ( $d['json'] ?? '' ) ) );
			$ops->add( 'templates', fn( array $d, int $u ): array=>$drafts->templates( $u, (string) ( $d['json'] ?? '' ) ) );
			$this->routes->query( 'fields', fn( array $d, int $u ): array=>$fields->read( (int) ( $d['object'] ?? 0 ), $u ), true );
						$this->routes->query(
							'template',
							fn( array $d, int $u ): array=>$drafts->template( (int) ( $d['object'] ?? 0 ), $u, array_intersect_key( $d, array_fill_keys( \ForumIntelligence\Domain\Composer\Templates::AXES, true ) ) ),
							true,
							array_fill_keys(
								\ForumIntelligence\Domain\Composer\Templates::AXES,
								array(
									'type'      => 'string',
									'maxLength' => 80,
								)
							)
						);
			$this->routes->query( 'draft', fn( array $d, int $u ): ?array=>$drafts->read( (int) ( $d['object'] ?? 0 ), $u, (string) ( $d['type'] ?? 'topic' ) ) );
		}
		if ( $this->enabled( 'attachments' ) ) {
			try {
				$isProtected                   = defined( 'FI_PROTECTED_STORAGE_DIR' );
				$storage                       = $isProtected ? new \ForumIntelligence\Infrastructure\Storage\ProtectedLocalStorage( (string) constant( 'FI_PROTECTED_STORAGE_DIR' ) ) : new \ForumIntelligence\Infrastructure\Storage\PublicMediaStorage();
				$service                       = new \ForumIntelligence\Domain\Attachments\Attachments( $s, $a, $storage, $isProtected );
				$this->services['attachments'] = $service;
				$ops->add( 'attachment', fn( array $d, int $u ): array=>$service->upload( (int) ( $d['object'] ?? 0 ), $u, (array) ( $d['upload'] ?? array() ) ) );
				$this->routes->query( 'attachments', fn( array $d, int $u ): array=>$service->listing( (int) ( $d['object'] ?? 0 ), $u ), true );
				( new \ForumIntelligence\Frontend\Downloads( $service ) )->register();
			} catch ( \ForumIntelligence\Domain\Failure $failure ) {
				add_action(
					'admin_notices',
					static function () use ( $failure ): void {
						if ( current_user_can( 'manage_options' ) ) {
							echo '<div class="notice notice-error"><p>' . esc_html( $failure->getMessage() ) . '</p></div>';
						} }
				);
			}
		}
		if ( $this->enabled( 'push' ) ) {
			$push = new \ForumIntelligence\Domain\Notifications\Push( $s, $this->queue, $a, $this->get( 'following' ) );
			$push->register();
			$this->services['push'] = $push;
			$ops->add( 'push_subscribe', fn( array $d, int $u ): array=>$push->subscribe( $u, (array) ( $d['subscription'] ?? array() ) ) );
			$ops->add( 'push_unsubscribe', fn( array $d, int $u ): array=>$push->unsubscribe( $u, (string) ( $d['endpoint'] ?? '' ) ) );
		}
		if ( $this->enabled( 'messaging' ) ) {
			$following                   = $this->get( 'following' ) ?? new \ForumIntelligence\Domain\Community\Following( $s, $a );
			$service                     = new \ForumIntelligence\Domain\Messaging\Messaging( $s, $following, $this->get( 'notifications' ) ? $this->queue : null );
			$this->services['messaging'] = $service;
			$ops->add( 'message_start', fn( array $d, int $u ): array=>$service->start( $u, (int) ( $d['recipient'] ?? 0 ), (string) ( $d['text'] ?? '' ) ) );
			$ops->add( 'message_send', fn( array $d, int $u ): array=>$service->send( (int) ( $d['conversation'] ?? 0 ), $u, (string) ( $d['text'] ?? '' ) ) );
			$ops->add( 'conversation', fn( array $d, int $u ): array=>$service->command( (int) ( $d['conversation'] ?? 0 ), $u, (string) ( $d['command'] ?? '' ), (int) ( $d['message'] ?? 0 ) ) );
			$transport = apply_filters( 'fi_realtime_transport', new \ForumIntelligence\Infrastructure\Queue\PollingTransport( $service, $s ) );
			if ( $transport instanceof \ForumIntelligence\Contracts\RealtimeTransport ) {
				$this->routes->query(
					'chat',
					fn( array $d, int $u ): array=>$transport->poll( (int) ( $d['conversation'] ?? 0 ), $u, (int) ( $d['after'] ?? 0 ) ),
					false,
					array(
						'conversation' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'after'        => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					)
				);
			}
			$this->routes->query( 'conversations', fn( array $d, int $u ): array=>$service->listing( $u, (int) ( $d['page'] ?? 1 ) ) );
			$this->routes->query( 'messages', fn( array $d, int $u ): array=>$service->messages( (int) ( $d['conversation'] ?? 0 ), $u, (int) ( $d['after'] ?? 0 ) ) );
		}
	}
}
