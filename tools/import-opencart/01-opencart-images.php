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

/**
 * Widths OpenCart has actually rendered into its cache, ascending.
 *
 * These exist so a lookup can be a bounded run of stat() calls instead of a
 * glob(). glob() has to read the whole directory, and the cache is not shallow:
 * products/masculino alone holds 70,666 files, so one call cost ~80 ms and the
 * cost grew with the neighbouring store's catalog. Nothing memoised it either,
 * so a product page paid that per image *per registered size* -- measured at 89 s
 * for a single product page, and past the 120 s limit for the largest galleries.
 *
 * Sampled against the live tree (233,270 renditions): 98% are square and every
 * one carries one of the three suffixes below, so probing "<width>x<width><suf>"
 * finds what glob() found for 99.7% of lookups. The remaining 0.3% fall through
 * to the renderer below and come out correct anyway, just rendered once.
 */
const OC_IMG_CACHE_WIDTHS = array(
	40, 60, 70, 80, 100, 120, 140, 150, 160, 190, 240, 250, 300, 333, 350, 353,
	375, 380, 400, 480, 499, 500, 550, 600, 679, 800, 896, 900, 999, 1000, 1001,
	1050, 1100, 1125, 1200, 1280, 1500, 1600, 1800, 2560,
);

/** Resize-mode markers OpenCart appends; '' (plain), 'h' and 'w' all pad to a square. */
const OC_IMG_CACHE_SUFFIXES = array( '', 'h', 'w' );

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

/** First existing cache rendition at exactly this width, trying each resize-mode marker. */
function oc_img_probe( string $dir, string $base, string $ext, int $width ): ?string {
	foreach ( OC_IMG_CACHE_SUFFIXES as $suffix ) {
		$path = $dir . '/' . $base . '-' . $width . 'x' . $width . $suffix . '.' . $ext;
		if ( is_readable( $path ) ) {
			return $path;
		}
	}
	return null;
}

/**
 * OpenCart's cache renditions are always square (it pads to a square with
 * white space rather than cropping -- confirmed by sampling edge pixels), so any
 * rendition for this image is visually interchangeable with any other. Pick
 * the smallest one at least as wide as requested, or the largest available
 * if none is big enough, and return its real path/width/height.
 */
function oc_img_cache_hit( string $rel, int $w ): ?array {
	// One page render asks for the same image at the same size many times over
	// (the loop, the gallery, srcset, structured data). Resolve each once.
	static $memo = array();
	$key = $rel . '|' . $w;
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}

	$dirs = oc_img_uploads();
	$ext  = pathinfo( $rel, PATHINFO_EXTENSION );
	$stem = substr( $rel, 0, - ( strlen( $ext ) + 1 ) );
	$dir  = $dirs['basedir'] . '/' . OC_IMG_CACHE . '/' . dirname( $stem );
	$base = basename( $stem );

	$chosen = null;
	foreach ( OC_IMG_CACHE_WIDTHS as $cw ) {
		if ( $cw < $w ) {
			continue;
		}
		$chosen = oc_img_probe( $dir, $base, $ext, $cw );
		if ( null !== $chosen ) {
			break;
		}
	}

	if ( null === $chosen ) {
		// Largest available is still smaller than requested -- only worth using
		// if it won't need blowing up too far (a cart-icon-sized 40px cache hit
		// serving a 500px grid slot would be worse than just rendering fresh).
		for ( $i = count( OC_IMG_CACHE_WIDTHS ) - 1; $i >= 0; $i-- ) {
			$cw = OC_IMG_CACHE_WIDTHS[ $i ];
			if ( $cw >= $w || $cw * 2 < $w ) {
				continue;
			}
			$chosen = oc_img_probe( $dir, $base, $ext, $cw );
			if ( null !== $chosen ) {
				break;
			}
		}
	}

	if ( null === $chosen ) {
		return $memo[ $key ] = null;
	}

	$actual = @getimagesize( $chosen );
	if ( ! $actual ) {
		return $memo[ $key ] = null;
	}

	$rel_url = ltrim( substr( $chosen, strlen( $dirs['basedir'] ) ), '/' );
	return $memo[ $key ] = array( $dirs['baseurl'] . '/' . $rel_url, (int) $actual[0], (int) $actual[1] );
}

