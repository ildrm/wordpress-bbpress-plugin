<?php
/**
 * Plugin Name: Forum Intelligence for bbPress
 * Description: Permission-aware solutions, search, knowledge and moderation.
 * Version: 0.1.0
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Requires Plugins: bbpress
 * Text Domain: forum-intelligence-for-bbpress
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/autoload.php';
register_activation_hook( __FILE__, array( ForumIntelligence\Bootstrap\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( ForumIntelligence\Bootstrap\Lifecycle::class, 'deactivate' ) );
add_action(
	'plugins_loaded',
	static function (): void {
		( new ForumIntelligence\Bootstrap\Plugin( __FILE__ ) )->register();
	},
	30
);
