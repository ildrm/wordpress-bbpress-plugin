<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Community\Following;
use ForumIntelligence\Domain\Failure;
final class Notifications {
	public function __construct( private Store $store, private Queue $queue, private Access $access, private Following $following ) {}
	public function register(): void {
		add_action( 'fi_knowledge_published', fn( int $topic, int $actor, string $key )=>$this->request( $topic, $actor, 'knowledge', $key ), 10, 3 );
		$this->queue->handle(
			'poll_notify',
			function ( array $data ): void {
				if ( (int) $data['cursor'] === 0 ) {
					$this->request( (int) $data['topic'], (int) $data['actor'], 'poll', $data['key'] );}
				$rows = $this->store->rows( "SELECT owner_id FROM {$this->store->table('records')} WHERE kind='poll_vote' AND object_id=%d AND owner_id>%d ORDER BY owner_id LIMIT 100", array( (int) $data['topic'], (int) $data['cursor'] ) );
				foreach ( $rows as $row ) {
					$this->deliver( (int) $row['owner_id'], (int) $data['topic'], (int) $data['actor'], 'poll', $data['key'] );}
				if ( count( $rows ) === 100 ) {
					$data['cursor'] = (int) end( $rows )['owner_id'];
					$this->queue->enqueue( 'poll_notify', $data, $data['key'] . ':' . $data['cursor'] );}
			}
		);

		add_action( 'fi_badge_awarded', fn( int $member, int $record, int $actor, string $key )=>$this->deliver( $member, $record, $actor, 'badge', $key ), 10, 4 );
		$this->queue->handle(
			'message_notify',
			function ( array $data ): void {
				$message = $this->store->row( "SELECT owner_id,object_id FROM {$this->store->table('records')} WHERE kind='message' AND id=%d", array( (int) $data['message'] ) );
				if ( ! $message ) {
					return;}
				foreach ( $this->store->rows( "SELECT user_id FROM {$this->store->table('links')} WHERE kind='message_participant' AND source_id=%d", array( $message['object_id'] ) ) as $participant ) {
					$this->deliver( (int) $participant['user_id'], (int) $data['message'], (int) $message['owner_id'], 'message', 'message:' . (int) $data['message'] );
				}
			}
		);
		$this->queue->handle( 'notify', fn( array $data )=>$this->fanout( $data ) );
		$this->queue->handle( 'digest', fn( array $data )=>$this->digest( (int) $data['user'] ) );
		add_action(
			'fi_solution_changed',
			function ( int $topic, int $reply, string $command, int $actor ): void {
				$row = $this->store->row( "SELECT version FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $topic ) );
				$key = 'solution:' . $topic . ':' . ( $row['version'] ?? 0 );
				$this->request( $topic, $actor, 'solution', $key );
				if ( $reply ) {
					$this->deliver( (int) get_post_field( 'post_author', $reply ), $topic, $actor, 'solution', $key );}
			},
			10,
			4
		);
		add_action(
			'bbp_new_reply',
			function ( int $reply ): void {
				$this->request( $reply, (int) get_post_field( 'post_author', $reply ), 'reply', 'reply:' . $reply );
			}
		);
		add_action(
			'bbp_new_topic',
			function ( int $topic ): void {
				$this->request( $topic, (int) get_post_field( 'post_author', $topic ), 'topic', 'topic:' . $topic ); }
		);
		add_action(
			'fi_moderation_action',
			function ( int $objectId, string $command, int $actor ): void {
				if ( $command === 'warn' ) {
					$this->deliver( (int) get_post_field( 'post_author', $objectId ), $objectId, $actor, 'moderation', 'warning:' . $objectId . ':' . time() ); }
			},
			10,
			3
		);
	}
	public function request( int $objectId, int $actor, string $kind, string $key ): void {
		$this->queue->enqueue(
			'notify',
			array(
				'object' => $objectId,
				'actor'  => $actor,
				'kind'   => $kind,
				'key'    => $key,
				'cursor' => 0,
			),
			$key . ':0'
		);
	}
	private function fanout( array $data ): void {
		$objectId = (int) $data['object'];
		$topic    = $this->access->topic( $objectId );
		$forum    = $this->access->forum( $objectId );
		if ( ! $topic || ! $forum ) {
			return; }
		$tags       = wp_get_object_terms( $topic, bbp_get_topic_tag_tax_id(), array( 'fields' => 'ids' ) );
		$tags       = is_wp_error( $tags ) ? array() : array_map( 'intval', $tags );
		$tagSql     = $tags ? " OR (kind='follow_tag' AND target_id IN (" . implode( ',', array_fill( 0, count( $tags ), '%d' ) ) . '))' : '';
		$rows       = $this->store->rows( "SELECT DISTINCT user_id FROM {$this->store->table('links')} WHERE user_id>%d AND ((kind='follow_topic' AND target_id=%d) OR (kind='follow_forum' AND target_id=%d) OR (kind='follow_user' AND target_id=%d) $tagSql) ORDER BY user_id LIMIT 100", array( (int) $data['cursor'], $topic, $forum, (int) $data['actor'], ...$tags ) );
		$recipients = array_map( 'intval', array_column( $rows, 'user_id' ) );
		if ( (int) $data['cursor'] === 0 ) {
			$recipients[] = (int) get_post_field( 'post_author', $topic );}
		cache_users( array_values( array_unique( array_filter( $recipients ) ) ) );
		if ( (int) $data['cursor'] === 0 ) {
			$this->deliver( (int) get_post_field( 'post_author', $topic ), $objectId, (int) $data['actor'], $data['kind'], $data['key'] ); }
		foreach ( $rows as $row ) {
			$this->deliver( (int) $row['user_id'], $objectId, (int) $data['actor'], $data['kind'], $data['key'] ); }
		if ( count( $rows ) === 100 ) {
			$data['cursor'] = (int) end( $rows )['user_id'];
			$this->queue->enqueue( 'notify', $data, $data['key'] . ':' . $data['cursor'] ); }
	}
	public function deliver( int $user, int $objectId, int $actor, string $kind, string $key ): void {
		if ( ! $user || $user === $actor || ! ( new Visibility( $this->store, $this->access, $this->following ) )->allows( $user, $objectId, $kind ) ) {
			return; }
		$prefs = $this->preferences( $user );
		if ( in_array( $kind, $prefs['muted_categories'], true ) ) {
			return; }
		$created = $this->store->execute( "INSERT IGNORE INTO {$this->store->table('notifications')} (user_id,object_id,kind,event_key,created_at) VALUES (%d,%d,%s,%s,%s)", array( $user, $objectId, $kind, $key, $this->store->now() ) );
		if ( ! $created ) {
			return; }
		do_action( 'fi_notification_created', $user, $objectId, $kind, $key );
		$mode = $prefs['email_mode'];
		if ( $mode === 'off' || ( $mode === 'important' && ! self::important( $kind ) ) ) {
			return; }
		$interval = array(
			'immediate' => 60,
			'important' => 60,
			'hourly'    => 3600,
			'daily'     => 86400,
			'weekly'    => 604800,
		)[ $mode ];
		$due      = ( intdiv( time(), $interval ) + 1 ) * $interval;
		$this->queue->enqueue( 'digest', array( 'user' => $user ), $user . ':' . $due, max( 0, $due - time() ) );
	}
	/** Current authorized notifications; native pagination never publishes raw totals. */
	public function inbox( int $user, int $page = 1, bool $unread = false, string $kind = '', string $state = 'all' ): array {
		$this->requireUser( $user );
		if ( ( $kind !== '' && ! array_key_exists( $kind, self::categories() ) ) || ! in_array( $state, array( 'all', 'read', 'unread' ), true ) ) {
			throw new Failure( 'invalid_filter', __( 'Choose a valid notification filter.', 'forum-intelligence-for-bbpress' ), 400 );
		}
		$page  = max( 1, min( 1000, $page ) );
		$where = '';
		$args  = array( $user );
		if ( $unread || $state === 'unread' ) {
			$where .= ' AND is_read=0';
		} elseif ( $state === 'read' ) {
			$where .= ' AND is_read=1';
		}
		if ( $kind !== '' ) {
			$where .= ' AND kind=%s';
			$args[] = $kind;
		}
		$args[]     = ( $page - 1 ) * 50;
		$rows       = $this->store->rows( "SELECT * FROM {$this->store->table('notifications')} WHERE user_id=%d $where ORDER BY id DESC LIMIT 50 OFFSET %d", $args );
		$items      = array();
		$visibility = new Visibility( $this->store, $this->access, $this->following );
		$muted      = $this->preferences( $user )['muted_categories'];
		foreach ( $rows as $row ) {
			$id = (int) $row['object_id'];
			if ( in_array( $row['kind'], $muted, true ) || ! $visibility->allows( $user, $id, $row['kind'] ) ) {
				continue;
			}
			$row['title'] = $row['kind'] === 'knowledge_review' ? __( 'Knowledge article needs source review', 'forum-intelligence-for-bbpress' ) : ( $row['kind'] === 'badge' ? __( 'Community achievement', 'forum-intelligence-for-bbpress' ) : ( $row['kind'] === 'message' ? __( 'Private message', 'forum-intelligence-for-bbpress' ) : wp_strip_all_tags( (string) get_post_field( 'post_title', $this->access->topic( $id ) ) ) ) );
			$row['url']   = $visibility->url( $id, $row['kind'] );
			$items[]      = $row;
		}
		return array(
			'items'          => $items,
			'page'           => $page,
			'unread_in_page' => count( array_filter( $items, fn( array $item ): bool=> ! $item['is_read'] ) ),
		);
	}
	/** Bounded lower bound, reauthorized on every request; never a raw SQL count. */
	public function summary( int $user ): array {
		$this->requireUser( $user );
		$this->store->limit( $user, 'unread_summary', 20 );
		$count    = 0;
		$complete = true;
		if ( $this->access->activeMember( $user ) ) {
			$visibility = new Visibility( $this->store, $this->access, $this->following );
			$muted      = $this->preferences( $user )['muted_categories'];
			$rows       = $this->store->rows( "SELECT object_id,kind FROM {$this->store->table('notifications')} WHERE user_id=%d AND is_read=0 ORDER BY id DESC LIMIT 500", array( $user ) );
			$checked    = array();
			$complete   = count( $rows ) < 500;
			foreach ( $rows as $row ) {
				$key = $row['kind'] . ':' . $row['object_id'];
				if ( ! array_key_exists( $key, $checked ) ) {
					$checked[ $key ] = ! in_array( $row['kind'], $muted, true ) && $visibility->allows( $user, (int) $row['object_id'], $row['kind'] );
				}
				if ( $checked[ $key ] && ++$count === 100 ) {
					$complete = false;
					break;
				}
			}
		}
		if ( $complete ) {
			// translators: %d is the number of currently authorized unread notifications.
			$label = sprintf( _n( '%d unread notification', '%d unread notifications', $count, 'forum-intelligence-for-bbpress' ), $count );
		} elseif ( $count > 0 ) {
			// translators: %d is a lower bound on currently authorized unread notifications.
			$label = sprintf( __( 'At least %d unread notifications', 'forum-intelligence-for-bbpress' ), $count );
		} else {
			$label = __( 'Notifications', 'forum-intelligence-for-bbpress' );
		}
		return array(
			'unread'   => $count,
			'complete' => $complete,
			'label'    => $label,
		);
	}
	public static function categories(): array {
		return array(
			'reply'            => __( 'Replies', 'forum-intelligence-for-bbpress' ),
			'topic'            => __( 'New topics', 'forum-intelligence-for-bbpress' ),
			'solution'         => __( 'Solutions', 'forum-intelligence-for-bbpress' ),
			'mention'          => __( 'Mentions', 'forum-intelligence-for-bbpress' ),
			'moderation'       => __( 'Moderation', 'forum-intelligence-for-bbpress' ),
			'knowledge'        => __( 'Knowledge articles', 'forum-intelligence-for-bbpress' ),
			'knowledge_review' => __( 'Article source reviews', 'forum-intelligence-for-bbpress' ),
			'message'          => __( 'Private messages', 'forum-intelligence-for-bbpress' ),
			'poll'             => __( 'Polls', 'forum-intelligence-for-bbpress' ),
			'badge'            => __( 'Achievements', 'forum-intelligence-for-bbpress' ),
			'saved_search'     => __( 'Saved-search matches', 'forum-intelligence-for-bbpress' ),
		);
	}
	public function mark( int $id, int $user, string $command ): array {
		$this->requireUser( $user );
		if ( ! in_array( $command, array( 'read', 'unread', 'all_read', 'delete' ), true ) ) {
			throw new Failure( 'invalid_action', __( 'Invalid notification action.', 'forum-intelligence-for-bbpress' ) ); }
		$table = $this->store->table( 'notifications' );
		if ( $command === 'all_read' ) {
			$this->store->execute( "UPDATE $table SET is_read=1 WHERE user_id=%d", array( $user ) ); } elseif ( $command === 'delete' ) {
			$this->store->execute( "DELETE FROM $table WHERE id=%d AND user_id=%d", array( $id, $user ) ); } else {
				$this->store->execute( "UPDATE $table SET is_read=%d WHERE id=%d AND user_id=%d", array( (int) ( $command === 'read' ), $id, $user ) ); }
			return array( 'saved' => true );
	}
	public static function metaKey(): string {
		return '_fi_preferences_' . get_current_blog_id();}
	public function preferences( int $user ): array {
		$this->requireUser( $user );
		return array_merge(
			array(
				'email_mode'       => 'off',
				'muted_categories' => array(),
			),
			(array) get_user_meta( $user, self::metaKey(), true )
		);
	}
	public function savePreferences( int $user, string $emailMode, array $muted ): array {
		$this->requireUser( $user );
		if ( ! in_array( $emailMode, array( 'off', 'immediate', 'hourly', 'daily', 'weekly', 'important' ), true ) ) {
			throw new Failure( 'invalid_preference', __( 'Invalid email preference.', 'forum-intelligence-for-bbpress' ) ); }
		$preferences = array(
			'email_mode'       => $emailMode,
			'muted_categories' => array_values( array_intersect( array( 'reply', 'topic', 'solution', 'mention', 'moderation', 'knowledge', 'knowledge_review', 'message', 'poll', 'badge', 'saved_search' ), $muted ) ),
		);
		update_user_meta( $user, self::metaKey(), $preferences );
		return $preferences;
	}
	private function digest( int $user ): void {
		$prefs = $this->preferences( $user );
		if ( $prefs['email_mode'] === 'off' ) {
			return; }
		$rows  = $this->store->rows( "SELECT * FROM {$this->store->table('notifications')} WHERE user_id=%d AND emailed=0 ORDER BY id LIMIT 50", array( $user ) );
		$lines = array();
		$ids   = array();
		foreach ( $rows as $row ) {
			$ids[]    = (int) $row['id'];
			$objectId = (int) $row['object_id'];
			if ( ! ( new Visibility( $this->store, $this->access, $this->following ) )->allows( $user, $objectId, $row['kind'] ) || in_array( $row['kind'], $prefs['muted_categories'], true ) || ( $prefs['email_mode'] === 'important' && ! self::important( $row['kind'] ) ) ) {
				continue; }
			// translators: %s is the discussion URL.
			$lines[] = sprintf( __( 'Community activity: %s', 'forum-intelligence-for-bbpress' ), ( new Visibility( $this->store, $this->access, $this->following ) )->url( $objectId, $row['kind'] ) );
		}
		$recipient = get_userdata( $user );
		if ( $lines && $recipient ) {
			// translators: %s is the preferences URL.
			$lines[] = sprintf( __( 'Manage notification preferences after signing in: %s', 'forum-intelligence-for-bbpress' ), admin_url( 'profile.php#fi_preferences' ) );
			if ( ! wp_mail( $recipient->user_email, __( 'Your community updates', 'forum-intelligence-for-bbpress' ), implode( "\n\n", $lines ) ) ) {
				throw new Failure( 'mail_failed', 'Mail delivery failed', 503 ); }
		}
		foreach ( $ids as $id ) {
			$this->store->execute( "UPDATE {$this->store->table('notifications')} SET emailed=1 WHERE id=%d AND user_id=%d", array( $id, $user ) ); }
	}
	public static function important( string $kind ): bool {
		return in_array( $kind, array( 'solution', 'mention', 'moderation', 'message', 'badge' ), true );
	}
	private function requireUser( int $user ): void {
		if ( ! $user || ! get_userdata( $user ) ) {
			throw new Failure( 'forbidden', __( 'Sign in to manage notifications.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
}
