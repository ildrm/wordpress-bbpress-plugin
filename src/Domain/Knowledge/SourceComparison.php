<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
/** Bounded native hashes and revision pointers; no historical source body is copied. */
final class SourceComparison {
	public function __construct( private Store $store, private Access $access ) {}
	public static function textHash( \WP_Post $post ): string {
		$text = '';
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' )as $field ) {
			$value = (string) $post->$field;
			$text .= strlen( $value ) . ':' . $value;}
		return hash( 'sha256', $text );
	}
	public function changes( array $recorded, array $plan, int $user ): array {
		$old             = $recorded['sources'];
		$oldText         = is_array( $recorded['source_text_hashes'] ?? null ) ? $recorded['source_text_hashes'] : array();
		$items           = array();
		$total           = 0;
		$revisionQueries = 0;
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $plan['hashes'] ) ) )as $source ) {
			$baseline = $oldText[ $source ] ?? null;
			$valid    = is_string( $baseline ) && preg_match( '/^[a-f0-9]{64}$/D', $baseline );
			if ( isset( $old[ $source ], $plan['hashes'][ $source ] ) && hash_equals( $old[ $source ], $plan['hashes'][ $source ] ) && ( ! $valid || hash_equals( $baseline, $plan['text_hashes'][ $source ] ) ) ) {
				continue;}
			++$total;
			if ( count( $items ) >= 20 ) {
				continue;}
			$this->access->read( (int) $source, $user );
			$kind      = ! isset( $old[ $source ] ) ? 'added' : ( ! isset( $plan['hashes'][ $source ] ) ? 'left_window' : ( ! $valid ? 'changed' : ( hash_equals( $baseline, $plan['text_hashes'][ $source ] ) ? 'metadata_changed' : 'text_changed' ) ) );
			$url       = null;
			$available = false;
			if ( $kind === 'text_changed' && $revisionQueries < 10 && $this->canCompare( (int) $source, $user ) ) {
				++$revisionQueries;
				$pair = $this->revisions( (int) $source, $baseline, $plan['text_hashes'][ $source ] );
				if ( $pair ) {
					$available = true;
					$url       = add_query_arg(
						array(
							'revision' => $pair['current'],
							'from'     => $pair['baseline'],
						),
						admin_url( 'revision.php' )
					);}
			}
			$items[] = array(
				'source_id'          => (int) $source,
				'kind'               => $kind,
				'source_url'         => get_permalink( $source ),
				'baseline_available' => $available,
				'revision_url'       => $url,
			);
		}
		$solutionChanged = is_int( $recorded['solution_version'] ?? null ) && $recorded['solution_version'] !== $plan['solution_version'];
		return array(
			'changes'          => $items,
			'remaining'        => max( 0, $total - count( $items ) ),
			'solution_changed' => $solutionChanged,
			'outside_window'   => $total === 0 && ! $solutionChanged && (int) ( $recorded['discussion_revision'] ?? 0 ) !== $plan['revision'],
		);
	}
	private function canCompare( int $source, int $user ): bool {
		if ( ! user_can( $user, 'edit_post', $source ) ) {
			return false;
		}
		$post = get_post( $source );
		return $post && wp_revisions_enabled( $post );
	}
	private function revisions( int $source, string $baseline, string $current ): ?array {
		$posts = $this->store->wordpressTable( 'posts' );
		$rows  = $this->store->rows( "SELECT ID,SHA2(CONCAT(LENGTH(post_title),':',post_title,LENGTH(post_content),':',post_content,LENGTH(post_excerpt),':',post_excerpt),256) AS text_hash FROM (SELECT ID,post_title,post_content,post_excerpt FROM $posts WHERE post_parent=%d AND post_type='revision' AND post_status='inherit' AND post_name<>%s ORDER BY ID DESC LIMIT 20) AS bounded_revisions", array( $source, $source . '-autosave-v1' ) );
		$from  = 0;
		$to    = 0;
		foreach ( $rows as $row ) {
			$textHash = $row['text_hash'] ?? null;
			if ( ! is_string( $textHash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $textHash ) ) {
				continue;
			}
			if ( ! $from && hash_equals( $baseline, $textHash ) ) {
				$from = (int) $row['ID'];}
			if ( ! $to && hash_equals( $current, $textHash ) ) {
				$to = (int) $row['ID'];}
		}
		return $from && $to && $from !== $to ? array(
			'baseline' => $from,
			'current'  => $to,
		) : null;
	}
}
