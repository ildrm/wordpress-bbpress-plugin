<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Search;
use ForumIntelligence\Domain\Failure;
/** Publication-day boundaries are inclusive UTC dates, shared by native/local filters. */
final class DateRange {
	public static function validate( array $filters ): array {
		$range = array();
		foreach ( array( 'after', 'before' ) as $key ) {
			$value = $filters[ $key ] ?? '';
			if ( $value === '' ) {
				continue;}
			if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				throw new Failure( 'invalid_date', __( 'Use a valid publication date in YYYY-MM-DD format.', 'forum-intelligence-for-bbpress' ) );}
			$range[ $key ] = $value;
		}
		if ( isset( $range['after'], $range['before'] ) && strcmp( $range['after'], $range['before'] ) > 0 ) {
			throw new Failure( 'invalid_date_range', __( 'The start date must be on or before the end date.', 'forum-intelligence-for-bbpress' ) );}
		return $range;
	}
}
