<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Community\Following;
/** Each notification kind has an explicit source namespace and current authorization. */
final class Visibility {
	public function __construct( private Store $store, private Access $access, private Following $following ) {}
	public function allows( int $user, int $objectId, string $kind ): bool {
		if ( ! $this->access->activeMember( $user ) ) {
			return false;}

		if ( $kind === 'knowledge_review' ) {
			if ( ! $this->reviewEnabled() || ! $this->reviewOwner( $user, $objectId ) ) {
				return false;}
			$knowledge = new \ForumIntelligence\Domain\Knowledge\Knowledge( $this->store, new \ForumIntelligence\Infrastructure\Queue\Queue( $this->store ), $this->access, new \ForumIntelligence\Infrastructure\AI\NullProvider() );
			try {
				$state = $knowledge->reviewState( $objectId, $user );
				return $state['stale'] && ! $this->following->muted( $user, $state['topic'] ) && $this->reviewOwner( $user, $objectId ) && $this->reviewEnabled();
			} catch ( \ForumIntelligence\Domain\Failure $failure ) {
				return false;}
		}
		if ( $kind === 'badge' ) {
			if ( empty( get_option( 'fi_settings', array() )['modules']['badges'] ) ) {
				return false;}
			$row = $this->store->row( "SELECT owner_id FROM {$this->store->table('records')} WHERE kind='badge' AND id=%d", array( $objectId ) );
			return $row && (int) $row['owner_id'] === $user && ( new \ForumIntelligence\Domain\Reputation\Badges( $this->store, $this->access ) )->visible( $objectId, $user );
		}
		if ( $kind !== 'message' ) {
			return $this->access->canRead( $objectId, $user ) && ! $this->following->muted( $user, $objectId );}
		if ( empty( get_option( 'fi_settings', array() )['modules']['messaging'] ) || ! user_can( $user, 'publish_replies' ) ) {
			return false;}
		$message = $this->store->row( "SELECT owner_id,object_id FROM {$this->store->table('records')} WHERE id=%d AND kind='message'", array( $objectId ) );
		if ( ! $message || ! $this->access->activeMember( (int) $message['owner_id'] ) || $this->following->blocked( $user, (int) $message['owner_id'] ) ) {
			return false;}
		return (bool) $this->store->row( "SELECT id FROM {$this->store->table('links')} WHERE source_id=%d AND user_id=%d AND kind='message_participant'", array( $message['object_id'], $user ) );
	}

	/** @phpstan-impure Native callbacks can revoke current configuration. */
	private function reviewEnabled(): bool {
		$settings = (array) get_option( 'fi_settings', array() );
		return ! empty( $settings['knowledge_review_notifications'] ) && ( $settings['modules']['knowledge'] ?? true ) && ( $settings['modules']['notifications'] ?? true );
	}
	/** @phpstan-impure Native source callbacks can change ownership and capabilities. */
	private function reviewOwner( int $user, int $id ): bool {
		$article = get_post( $id );
		return $article && ( new \ForumIntelligence\Domain\Knowledge\Targets() )->availableArticle( $article ) && in_array( $article->post_status, array( 'draft', 'pending', 'future', 'private', 'publish' ), true ) && (int) $article->post_author === $user && user_can( $user, 'edit_post', $id );
	}
	public function url( int $objectId, string $kind ): string {
		if ( $kind === 'knowledge_review' ) {
			return add_query_arg(
				array(
					'post'   => $objectId,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			); }
		if ( $kind === 'badge' ) {
			$row = $this->store->row( "SELECT owner_id FROM {$this->store->table('records')} WHERE kind='badge' AND id=%d", array( $objectId ) );
			return bbp_get_user_profile_url( (int) ( $row['owner_id'] ?? 0 ) );}

		if ( $kind === 'message' ) {
			$row = $this->store->row( "SELECT object_id FROM {$this->store->table('records')} WHERE id=%d AND kind='message'", array( $objectId ) );
			return add_query_arg(
				array(
					'fi_messages'     => 1,
					'fi_conversation' => (int) ( $row['object_id'] ?? 0 ),
				),
				home_url( '/' )
			);
		}
		return get_post_type( $objectId ) === bbp_get_reply_post_type() ? bbp_get_reply_url( $objectId ) : get_permalink( $this->access->topic( $objectId ) );
	}
}
