<?php
/**
 * Plugin Name: Local Docker Dev Overrides
 * Description: Only loaded inside the local Docker stack (mounted from ./docker/mu-plugins).
 *              Keeps production-only plugins from hijacking the local site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Really Simple Security forces HTTPS + the production host; Jetpack tries to
// talk to WordPress.com. Neither works on http://localhost:8000.
add_filter(
	'option_active_plugins',
	static function ( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$disabled = array(
			'really-simple-ssl/rlrsssl-really-simple-ssl.php',
			'jetpack/jetpack.php',
		);

		return array_values( array_diff( $plugins, $disabled ) );
	}
);

// Never let the local copy send real e-mail.
add_filter( 'pre_wp_mail', '__return_false' );
