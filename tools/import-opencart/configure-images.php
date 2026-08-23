<?php
/**
 * Sets the WooCommerce/Blocksy image options so the catalog renders
 * letterboxed instead of cropped, and clears any thumbnails already
 * generated under the old (cropped) settings.
 *
 * Run through the wpcli service or oc-import-cli.php, no arguments:
 *   docker compose --profile tools run --rm wpcli eval-file /import/configure-images.php
 *
 * Idempotent -- re-running just confirms the same values.
 *
 * Background: this catalog is mixed-shape (most images are not square), but
 * the production database dump this site was seeded from carries Blocksy's
 * product-card settings tuned for a 3:4 crop, which cuts a quarter off a
 * square source and much more off a tall or wide one. The pair below is easy
 * to get wrong: `_cropping` controls the size WordPress *generates* (the
 * file), while `_cropping_custom_width/height` control the CSS box Blocksy
 * draws around it (blocksy_get_woocommerce_ratio() reads the custom_width/
 * height options directly, not the _cropping option, so both must be set or
 * only one half of the crop goes away). Both are set to "no crop, square
 * box" here, and 01-opencart-images.php's `object-fit: contain` rule does
 * the actual letterboxing.
 */

if ( ! function_exists( 'oc_log_line' ) ) {
	function oc_log_line( string $msg ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::log( $msg );
		} else {
			echo $msg, PHP_EOL;
			flush();
		}
	}
}

$settings = array(
	// Grid thumbnails (Blocksy's own "archive_thumbnail" size, used for
	// product cards). 500px is Blocksy's default and matches the most
	// common OpenCart cache size (500x500), so more cached files apply.
	'woocommerce_archive_thumbnail_image_width'            => 500,
	'woocommerce_archive_thumbnail_cropping'               => 'uncropped',
	'woocommerce_archive_thumbnail_cropping_custom_width'  => 1,
	'woocommerce_archive_thumbnail_cropping_custom_height' => 1,

	// Everywhere else WooCommerce uses "thumbnail" directly (cart, related
	// products, blocks) -- keep it consistent with the cards.
	'woocommerce_thumbnail_image_width'           => 500,
	'woocommerce_thumbnail_cropping'              => 'uncropped',
	'woocommerce_thumbnail_cropping_custom_width'  => 1,
	'woocommerce_thumbnail_cropping_custom_height' => 1,
);

foreach ( $settings as $option => $value ) {
	$before = get_option( $option );
	update_option( $option, $value );
	oc_log_line( sprintf( '%-48s %s -> %s', $option, var_export( $before, true ), var_export( $value, true ) ) );
}

// Every existing rendition was generated under the old cropped settings.
// oc-thumbs is disposable by design -- delete it and it regenerates on
// demand under the new ones.
$uploads = wp_get_upload_dir();
$thumbs  = $uploads['basedir'] . '/oc-thumbs';
$removed = 0;
if ( is_dir( $thumbs ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $thumbs, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $entry ) {
		if ( $entry->isDir() ) {
			@rmdir( $entry->getPathname() );
		} else {
			@unlink( $entry->getPathname() );
			$removed++;
		}
	}
}
oc_log_line( sprintf( 'cleared %d stale thumbnail(s) from %s', $removed, $thumbs ) );

if ( class_exists( 'WC_Cache_Helper' ) ) {
	WC_Cache_Helper::get_transient_version( 'product', true );
}
oc_log_line( 'done -- reload the shop page to see the new thumbnails render' );
