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

/** Target dimensions for a registered size name. Crop is intentionally ignored -- see oc_img_resolve(). */
function oc_img_size_spec( $size ): ?array {
	if ( is_array( $size ) ) {
		return array( (int) $size[0], (int) $size[1] );
	}

	$additional = wp_get_additional_image_sizes();
	if ( isset( $additional[ $size ] ) ) {
		return array( (int) $additional[ $size ]['width'], (int) $additional[ $size ]['height'] );
	}

	if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
		return array( (int) get_option( $size . '_size_w' ), (int) get_option( $size . '_size_h' ) );
	}

	return null;
}

/**
 * OpenCart's cache renditions are always square (it pads to a square with
 * white space rather than cropping -- confirmed by sampling pixels), so any
 * rendition for this image is visually interchangeable with any other. Pick
 * the smallest one at least as wide as requested, or the largest available
 * if none is big enough, and return its real path/width/height.
 */
function oc_img_cache_hit( string $rel, int $w ): ?array {
	$dirs = oc_img_uploads();
	$ext  = pathinfo( $rel, PATHINFO_EXTENSION );
	$stem = substr( $rel, 0, - ( strlen( $ext ) + 1 ) );

	$dir   = $dirs['basedir'] . '/' . OC_IMG_CACHE . '/' . dirname( $stem );
	$base  = basename( $stem );
	// Slugs from the importer don't contain glob metacharacters (* ? [ ]), so a
	// plain match is safe here.
	$files = glob( $dir . '/' . $base . '-*.' . $ext, GLOB_NOSORT ) ?: array();

	$candidates = array();
	foreach ( $files as $file ) {
		if ( preg_match( '/-(\d+)x(\d+)[a-z]?\.' . preg_quote( $ext, '/' ) . '$/i', $file, $m ) ) {
			$candidates[ (int) $m[1] ] = $file;
		}
	}
	if ( ! $candidates ) {
		return null;
	}

	ksort( $candidates );
	$chosen = null;
	foreach ( $candidates as $width => $path ) {
		if ( $width >= $w ) {
			$chosen = $path;
			break;
		}
	}
	if ( null === $chosen ) {
		// Largest available is still smaller than requested -- only worth using
		// if it won't need blowing up too far (a cart-icon-sized 40px cache hit
		// serving a 500px grid slot would be worse than just rendering fresh).
		end( $candidates );
		$largest_w = key( $candidates );
		if ( $largest_w * 2 >= $w ) {
			$chosen = current( $candidates );
		}
	}
	if ( null === $chosen ) {
		return null;
	}

	$actual = @getimagesize( $chosen );
	if ( ! $actual ) {
		return null;
	}

	$rel_url = ltrim( substr( $chosen, strlen( $dirs['basedir'] ) ), '/' );
	return array( $dirs['baseurl'] . '/' . $rel_url, (int) $actual[0], (int) $actual[1] );
}

/**
 * Resolve a requested size to a real file, generating it if need be.
 * Never crops -- always fits within the box, preserving aspect ratio, so a
 * mixed-shape catalog (most of it is not square) is never cut. The theme is
 * responsible for the visual letterbox (see the wp_head style below).
 * Returns array( url, width, height ) or null to fall back to the original.
 */
function oc_img_resolve( string $rel, int $w, int $h ): ?array {
	$hit = oc_img_cache_hit( $rel, $w );
	if ( null !== $hit ) {
		return $hit;
	}

	$dirs = oc_img_uploads();
	$ext  = pathinfo( $rel, PATHINFO_EXTENSION );
	$stem = substr( $rel, 0, - ( strlen( $ext ) + 1 ) );

	// Something we rendered on an earlier request.
	$mine = OC_IMG_THUMBS . '/' . $stem . "-{$w}x{$h}." . $ext;
	$path = $dirs['basedir'] . '/' . $mine;
	if ( is_readable( $path ) ) {
		$actual = @getimagesize( $path );
		return array( $dirs['baseurl'] . '/' . $mine, $actual ? $actual[0] : $w, $actual ? $actual[1] : $h );
	}

	// Render it once, into oc-thumbs and nowhere else.
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

	$editor->resize( $w, $h, false ); // never crop
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

		list( $w, $h ) = $spec;
		if ( $w < 1 && $h < 1 ) {
			return $full;
		}

		// Never upscale. A dimension of 0 means "unconstrained" (proportional
		// scaling by the other axis), so it always counts as already satisfied.
		if ( $full_w && $full_h ) {
			$width_fits  = ( $w < 1 ) || ( $full_w <= $w );
			$height_fits = ( $h < 1 ) || ( $full_h <= $h );
			if ( $width_fits && $height_fits ) {
				return $full;
			}
		}

		$resolved = oc_img_resolve( $rel, $w, $h );
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

// The image files are never cropped (see oc_img_resolve()); this is what turns
// that into a uniform, letterboxed grid instead of stretched/odd-shaped cards.
// Scoped to WooCommerce product loops, so it doesn't affect images elsewhere.
add_action(
	'wp_head',
	static function () {
		echo '<style>ul.products li.product .ct-media-container{--theme-object-fit:contain;}</style>' . "\n";
	}
);
