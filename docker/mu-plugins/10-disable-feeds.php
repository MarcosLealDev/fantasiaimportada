<?php
/**
 * Plugin Name: Disable RSS/Atom feeds
 * Description: Refuses every feed endpoint and stops advertising them. Mounted
 *              read-only into wp-content/mu-plugins by docker-compose.prod.yml.
 *
 * WHY
 *
 * WordPress generates a feed for EVERY product, category and tag. On a
 * 6,900-product WooCommerce catalog that is roughly 7,000 extra crawlable URLs,
 * each one rendering a full query through PHP -- and this site runs on a shared
 * single-vCPU droplet where shop pages already cost seconds.
 *
 * Nothing here consumes them: no active plugin reads a feed, MailPoet and
 * Google Listings & Ads are both inactive (their tables are leftovers), and a
 * costume shop has no RSS subscribers. They exist only as crawler bait.
 *
 * 410 Gone rather than 404: it tells a crawler the URL is deliberately retired
 * and not to come back, which is exactly the message to send here. Both are
 * cheap, but 410 is the one that reduces future crawling.
 *
 * TO RE-ENABLE: remove the bind mount from docker-compose.prod.yml. Nothing in
 * the database changes, so it is reversible with no data migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'template_redirect',
	static function () {
		if ( ! is_feed() ) {
			return;
		}

		// Let a logged-in editor still fetch one, so this cannot silently break
		// an admin-side workflow that turns out to depend on it.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}

		status_header( 410 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		nocache_headers();
		echo "Feeds are disabled on this site.\n";
		exit;
	},
	0
);

// Stop advertising feeds in <head> and in HTTP Link headers, so nothing
// discovers URLs that now answer 410.
add_action(
	'init',
	static function () {
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'rsd_link' );
	}
);

add_filter( 'feed_links_show_posts_feed', '__return_false' );
add_filter( 'feed_links_show_comments_feed', '__return_false' );
