<?php
/**
 * Plugin Name: OpenCart Linked Images
 * Description: Serves product images straight from the read-only Bella Collezione
 *              image store, generating any missing size into a separate writable
 *              folder. The image store itself is never written to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attachments imported from OpenCart carry `_oc_rel_path` meta holding the path
 * relative to the catalog root, e.g. "products/princesas/fantasia-mulan.jpg".
 * The originals are mounted read-only at uploads/oc-catalog, OpenCart's own
 * thumbnail cache at uploads/oc-cache, and anything we render ourselves goes to
 * uploads/oc-thumbs -- the only one of the three that is writable.
 */
const OC_IMG_ORIGINALS = 'oc-catalog';
const OC_IMG_CACHE     = 'oc-cache';
const OC_IMG_THUMBS    = 'oc-thumbs';

function oc_img_uploads(): array {
	static $dirs = null;
	if ( null === $dirs ) {
		$u    = wp_get_upload_dir();
		$dirs = array( 'basedir' => $u['basedir'], 'baseurl' => $u['baseurl'] );
	}
	return $dirs;
}

/** Relative path of an OpenCart-linked attachment, or '' for a normal one. */
function oc_img_rel_path( int $attachment_id ): string {
	$rel = get_post_meta( $attachment_id, '_oc_rel_path', true );
	return is_string( $rel ) ? ltrim( $rel, '/' ) : '';
}

/** Target dimensions + crop flag for a registered size name. */
function oc_img_size_spec( $size ): ?array {
	if ( is_array( $size ) ) {
		return array( (int) $size[0], (int) $size[1], false );
	}

	$additional = wp_get_additional_image_sizes();
	if ( isset( $additional[ $size ] ) ) {
		return array(
			(int) $additional[ $size ]['width'],
			(int) $additional[ $size ]['height'],
			(bool) $additional[ $size ]['crop'],
		);
	}

	if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
		return array(
			(int) get_option( $size . '_size_w' ),
			(int) get_option( $size . '_size_h' ),
			(bool) get_option( $size . '_crop' ),
		);
	}

	return null;
}

/**
 * Resolve a requested size to a real file, generating it if need be.
 * Returns array( url, width, height ) or null to fall back to the original.
 */
function oc_img_resolve( string $rel, int $w, int $h, bool $crop ): ?array {
	$dirs = oc_img_uploads();
	$ext  = pathinfo( $rel, PATHINFO_EXTENSION );
	$stem = substr( $rel, 0, - ( strlen( $ext ) + 1 ) );

	// 1. OpenCart's own cache, which uses "-WxH" and "-WxHh" suffixes. It only
	//    covers a small slice of the catalog, so treat a hit as a bonus.
	foreach ( array( "-{$w}x{$h}h", "-{$w}x{$h}" ) as $suffix ) {
		$candidate = OC_IMG_CACHE . '/' . $stem . $suffix . '.' . $ext;
		if ( is_readable( $dirs['basedir'] . '/' . $candidate ) ) {
			return array( $dirs['baseurl'] . '/' . $candidate, $w, $h );
		}
	}

	// 2. Something we rendered on an earlier request.
	$mine = OC_IMG_THUMBS . '/' . $stem . "-{$w}x{$h}" . ( $crop ? 'c' : '' ) . '.' . $ext;
	$path = $dirs['basedir'] . '/' . $mine;
	if ( is_readable( $path ) ) {
		$actual = @getimagesize( $path );
		return array( $dirs['baseurl'] . '/' . $mine, $actual ? $actual[0] : $w, $actual ? $actual[1] : $h );
	}

	// 3. Render it once, into oc-thumbs and nowhere else.
	$source = $dirs['basedir'] . '/' . OC_IMG_ORIGINALS . '/' . $rel;
	if ( ! is_readable( $source ) ) {
		return null;
	}

	$thumbs_root = wp_normalize_path( $dirs['basedir'] . '/' . OC_IMG_THUMBS ) . '/';
	if ( 0 !== strpos( wp_normalize_path( $path ), $thumbs_root ) ) {
		return null; // Never write outside oc-thumbs.
	}

	$editor = wp_get_image_editor( $source );
	if ( is_wp_error( $editor ) ) {
		return null;
	}

	$editor->resize( $w, $h, $crop );
	if ( ! wp_mkdir_p( dirname( $path ) ) ) {
		return null;
	}

	$saved = $editor->save( $path );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return null;
	}

	return array( $dirs['baseurl'] . '/' . $mine, (int) $saved['width'], (int) $saved['height'] );
}

add_filter(
	'image_downsize',
	static function ( $out, $id, $size ) {
		$rel = oc_img_rel_path( (int) $id );
		if ( '' === $rel ) {
			return $out;
		}

		$dirs   = oc_img_uploads();
		$meta   = wp_get_attachment_metadata( $id );
		$full_w = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$full_h = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$full   = array( $dirs['baseurl'] . '/' . OC_IMG_ORIGINALS . '/' . $rel, $full_w, $full_h, false );

		if ( 'full' === $size ) {
			return $full;
		}

		$spec = oc_img_size_spec( $size );
		if ( null === $spec ) {
			return $full;
		}

		list( $w, $h, $crop ) = $spec;
		if ( $w < 1 && $h < 1 ) {
			return $full;
		}

		// Never upscale: an original smaller than the request is served as-is.
		if ( $full_w && $full_h && $w >= $full_w && $h >= $full_h ) {
			return $full;
		}

		$resolved = oc_img_resolve( $rel, $w, $h, $crop );
		if ( null === $resolved ) {
			return $full;
		}

		return array( $resolved[0], $resolved[1], $resolved[2], true );
	},
	10,
	3
);

// Metadata for these attachments has an empty `sizes` list, so core would build
// srcset entries that do not exist. Suppress it rather than serve 404s.
add_filter(
	'wp_calculate_image_srcset',
	static function ( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		return '' === oc_img_rel_path( (int) $attachment_id ) ? $sources : false;
	},
	10,
	5
);
