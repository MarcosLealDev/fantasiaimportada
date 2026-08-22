<?php
/**
 * Post-import housekeeping. Safe to re-run.
 *
 * Term counts are deferred during the import for speed, WooCommerce caches the
 * catalog aggressively, and thousands of new product/category slugs need
 * rewrite rules regenerated before their permalinks resolve.
 */

function oc_log_line( string $msg ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $msg );
	} else {
		echo $msg, PHP_EOL;
		flush();
	}
}

oc_log_line( 'recounting terms' );
foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		continue;
	}
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
	if ( is_wp_error( $terms ) || ! $terms ) {
		continue;
	}
	wp_update_term_count_now( $terms, $taxonomy );
	oc_log_line( sprintf( '  %s: %d terms', $taxonomy, count( $terms ) ) );
}

oc_log_line( 'clearing WooCommerce catalog caches' );
if ( class_exists( 'WC_Cache_Helper' ) ) {
	WC_Cache_Helper::get_transient_version( 'product', true );
}
if ( function_exists( 'wc_delete_product_transients' ) ) {
	wc_delete_product_transients();
}
delete_transient( 'wc_attribute_taxonomies' );
delete_transient( 'wc_term_counts' );
wp_cache_flush();

oc_log_line( 'flushing rewrite rules (new product and category permalinks)' );
flush_rewrite_rules( false );

$counts = wp_count_posts( 'product' );
oc_log_line(
	sprintf(
		'done -- %d published, %d draft products, %d variations, %d linked images',
		(int) $counts->publish,
		(int) $counts->draft,
		(int) wp_count_posts( 'product_variation' )->publish,
		(int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_oc_rel_path'" )
	)
);
