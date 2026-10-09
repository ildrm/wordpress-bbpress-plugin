<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Push;
/** Development uses Composer directly; release packages require the isolated dependency namespace. */
final class Runtime {
	public static function className( string $name ): string {
		if ( ! in_array( $name, array( 'WebPush', 'Subscription', 'VAPID' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown push runtime class.' );}
		$prefix = is_file( dirname( __DIR__, 3 ) . '/vendor/fi-scoped.json' ) ? 'ForumIntelligenceVendor\\' : '';
		return $prefix . 'Minishlink\\WebPush\\' . $name;
	}
}