/**
 * Render a source image onto a square white canvas, scaled to fit.
 *
 * This is what makes our own renditions match the ones OpenCart already
 * produced for the same catalog. Blocksy gives product media a 1:1 container
 * and defaults to `object-fit: cover`, so a tall image -- and a fitted portrait
 * render is very tall, e.g. 379x1500 -- had its top and bottom cropped away,
 * which is how heads were being cut off. A square image cannot be cropped by a
 * square box.
 *
 * The content is scaled to *fill* the box, enlarging a small source if need be,
 * because that is what OpenCart does: sampled against the live cache, a 300x300
 * source occupies 97% of its 800x800 rendition. Fitting without enlarging would
 * leave small products floating in white next to cache-served neighbours that
 * fill their slot, which reads as a bug even though nothing is cropped.
 *
 * Done in one GD pass rather than via WP_Image_Editor because core's resize()
 * routes through wp_constrain_dimensions(), which refuses to enlarge -- and
 * because a single decode/resample/encode avoids compressing the JPEG twice.
 */
function oc_img_render_square( string $source, string $dest, int $box ): bool {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return false;
	}

	$info = @getimagesize( $source );
	if ( ! $info ) {
		return false;
	}

	list( $w, $h ) = $info;
	if ( $w < 1 || $h < 1 ) {
		return false;
	}

	// Guard against decoding something pathological into a 640 MB container.
	if ( $w * $h > 30000000 ) {
		return false;
	}

	switch ( $info[2] ) {
		case IMAGETYPE_JPEG:
			$src = @imagecreatefromjpeg( $source );
			break;
		case IMAGETYPE_PNG:
			$src = @imagecreatefrompng( $source );
			break;
		case IMAGETYPE_GIF:
			$src = @imagecreatefromgif( $source );
			break;
		case IMAGETYPE_WEBP:
			$src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $source ) : false;
			break;
		default:
			return false;
	}
	if ( ! $src ) {
		return false;
	}

	$scale = min( $box / $w, $box / $h );
	$new_w = max( 1, (int) round( $w * $scale ) );
	$new_h = max( 1, (int) round( $h * $scale ) );

	$canvas = imagecreatetruecolor( $box, $box );
	$white  = imagecolorallocate( $canvas, 255, 255, 255 );
	imagefilledrectangle( $canvas, 0, 0, $box, $box, $white );
	imagecopyresampled(
		$canvas, $src,
		intdiv( $box - $new_w, 2 ), intdiv( $box - $new_h, 2 ), 0, 0,
		$new_w, $new_h, $w, $h
	);
	imagedestroy( $src );

	switch ( $info[2] ) {
		case IMAGETYPE_PNG:
			$ok = imagepng( $canvas, $dest, 6 );
			break;
		case IMAGETYPE_GIF:
			$ok = imagegif( $canvas, $dest );
			break;
		case IMAGETYPE_WEBP:
			$ok = function_exists( 'imagewebp' ) ? imagewebp( $canvas, $dest ) : false;
			break;
		default:
			// 82 is core's default JPEG quality (see wp_get_image_editor()).
			$ok = imagejpeg( $canvas, $dest, 82 );
			break;
	}
	imagedestroy( $canvas );

	return (bool) $ok;
}

/**
 * Resolve a requested size to a real file, generating it if need be.
 * Never crops -- the image is fitted inside the box and the remainder padded
 * with white, exactly as OpenCart does, so every rendition on the site shares
 * one square geometry regardless of which of the three sources it came from.
 * Returns array( url, width, height ) or null to fall back to the original.
 */
