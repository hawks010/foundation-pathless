<?php
/**
 * WP-Background Processing Library Loader
 *
 * Lightweight async request + background process classes for WordPress.
 * Forked from Delicious Brains Inc. — https://github.com/deliciousbrains/wp-background-processing
 *
 * @package Foundation\Pathless\Vendor
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load classes if not already loaded.
 */
if ( ! class_exists( 'WP_Async_Request', false ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'classes/wp-async-request.php';
}

if ( ! class_exists( 'WP_Background_Process', false ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'classes/wp-background-process.php';
}
