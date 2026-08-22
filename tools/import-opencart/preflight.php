<?php
/**
 * Read-only checks to run before importing into a live site. Changes nothing.
 */

$oc_argv = $args ?? ( $oc_args ?? array() );
$fail    = 0;
$warn    = 0;

$check = function ( string $label, bool $ok, string $detail = '', bool $fatal = true ) use ( &$fail, &$warn ) {
	if ( $ok ) {
		printf( "  OK    %-34s %s\n", $label, $detail );
		return;
	}
	if ( $fatal ) {
		$fail++;
		printf( "  FAIL  %-34s %s\n", $label, $detail );
	} else {
		$warn++;
		printf( "  WARN  %-34s %s\n", $label, $detail );
	}
};

echo "\n== WordPress / WooCommerce ==\n";
$check( 'WordPress', defined( 'ABSPATH' ), get_bloginfo( 'version' ) . ' at ' . ABSPATH );
$check( 'WooCommerce active', function_exists( 'WC' ), function_exists( 'WC' ) ? WC()->version : 'not loaded' );
$check( 'product_brand taxonomy', taxonomy_exists( 'product_brand' ), '', false );
$check( 'site url', true, home_url() );
$check(
	'existing products',
	true,
	sprintf( '%d published / %d draft', (int) wp_count_posts( 'product' )->publish, (int) wp_count_posts( 'product' )->draft ),
	false
);
$already = (int) get_option( 'oc_import_last_product_id', 0 );
$check( 'previous import state', true, $already ? "resumes after OpenCart id {$already}" : 'none (fresh import)', false );

echo "\n== OpenCart database ==\n";
$oc_name = getenv( 'OC_DB_NAME' ) ?: 'bella_oc';
$oc      = new wpdb(
	getenv( 'OC_DB_USER' ) ?: DB_USER,
	getenv( 'OC_DB_PASSWORD' ) ?: DB_PASSWORD,
	$oc_name,
	getenv( 'OC_DB_HOST' ) ?: DB_HOST
);
$oc->suppress_errors( true );
$reachable = (bool) $oc->get_var( 'SELECT 1' );
$check( 'connection', $reachable, $oc_name . ' @ ' . ( getenv( 'OC_DB_HOST' ) ?: DB_HOST ) );

if ( $reachable ) {
	foreach ( array( 'oc_product', 'oc_product_description', 'oc_category', 'oc_category_description', 'oc_product_to_category', 'oc_product_image', 'oc_product_option', 'oc_product_option_value', 'oc_option_description', 'oc_option_value_description', 'oc_manufacturer', 'oc_seo_url' ) as $table ) {
		$count = $oc->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$check( $table, null !== $count, null !== $count ? number_format( (int) $count ) . ' rows' : 'missing' );
	}
	$langs   = $oc->get_results( 'SELECT language_id, name, code FROM oc_language ORDER BY language_id' );
	$names   = array();
	$has_ptbr = false;
	foreach ( (array) $langs as $lang ) {
		$names[] = $lang->language_id . '=' . $lang->name;
		if ( 2 === (int) $lang->language_id ) {
			$has_ptbr = true;
		}
	}
	$check( 'language_id 2 (import source)', $has_ptbr, implode( ', ', $names ) );
}

echo "\n== Images ==\n";
$uploads = wp_get_upload_dir();
$catalog = $uploads['basedir'] . '/oc-catalog';
$thumbs  = $uploads['basedir'] . '/oc-thumbs';
$check( 'uploads basedir', is_dir( $uploads['basedir'] ), $uploads['basedir'] );
$check( 'oc-catalog present', is_dir( $catalog ), is_link( $catalog ) ? 'symlink -> ' . readlink( $catalog ) : $catalog );

$sample_rel = '';
if ( $reachable ) {
	$sample_rel = (string) $oc->get_var( "SELECT image FROM oc_product WHERE image <> '' AND image IS NOT NULL LIMIT 1" );
}
if ( $sample_rel ) {
	$rel  = preg_replace( '#^catalog/#', '', $sample_rel );
	$abs  = $catalog . '/' . $rel;
	$ok   = is_readable( $abs );
	$check( 'sample image readable', $ok, $rel );
	if ( $ok ) {
		$size = @getimagesize( $abs );
		$check( 'sample image decodable', (bool) $size, $size ? $size[0] . 'x' . $size[1] : 'getimagesize failed' );

		// The real question on a live server: does the web server actually serve a
		// file through the symlink? A 403 here means Apache is refusing to follow
		// it. Distinguish that from "this host cannot reach its own site URL",
		// which is an environment quirk (containers, split-horizon DNS) rather
		// than a reason to stop.
		$url      = $uploads['baseurl'] . '/oc-catalog/' . $rel;
		$response = wp_remote_head( $url, array( 'timeout' => 15, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			$check(
				'sample image over HTTP',
				false,
				'could not connect (' . $response->get_error_message() . ') -- open this URL in a browser to confirm: ' . $url,
				false
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$hint = '';
			if ( 403 === $code ) {
				$hint = ' -- Apache is refusing to follow the symlink; add "Options +FollowSymLinks"';
			} elseif ( 404 === $code ) {
				$hint = ' -- the symlink does not resolve to the OpenCart image directory';
			}
			$check( 'sample image over HTTP', 200 === $code, $code . '  ' . $url . $hint );
		}
	}
}

$check( 'oc-cache (optional)', is_dir( $uploads['basedir'] . '/oc-cache' ), 'OpenCart thumbnail cache', false );

if ( ! is_dir( $thumbs ) ) {
	$check( 'oc-thumbs writable', wp_mkdir_p( $thumbs ), 'created ' . $thumbs, true );
} else {
	$probe = $thumbs . '/.preflight';
	$check( 'oc-thumbs writable', (bool) @file_put_contents( $probe, 'x' ), $thumbs );
	@unlink( $probe );
}

$mu = WPMU_PLUGIN_DIR . '/01-opencart-images.php';
$check( 'image mu-plugin installed', file_exists( $mu ), $mu );
$check( 'image_downsize hook active', (bool) has_filter( 'image_downsize' ), '', false );

echo "\n== PHP ==\n";
$check( 'php version', version_compare( PHP_VERSION, '7.4', '>=' ), PHP_VERSION );
$check( 'GD or Imagick', extension_loaded( 'gd' ) || extension_loaded( 'imagick' ), 'needed to render thumbnails' );
$check( 'memory_limit', true, ini_get( 'memory_limit' ), false );
$free = @disk_free_space( $uploads['basedir'] );
$check( 'free disk', $free > 512 * 1024 * 1024, $free ? size_format( $free ) : 'unknown', false );

printf( "\n%s  %d failed, %d warnings\n\n", $fail ? 'NOT READY' : 'READY', $fail, $warn );
if ( $fail ) {
	exit( 1 );
}
