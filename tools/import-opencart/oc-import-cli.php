<?php
/**
 * Standalone entrypoint for servers without WP-CLI.
 *
 *   php oc-import-cli.php --wp=/var/www/html categories
 *   php oc-import-cli.php --wp=/var/www/html products 100
 *   php oc-import-cli.php --wp=/var/www/html --script=preflight.php
 *
 * Bootstraps WordPress through wp-load.php and hands the positional arguments
 * to import.php, which behaves identically under WP-CLI.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "oc-import-cli.php is a command line tool.\n" );
}

$argv_in = $argv;
array_shift( $argv_in );

$wp_path = '';
$script  = 'import.php';
$oc_args = array();
foreach ( $argv_in as $arg ) {
	if ( 0 === strpos( $arg, '--wp=' ) ) {
		$wp_path = substr( $arg, 5 );
	} elseif ( 0 === strpos( $arg, '--script=' ) ) {
		$script = basename( substr( $arg, 9 ) );
	} else {
		$oc_args[] = $arg;
	}
}

if ( '' === $wp_path ) {
	$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 2 );
}

$wp_load = rtrim( $wp_path, '/' ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Error: wp-load.php not found at {$wp_load}. Pass --wp=/path/to/wordpress\n" );
	exit( 1 );
}

// Tells plugins (and WordPress itself) to skip work that only matters for
// interactive requests, and keeps a long import from being cut short.
define( 'WP_USE_THEMES', false );
define( 'WP_IMPORTING', true );
define( 'DOING_CRON', false );
ignore_user_abort( true );
set_time_limit( 0 );
ini_set( 'memory_limit', getenv( 'OC_MEMORY_LIMIT' ) ?: '1024M' );

require_once $wp_load;

if ( 'import.php' === $script && ! function_exists( 'WC' ) ) {
	fwrite( STDERR, "Error: WooCommerce is not active on this site.\n" );
	exit( 1 );
}

$script_path = __DIR__ . '/' . $script;
if ( ! is_readable( $script_path ) ) {
	fwrite( STDERR, "Error: no such script: {$script_path}\n" );
	exit( 1 );
}

require $script_path;
