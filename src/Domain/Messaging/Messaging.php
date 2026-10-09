<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Messaging;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Domain\Community\Following;
use ForumIntelligence\Domain\Failure;
final class Messaging {
	public function __construct( private Store $store, private Following $following, private ?\ForumIntelligence\Infrastructure\Queue\Queue $queue = null ) {}
	private function user( int $user ): void {
		if ( ! $user || bbp_is_user_inactive( $user ) || ! user_can( $user, 'publish_replies' ) ) {
			throw new Failure( 'forbidden', __( 'Messaging participation permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
	public function start( int $sender, int $recipient, string $text ): array {
		$this->user( $sender );
		$this->user( $recipient );
		if ( $sender === $recipient || $this->following->blocked( $sender, $recipient ) ) {
			throw new Failure( 'recipient_unavailable', __( 'This recipient is unavailable.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$this->store->limit( $sender, 'conversation', 5, 3600 );
		return $this->store->transaction(
			function () use ( $sender, $recipient, $text ): array {
				$id = $this->store->insert(
					'records',
					array(
						'kind'       => 'conversation',
						'owner_id'   => $sender,
						'record_key' => 'conversation:' . bin2hex( random_bytes( 16 ) ),
						'payload'    => '{}',
						'created_at' => $this->store->now(),
						'updated_at' => $this->store->now(),
					)
				);
				foreach ( array( $sender, $recipient ) as $user ) {
					$this->store->insert(
						'links',
						array(
							'user_id'    => $user,
							'source_id'  => $id,
							'kind'       => 'message_participant',
							'payload'    => '{"last_read":0}',
							'created_at' => $this->store->now(),
						)
					); }
				$this->send( $id, $sender, $text );
				return array( 'conversation' => $id );
			}
		);
	}
	public function send( int $conversation, int $sender, string $text ): array {
		return $this->store->transaction(
			function () use ( $conversation, $sender, $text ): array {
				$this->lock( $conversation );
				return $this->persist( $conversation, $sender, $text );
			}
		);
	}
	private function persist( int $conversation, int $sender, string $text ): array {
		$this->user( $sender );
		$this->membership( $conversation, $sender );
		$participants = $this->store->rows( "SELECT user_id FROM {$this->store->table('links')} WHERE source_id=%d AND kind='message_participant'", array( $conversation ) );
		foreach ( $participants as $participant ) {
			if ( $this->following->blocked( $sender, (int) $participant['user_id'] ) ) {
				throw new Failure( 'recipient_unavailable', __( 'Messaging is blocked.', 'forum-intelligence-for-bbpress' ), 403 ); }
		}
		if ( count( $participants ) < 2 ) {
			throw new Failure( 'conversation_closed', __( 'This conversation is closed.', 'forum-intelligence-for-bbpress' ), 409 ); }
		$text = mb_substr( sanitize_textarea_field( $text ), 0, 5000 );
		if ( mb_strlen( trim( $text ) ) < 1 ) {
			throw new Failure( 'empty_message', __( 'Enter a message.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->limit( $sender, 'message', 20, 300 );
		$id = $this->store->insert(
			'records',
			array(
				'kind'       => 'message',
				'owner_id'   => $sender,
				'object_id'  => $conversation,
				'record_key' => 'message:' . bin2hex( random_bytes( 16 ) ),
				'payload'    => wp_json_encode( array( 'text' => $text ) ),
				'created_at' => $this->store->now(),
				'updated_at' => $this->store->now(),
			)
		);
		if ( $this->queue ) {
			$this->queue->enqueue( 'message_notify', array( 'message' => $id ), (string) $id );
		}
		return array(
			'message_id'   => $id,
			'conversation' => $conversation,
		);
	}
	public function listing( int $user, int $page = 1 ): array {
		$this->user( $user );
		$rows = $this->store->rows( "SELECT source_id,payload FROM {$this->store->table('links')} WHERE user_id=%d AND kind='message_participant' ORDER BY id DESC LIMIT 20 OFFSET %d", array( $user, ( max( 1, min( 1000, $page ) ) - 1 ) * 20 ) );
		return array(
			'items' => array_map(
				static fn( array $row ): array=>array(
					'conversation' => (int) $row['source_id'],
					'last_read'    => (int) ( json_decode( $row['payload'], true )['last_read'] ?? 0 ),
				),
				$rows
			),
			'page'  => $page,
		);
	}
	public function messages( int $conversation, int $user, int $after = 0 ): array {
		$this->user( $user );
		$this->membership( $conversation, $user );
		$rows  = $this->store->rows( "SELECT id,owner_id,payload,created_at FROM {$this->store->table('records')} WHERE kind='message' AND object_id=%d AND id>%d ORDER BY id LIMIT 50", array( $conversation, max( 0, $after ) ) );
		$items = array_map(
			static fn( array $row ): array=>array(
				'id'     => (int) $row['id'],
				'sender' => (int) $row['owner_id'],
				'text'   => json_decode( $row['payload'], true )['text'] ?? '',
				'date'   => $row['created_at'],
			),
			$rows
		);
		return array(
			'items'        => $items,
			'conversation' => $conversation,
			'has_more'     => count( $items ) === 50,
		);
	}
	public function command( int $conversation, int $user, string $command, int $message = 0 ): array {
		return $this->store->transaction(
			function () use ( $conversation, $user, $command, $message ): array {
				$this->lock( $conversation );
				return $this->change( $conversation, $user, $command, $message );
			}
		);
	}
	private function change( int $conversation, int $user, string $command, int $message ): array {
		$this->user( $user );
		$this->membership( $conversation, $user );
		if ( $command === 'read' && $message && ! $this->store->row( "SELECT id FROM {$this->store->table('records')} WHERE id=%d AND kind='message' AND object_id=%d", array( $message, $conversation ) ) ) {
			throw new Failure( 'invalid_cursor', __( 'Choose a message in this conversation.', 'forum-intelligence-for-bbpress' ) ); }
		if ( $command === 'leave' ) {
			$this->store->execute( "DELETE FROM {$this->store->table('links')} WHERE source_id=%d AND user_id=%d AND kind='message_participant'", array( $conversation, $user ) );
		} elseif ( $command === 'read' ) {
			$this->store->execute( "UPDATE {$this->store->table('links')} SET payload=JSON_SET(payload,'$.last_read',GREATEST(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.last_read')) AS UNSIGNED),0),%d)) WHERE source_id=%d AND user_id=%d AND kind='message_participant'", array( max( 0, $message ), $conversation, $user ) );
		} elseif ( $command === 'report' ) {
			$row = $this->store->row( "SELECT * FROM {$this->store->table('records')} WHERE id=%d AND object_id=%d AND kind='message'", array( $message, $conversation ) );
			if ( ! $row ) {
				throw new Failure( 'not_found', __( 'Message unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
			$this->store->limit( $user, 'message_report', 5, 300 );
			$this->store->saveRecord(
				'message_report',
				'message_report:' . $message . ':' . $user,
				$user,
				$conversation,
				array(
					'message'  => $message,
					'sender'   => (int) $row['owner_id'],
					'evidence' => json_decode( $row['payload'], true )['text'] ?? '',
					'status'   => 'new',
				)
			);
		} else {
			throw new Failure( 'invalid_action', __( 'Invalid conversation action.', 'forum-intelligence-for-bbpress' ) ); }
		return array( 'saved' => true );
	}
	private function lock( int $conversation ): void {
		if ( ! $this->store->row( "SELECT id FROM {$this->store->table('records')} WHERE id=%d AND kind='conversation' FOR UPDATE", array( $conversation ) ) ) {
			throw new Failure( 'not_found', __( 'Conversation unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
		}
	}
	private function membership( int $conversation, int $user ): array {
		$row = $this->store->row( "SELECT id,payload FROM {$this->store->table('links')} WHERE source_id=%d AND user_id=%d AND kind='message_participant'", array( $conversation, $user ) );
		if ( ! $row ) {
			throw new Failure( 'not_found', __( 'Conversation unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		return $row;
	}
}
