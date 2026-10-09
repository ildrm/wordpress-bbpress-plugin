<?php
declare(strict_types=1);
namespace ForumIntelligence\Integration\BuddyPress;
/** Replace the verified legacy profile bridge without changing either plugin's files. */
final class Compatibility {
	public function register(): void {
		if ( ! function_exists( 'bp_members_get_user_url' ) || ! class_exists( 'BBP_BuddyPress_Members' ) || version_compare( bbp_get_version(), '2.6.19', '>' ) ) {
			return; }
		global $wp_filter;
		$sections = array(
			'bbp_pre_get_user_profile_url'         => '',
			'bbp_pre_get_user_topics_created_url'  => bbp_get_topic_archive_slug(),
			'bbp_pre_get_user_replies_created_url' => bbp_get_reply_archive_slug(),
			'bbp_pre_get_user_engagements_url'     => bbp_get_user_engagements_slug(),
			'bbp_pre_get_favorites_permalink'      => bbp_get_user_favorites_slug(),
			'bbp_pre_get_subscriptions_permalink'  => bbp_get_user_subscriptions_slug(),
		);
		foreach ( $sections as $hook => $section ) {
			foreach ( ( $wp_filter[ $hook ]->callbacks ?? array() ) as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					$callback = $entry['function'];
					if ( ! is_array( $callback ) || ! ( $callback[0] instanceof \BBP_BuddyPress_Members ) ) {
						continue; }
					remove_filter( $hook, $callback, $priority );
					add_filter(
						$hook,
						static function ( $user ) use ( $section ) {
							if ( ! is_numeric( $user ) || ! (int) $user || ! bp_is_root_blog() ) {
								return $user; }
							$parts = $section ? array(
								'single_item_component' => bbpress()->extend->buddypress->slug,
								'single_item_action'    => $section,
							) : array();
							return bp_members_get_user_url( (int) $user, $parts );
						},
						$priority
					);
				}
			}
		}
	}
}
