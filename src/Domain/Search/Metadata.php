<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Search;
final class Metadata {
	public static function text( int $source, bool $external = false ): string {
		$topic  = get_post_type( $source ) === bbp_get_reply_post_type() ? (int) bbp_get_reply_topic_id( $source ) : $source;
		$values = (array) get_post_meta( $topic, '_fi_fields', true );
		$parts  = array();
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( ( ! empty( $field['forums'] ) && ! in_array( (int) bbp_get_topic_forum_id( $topic ), $field['forums'], true ) ) || $field['visibility'] !== 'public' || empty( $field[ $external ? 'ai' : 'searchable' ] ) || ! array_key_exists( $field['key'], $values ) ) {
				continue; }
			$value   = $values[ $field['key'] ];
			$parts[] = $field['label'] . ': ' . ( is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : (string) $value );
		}
		return implode( "\n", $parts );
	}
	public static function matches( int $source, array $filters ): bool {
		if ( ! $filters ) {
			return true; }
		$topic       = get_post_type( $source ) === bbp_get_reply_post_type() ? (int) bbp_get_reply_topic_id( $source ) : $source;
		$values      = (array) get_post_meta( $topic, '_fi_fields', true );
		$definitions = array();
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( ( empty( $field['forums'] ) || in_array( (int) bbp_get_topic_forum_id( $topic ), $field['forums'], true ) ) && $field['visibility'] === 'public' && ! empty( $field['searchable'] ) ) {
				$definitions[ $field['key'] ] = $field; }
		}
		foreach ( $filters as $key => $expected ) {
			if ( ! isset( $definitions[ $key ] ) || ! is_scalar( $expected ) || ! isset( $values[ $key ] ) ) {
				return false; }
			$actual = (array) $values[ $key ];
			if ( ! in_array( (string) $expected, array_map( 'strval', $actual ), true ) ) {
				return false; }
		}
		return true;
	}
}