function oc_img_resolve( string $rel, int $w, int $h ): ?array {
	// A width-only size (medium_large is 768x0) still resolves to a square box,
	// which is what keeps it consistent with the cache renditions.
	$box = max( $w, $h );
	if ( $box < 1 ) {
		return null;
	}

	$hit = oc_img_cache_hit( $rel, $box );
	if ( null !== $hit ) {
		return $hit;
	}

	$dirs = oc_img_uploads();
	$ext  = pathinfo( $rel, PATHINFO_EXTENSION );
	$stem = substr( $rel, 0, - ( strlen( $ext ) + 1 ) );

	// Something we rendered on an earlier request. The "sq" marker keeps these
	// apart from the fitted, non-square files written before this change --
	// those are the ones the 1:1 containers were cropping, and reusing them by
	// name would silently reintroduce the bug. oc-thumbs is disposable, so the
	// stale files simply age out; deleting them is optional housekeeping.
	$mine = OC_IMG_THUMBS . '/' . $stem . '-' . $box . 'x' . $box . 'sq.' . $ext;
	$path = $dirs['basedir'] . '/' . $mine;
	if ( is_readable( $path ) ) {
		return array( $dirs['baseurl'] . '/' . $mine, $box, $box );
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

	if ( ! wp_mkdir_p( dirname( $path ) ) ) {
		return null;
	}

	if ( ! oc_img_render_square( $source, $path, $box ) ) {
		return null;
	}

	return array( $dirs['baseurl'] . '/' . $mine, $box, $box );
}

add_filter(
	'image_downsize',
	static function ( $out, $id, $size ) {
		// Same image, same size, many times per page -- see oc_img_cache_hit().
		static $memo = array();
		$key = $id . '|' . ( is_array( $size ) ? implode( 'x', $size ) : (string) $size );
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		$rel = oc_img_rel_path( (int) $id );
		if ( '' === $rel ) {
			return $memo[ $key ] = $out;
		}

		$dirs   = oc_img_uploads();
		$meta   = wp_get_attachment_metadata( $id );
		$full_w = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$full_h = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$full   = array( $dirs['baseurl'] . '/' . OC_IMG_ORIGINALS . '/' . $rel, $full_w, $full_h, false );

		if ( 'full' === $size ) {
			return $memo[ $key ] = $full;
		}

		$spec = oc_img_size_spec( $size );
		if ( null === $spec ) {
			return $memo[ $key ] = $full;
		}

		list( $w, $h ) = $spec;
		if ( $w < 1 && $h < 1 ) {
			return $memo[ $key ] = $full;
		}

		// Note there is deliberately no "the original already fits, serve it as
		// is" shortcut here any more. It returned the untouched original, whose
		// aspect ratio is arbitrary, and that was the other half of the cropping
		// bug: a page mixed square renditions with tall originals in the same
		// 1:1 slots. A source smaller than the box is now scaled up to fill it,
		// which is what OpenCart does -- see oc_img_render_square().
		$resolved = oc_img_resolve( $rel, $w, $h );
		if ( null === $resolved ) {
			return $memo[ $key ] = $full;
		}

		return $memo[ $key ] = array( $resolved[0], $resolved[1], $resolved[2], true );
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

// Belt and braces. Every rendition this plugin resolves is now square, so a 1:1
// container has nothing left to crop -- but `full` is still the untouched
// original, and Blocksy defaults these containers to `object-fit: cover`.
// Scoped to WooCommerce product media so other imagery keeps the theme default.
add_action(
	'wp_head',
	static function () {
		echo '<style>'
			. 'ul.products li.product .ct-media-container,'
			. '.wc-block-grid__product .ct-media-container,'
			. '.woocommerce div.product .ct-media-container'
			. '{--theme-object-fit:contain;}'
			. '</style>' . "\n";
	}
);
