<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Search;
use ForumIntelligence\Contracts\SearchEngine;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
/** Unpublished drafts use local retrieval only, regardless of the external AI setting. */
final class Duplicates {
	public function __construct( private SearchEngine $local, private Store $store, private Access $access ) {}
	public function suggest( string $title, string $body, int $forum, string $tags, array $fields, int $user ): array {
		if ( $forum ) {
			$this->access->read( $forum, $user ); }
		$title = mb_substr( sanitize_text_field( $title ), 0, 200 );
		$body  = mb_substr( wp_strip_all_tags( $body ), 0, 12000 );
		if ( mb_strlen( trim( $title ) ) < 12 ) {
			return array( 'items' => array() ); }
		$identity = $user ?: (int) sprintf( '%u', crc32( hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? 'anonymous' ), wp_salt( 'nonce' ) ) ) );
		$this->store->limit( $identity, 'duplicates', 30 );
		$metadata = '';
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( $field['visibility'] !== 'public' || empty( $field['searchable'] ) || ( $field['forums'] && ! in_array( $forum, $field['forums'], true ) ) ) {
				continue; }
			$value = $fields[ $field['key'] ] ?? '';
			if ( is_scalar( $value ) ) {
				$metadata .= ' ' . sanitize_text_field( (string) $value ); }
		}
		$filters = array(
			'type'     => 'topic',
			'per_page' => 10,
			'forum'    => $forum,
		);
		$items   = $this->local->search( $title, $filters, $user )['items'];
		$words   = Ranking::tokens( $body . ' ' . $metadata . ' ' . sanitize_text_field( $tags ) );
		$words   = array_values( array_filter( $words, static fn( string $word ): bool=>mb_strlen( $word ) >= 4 ) );
		usort( $words, static fn( string $x, string $y ): int=>mb_strlen( $y ) <=> mb_strlen( $x ) );
		if ( $words ) {
			$items = array( ...$items, ...$this->local->search( implode( ' ', array_slice( $words, 0, 3 ) ), $filters, $user )['items'] ); }
		$unique = array();
		foreach ( $items as $item ) {
			if ( ! $this->access->canRead( (int) $item['id'], $user ) ) {
				continue; }
			$post                  = get_post( $item['id'] );
			$item['score']         = 0.65 * Ranking::lexical( $title, $post->post_title, $post->post_content ) + 0.25 * Ranking::lexical( implode( ' ', array_slice( $words, 0, 8 ) ), $post->post_title, $post->post_content ) + ( $item['solved'] ? 0.1 : 0 );
			$item['reason']        = $item['solved'] ? 'related_solved_discussion' : 'related_discussion';
			$unique[ $item['id'] ] = $item;
		}
		$threshold = (float) ( get_option( 'fi_settings', array() )['duplicate_threshold'] ?? 0.25 );
		$unique    = array_filter( $unique, static fn( array $item ): bool=>$item['score'] >= $threshold );
		$this->store->metric( $unique ? 'duplicate_suggestions_shown' : 'duplicate_no_match' );
		usort( $unique, static fn( array $x, array $y ): int=>$y['score'] <=> $x['score'] );
		$items = array_slice( $unique, 0, 5 );
		return array(
			'items'               => $items,
			'offer'               => $this->offer( $items, $forum, $user ),
			'may_continue'        => true,
			'external_processing' => false,
		);
	}
	/** Offers contain only current IDs and a short-lived signature; no composer text is retained. */
	private function canOffer( int $forum, int $user ): bool {
		$settings = get_option( 'fi_settings', array() );
		$modules  = is_array( $settings ) ? ( $settings['modules'] ?? array() ) : null;
		return is_array( $modules ) && ( $modules['composer'] ?? true ) === true && $forum > 0
			&& get_post_type( $forum ) === bbp_get_forum_post_type() && $this->access->activeMember( $user )
			&& user_can( $user, 'publish_topics' ) && ! bbp_is_forum_category( $forum )
			&& ( user_can( $user, 'moderate' ) || ! bbp_is_forum_closed( $forum, true ) );
	}
	private function offer( array $items, int $forum, int $user ): string {
		if ( ! $items || ! $this->canOffer( $forum, $user ) ) {
			return ''; }
		$payload = array(
			'version' => 1,
			'site'    => get_current_blog_id(),
			'user'    => $user,
			'forum'   => $forum,
			'issued'  => time(),
			'nonce'   => bin2hex( random_bytes( 16 ) ),
			'ids'     => array_map( static fn( array $item ): int => (int) $item['id'], $items ),
		);
		$encoded = rtrim( strtr( base64_encode( (string) wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
	}
	private function offerData( string $offer, int $user ): array {
		$invalid = new \ForumIntelligence\Domain\Failure( 'invalid_feedback', __( 'This suggestion feedback is unavailable. Continue composing your topic.', 'forum-intelligence-for-bbpress' ) );
		if ( strlen( $offer ) > 2048 || ! preg_match( '/^([A-Za-z0-9_-]{1,1600})\.([a-f0-9]{64})$/D', $offer, $parts )
			|| ! hash_equals( hash_hmac( 'sha256', $parts[1], wp_salt( 'nonce' ) ), $parts[2] ) ) {
			throw $invalid; }
		$decoded = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		$data    = $decoded !== false ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || count( $data ) !== 7 || ( $data['version'] ?? null ) !== 1
			|| ( $data['site'] ?? null ) !== get_current_blog_id() || ( $data['user'] ?? null ) !== $user
			|| ! is_int( $data['forum'] ?? null ) || $data['forum'] <= 0
			|| ! is_int( $data['issued'] ?? null ) || $data['issued'] > time() || $data['issued'] < time() - 900
			|| ! is_string( $data['nonce'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $data['nonce'] )
			|| ! is_array( $data['ids'] ?? null ) || ! array_is_list( $data['ids'] ) || ! $data['ids'] || count( $data['ids'] ) > 5 ) {
			throw $invalid; }
		$ids = array();
		foreach ( $data['ids'] as $id ) {
			if ( ! is_int( $id ) || $id <= 0 || in_array( $id, $ids, true ) ) {
				throw $invalid; }
			$ids[] = $id;
		}
		return $data;
	}
	private function feedbackAdmission( array $data, int $user ): void {
		if ( ! $this->canOffer( $data['forum'], $user ) ) {
			throw new \ForumIntelligence\Domain\Failure( 'forbidden', __( 'Suggestion feedback is unavailable for this account or forum. Continue composing your topic.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$this->access->read( $data['forum'], $user );
		foreach ( $data['ids'] as $id ) {
			$this->access->read( $id, $user );
			if ( get_post_type( $id ) !== bbp_get_topic_post_type() || $this->access->forum( $id ) !== $data['forum'] ) {
				throw new \ForumIntelligence\Domain\Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		}
		if ( get_current_blog_id() !== $data['site'] || ! $this->canOffer( $data['forum'], $user ) ) {
			throw new \ForumIntelligence\Domain\Failure( 'forbidden', __( 'Suggestion feedback is unavailable for this account or forum. Continue composing your topic.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
	/** Count each acknowledged outcome once per issued offer, without persistent user/source/query attribution. */
	public function feedback( string $offer, string $outcome, int $user, int $objectId = 0 ): array {
		$data = $this->offerData( $offer, $user );
		if ( ! in_array( $outcome, array( 'viewed', 'opened', 'dismissed' ), true )
			|| ( $outcome === 'opened' ? ! in_array( $objectId, $data['ids'], true ) : $objectId !== 0 ) ) {
			throw new \ForumIntelligence\Domain\Failure( 'invalid_feedback', __( 'This suggestion feedback is unavailable. Continue composing your topic.', 'forum-intelligence-for-bbpress' ) ); }
		$this->feedbackAdmission( $data, $user );
		$this->store->limit( $user, 'duplicate_feedback', 90 );
		$recorded = $this->store->transaction(
			function () use ( $offer, $outcome, $data, $user ): bool {
				$this->feedbackAdmission( $data, $user );
				$bucket  = hash( 'sha256', 'duplicate_feedback:' . $offer . ':' . $outcome );
				$claimed = $this->store->execute( "INSERT IGNORE INTO {$this->store->table('limits')} (bucket,window_start,count) VALUES (%s,%d,1)", array( $bucket, $data['issued'] ) );
				$this->feedbackAdmission( $data, $user );
				if ( $claimed === 0 ) {
					return false; }
				$this->store->metric( 'duplicate_feedback_' . $outcome );
				return true;
			}
		);
		return array( 'recorded' => $recorded );
	}
}
