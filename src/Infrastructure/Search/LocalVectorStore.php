<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Contracts\VectorStore;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Domain\Search\Ranking;
use ForumIntelligence\Domain\Search\PreparedVector;
use ForumIntelligence\Infrastructure\Queue\Queue;
final class LocalVectorStore implements VectorStore {
	public function __construct( private Store $store ) {}
	public function put( int $source, string $hash, string $model, array $vector ): void {
		if ( ! Ranking::validVector( $vector ) ) {
			throw new \InvalidArgumentException( 'Invalid vector' ); }
		$vector = array_map( 'floatval', $vector );
		$json   = wp_json_encode( $vector );
		if ( ! is_string( $json ) ) {
			throw new \InvalidArgumentException( 'Invalid vector encoding' );
		}
		$frame     = PreparedVector::encode( $json );
		$binary    = $frame === null ? 'NULL' : 'UNHEX(%s)';
		$arguments = $frame === null ? array( $json, $model, $source, $hash ) : array( $json, bin2hex( $frame ), $model, $source, $hash );
		$this->store->execute( "UPDATE {$this->store->table('chunks')} SET vector=%s,vector_binary=$binary,model=%s WHERE source_id=%d AND content_hash=%s", $arguments );
	}
	public function remove( int $source ): void {
		$this->store->execute( "DELETE FROM {$this->store->table('chunks')} WHERE source_id=%d", array( $source ) );
	}
	public function nearest( array $vector, int $limit ): array {
		$query = PreparedVector::fromArray( $vector );
		if ( ! $query ) {
			return array(); }
		$model = (string) ( get_option( 'fi_settings', array() )['embedding_model'] ?? 'text-embedding-3-small' );
		// Count all scanned rows, including rejected candidates, within the portable bound.
		$remaining = 1000;
		$cursor    = 0;
		$scores    = array();
		$access    = new \ForumIntelligence\Integration\BbPress\Access();
		while ( $remaining > 0 ) {
			$size   = min( 50, $remaining );
			$before = $cursor > 0 ? ' AND source_id<%d' : '';
			$args   = $cursor > 0 ? array( $cursor, $size ) : array( $size );
			$rows   = $this->store->rows( "SELECT source_id,vector,vector_binary,content_hash,model FROM {$this->store->table('chunks')} WHERE visibility='public' AND vector<>'[]'$before ORDER BY source_id DESC LIMIT %d", $args );
			$count  = count( $rows );
			if ( ! $count ) {
				break; }
			$remaining -= $count;
			$cursor     = (int) $rows[ $count - 1 ]['source_id'];
			$batch      = array();
			foreach ( $rows as $row ) {
				$id = (int) $row['source_id'];
				if ( $id <= 0 || $row['model'] !== $model ) {
					continue; }
				$stored   = is_string( $row['vector_binary'] ) ? PreparedVector::fromFrame( $row['vector_binary'], $row['vector'] ) : null;
				$stored ??= PreparedVector::fromJson( $row['vector'] );
				if ( ! $stored ) {
					continue; }
				$batch[ $id ] = array(
					'vector' => $stored,
					'hash'   => $row['content_hash'],
				);
			}
			if ( $batch ) {
				$ids = array_keys( $batch );
				_prime_post_caches( $ids, false, true );
				$topics = array();
				foreach ( $ids as $id ) {
					$topic = $access->topic( $id );
					if ( $topic > 0 ) {
						$topics[ $topic ] = $topic; }
				}
				if ( $topics ) {
					_prime_post_caches( array_values( $topics ), false, true ); }
				$forums = array();
				foreach ( $ids as $id ) {
					$forum = $access->forum( $id );
					if ( $forum > 0 ) {
						$forums[ $forum ] = $forum; }
				}
				if ( $forums ) {
					_prime_post_caches( array_values( $forums ), false, true ); }
				foreach ( $batch as $id => $candidate ) {
					$hash = Indexer::hash( $id );
					if ( $hash === '' || $candidate['hash'] !== $hash ) {
						continue; }
					$scores[ $id ] = $query->cosine( $candidate['vector'] );
				}
			}
			// Release the previous page before issuing the next database read.
			unset( $rows, $batch, $stored, $candidate );
			if ( $count < $size || $cursor <= 0 ) {
				break; }
		}
		arsort( $scores );
		return array_slice( $scores, 0, min( 100, max( 1, $limit ) ), true );
	}

	/** Register maintenance independently of AI; existing valid vectors need no provider work. */
	public function register( Queue $queue ): void {
		$queue->handle( 'vector_encode', fn( array $data ) => $this->encodeBatch( (int) ( $data['cursor'] ?? 0 ), (string) ( $data['run'] ?? '' ), $queue ) );
		$run = get_option( 'fi_vector_cache_upgrade', '' );
		if ( is_string( $run ) && preg_match( '/^[a-f0-9]{16}$/D', $run ) ) {
			$queue->enqueue(
				'vector_encode',
				array(
					'cursor' => 0,
					'run'    => $run,
				),
				$run . ':0'
			);
			delete_option( 'fi_vector_cache_upgrade' );
		}
	}
	/** Bounded upgrade with exact-byte compare-and-swap; retrieval remains read-only. */
	public function encodeBatch( int $cursor, string $run, Queue $queue ): void {
		if ( $cursor < 0 || ! preg_match( '/^[a-f0-9]{16}$/D', $run ) ) {
			throw new \InvalidArgumentException( 'Invalid vector upgrade cursor' );
		}
		$table = $this->store->table( 'chunks' );
		$model = (string) ( get_option( 'fi_settings', array() )['embedding_model'] ?? 'text-embedding-3-small' );
		$rows  = $this->store->rows( "SELECT source_id,vector,vector_binary,content_hash,model,visibility FROM $table WHERE source_id>%d AND vector<>'[]' ORDER BY source_id LIMIT 50", array( $cursor ) );
		foreach ( $rows as $row ) {
			$id = (int) $row['source_id'];
			if ( $row['visibility'] !== 'public' || $row['model'] !== $model || $row['content_hash'] !== Indexer::hash( $id ) ) {
				continue;
			}
			if ( is_string( $row['vector_binary'] ) && PreparedVector::fromFrame( $row['vector_binary'], $row['vector'] ) ) {
				continue;
			}
			$frame = PreparedVector::encode( $row['vector'] );
			if ( $frame === null ) {
				continue;
			}
			$this->store->execute( "UPDATE $table SET vector_binary=UNHEX(%s) WHERE source_id=%d AND content_hash=%s AND BINARY model=BINARY %s AND BINARY vector=BINARY %s AND visibility='public'", array( bin2hex( $frame ), $id, $row['content_hash'], $model, $row['vector'] ) );
		}
		if ( count( $rows ) === 50 ) {
			$next = (int) end( $rows )['source_id'];
			$queue->enqueue(
				'vector_encode',
				array(
					'cursor' => $next,
					'run'    => $run,
				),
				$run . ':' . $next
			);
		}
	}
}
