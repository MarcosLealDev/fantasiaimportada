<?php
/**
 * Import the Bella Collezione OpenCart catalog into WooCommerce.
 *
 * Run through the wpcli service:
 *   docker compose --profile tools run --rm wpcli eval-file /import/import.php categories
 *   docker compose --profile tools run --rm wpcli eval-file /import/import.php brands
 *   docker compose --profile tools run --rm wpcli eval-file /import/import.php attributes
 *   docker compose --profile tools run --rm wpcli eval-file /import/import.php products [batch] [after_id]
 *
 * Every phase is idempotent: records are matched on the OpenCart id kept in
 * `_oc_product_id` / `_oc_category_id` / `_oc_manufacturer_id` meta, so a run can
 * be interrupted and restarted without creating duplicates.
 *
 * Images are never copied. Attachments point at the read-only oc-catalog mount
 * via `_wp_attached_file`, and `_oc_rel_path` tells the companion mu-plugin
 * (docker/mu-plugins/01-opencart-images.php) how to resolve resized versions.
 */

const OC_LANG          = 2;              // Português (BR)
const OC_LB_TO_KG      = 0.45359237;     // weight_class_id 5 is Pound; the store uses kg
const OC_IMAGE_PREFIX  = 'oc-catalog/';
const OC_MAX_VARIATIONS = 100;

// Keep the OpenCart HTML exactly as authored. Without this, wp-cli runs with no
// current user and kses would rewrite the size tables inside descriptions.
kses_remove_filters();
wp_defer_term_counting( true );
wp_defer_comment_counting( true );

/**
 * Positional arguments: `wp eval-file` provides $args, the standalone runner
 * (oc-import-cli.php) provides $oc_args.
 */
$oc_argv = $args ?? ( $oc_args ?? array() );
$phase   = $oc_argv[0] ?? '';

/**
 * OpenCart database. Defaults to the WordPress credentials, which is right when
 * both databases live on the same server and user; override per environment.
 */
$oc = new wpdb(
	getenv( 'OC_DB_USER' ) ?: DB_USER,
	getenv( 'OC_DB_PASSWORD' ) ?: DB_PASSWORD,
	getenv( 'OC_DB_NAME' ) ?: 'bella_oc',
	getenv( 'OC_DB_HOST' ) ?: DB_HOST
);
$oc->set_prefix( 'oc_' );
if ( ! empty( $oc->error ) || ! $oc->get_var( 'SELECT 1' ) ) {
	oc_abort( sprintf( 'cannot read the OpenCart database "%s"', getenv( 'OC_DB_NAME' ) ?: 'bella_oc' ) );
}

/**
 * Publish policy. "source" mirrors OpenCart's own status (default); "draft"
 * imports everything unpublished, which is the safe first pass on a live store.
 */
define( 'OC_IMPORT_STATUS', getenv( 'OC_IMPORT_STATUS' ) ?: 'source' );

function oc_log( string $msg ): void {
	$line = sprintf( '[%s] %s', gmdate( 'H:i:s' ), $msg );
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $line );
	} else {
		echo $line, PHP_EOL;
		flush();
	}
}

function oc_abort( string $msg ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $msg );
	}
	fwrite( STDERR, 'Error: ' . $msg . PHP_EOL );
	exit( 1 );
}

function oc_target_status( int $oc_status ): string {
	if ( 'draft' === OC_IMPORT_STATUS ) {
		return 'draft';
	}
	if ( 'publish' === OC_IMPORT_STATUS ) {
		return 'publish';
	}
	return 1 === $oc_status ? 'publish' : 'draft';
}

function oc_html( ?string $raw ): string {
	// OpenCart stores descriptions entity-escaped ("&lt;p&gt;"); one decode pass
	// turns them back into the HTML the old storefront rendered.
	return trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

function oc_text( ?string $raw ): string {
	return trim( wp_strip_all_tags( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/** slug for a product/category, taken from OpenCart's SEO urls. */
function oc_slug( wpdb $oc, string $query ): string {
	$keyword = $oc->get_var(
		$oc->prepare(
			"SELECT keyword FROM oc_seo_url WHERE query = %s AND language_id = %d ORDER BY seo_url_id LIMIT 1",
			$query,
			OC_LANG
		)
	);
	return $keyword ? sanitize_title( $keyword ) : '';
}

/* -------------------------------------------------------------------------
 * Attachments: one per distinct image path, linked not copied.
 * ---------------------------------------------------------------------- */

function oc_attachment_map(): array {
	global $wpdb;
	static $map = null;
	if ( null === $map ) {
		$map  = array();
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_oc_rel_path'" );
		foreach ( $rows as $row ) {
			$map[ $row->meta_value ] = (int) $row->post_id;
		}
	}
	return $map;
}

function oc_attachment_id( string $rel, string $title, int $parent = 0 ): int {
	static $map = null;
	if ( null === $map ) {
		$map = oc_attachment_map();
	}

	// OpenCart stores paths as "catalog/products/x.jpg", but the mount at
	// uploads/oc-catalog *is* that catalog directory, so drop the prefix.
	$rel = preg_replace( '#^catalog/#', '', ltrim( (string) $rel, '/' ) );
	if ( '' === $rel ) {
		return 0;
	}
	if ( isset( $map[ $rel ] ) ) {
		return $map[ $rel ];
	}

	$uploads = wp_get_upload_dir();
	$abs     = $uploads['basedir'] . '/' . OC_IMAGE_PREFIX . $rel;
	if ( ! is_readable( $abs ) ) {
		return 0;
	}

	$type = wp_check_filetype( $abs );
	if ( empty( $type['type'] ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => $title !== '' ? $title : pathinfo( $rel, PATHINFO_FILENAME ),
			'post_mime_type' => $type['type'],
			'post_status'    => 'inherit',
			'guid'           => $uploads['baseurl'] . '/' . OC_IMAGE_PREFIX . $rel,
		),
		$abs,
		$parent
	);

	if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
		return 0;
	}

	// Only the header is read -- no pixel data, no writes.
	$size = @getimagesize( $abs );
	wp_update_attachment_metadata(
		$attachment_id,
		array(
			'file'   => OC_IMAGE_PREFIX . $rel,
			'width'  => $size ? (int) $size[0] : 0,
			'height' => $size ? (int) $size[1] : 0,
			'sizes'  => array(), // resolved on demand by 01-opencart-images.php
		)
	);
	update_post_meta( $attachment_id, '_oc_rel_path', $rel );

	$map[ $rel ] = (int) $attachment_id;
	return (int) $attachment_id;
}

/* -------------------------------------------------------------------------
 * Phase: categories
 * ---------------------------------------------------------------------- */

function oc_phase_categories( wpdb $oc ): void {
	$rows = $oc->get_results(
		$oc->prepare(
			"SELECT c.category_id, c.parent_id, c.image, c.sort_order, cd.name, cd.description
			 FROM oc_category c
			 JOIN oc_category_description cd
			   ON cd.category_id = c.category_id AND cd.language_id = %d
			 ORDER BY c.parent_id, c.sort_order",
			OC_LANG
		)
	);
	oc_log( sprintf( 'categories: %d rows', count( $rows ) ) );

	// term_id by OpenCart category_id, so children can attach to real parents.
	$existing = array();
	foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
		$oc_id = (int) get_term_meta( $term_id, '_oc_category_id', true );
		if ( $oc_id ) {
			$existing[ $oc_id ] = (int) $term_id;
		}
	}

	// Parents before children: keep sweeping until nothing new resolves.
	$pending = array();
	foreach ( $rows as $row ) {
		$pending[ (int) $row->category_id ] = $row;
	}

	$created = 0;
	$updated = 0;
	do {
		$progress = false;
		foreach ( $pending as $oc_id => $row ) {
			$parent_oc = (int) $row->parent_id;
			if ( $parent_oc && ! isset( $existing[ $parent_oc ] ) ) {
				continue; // parent not imported yet
			}

			$parent_term = $parent_oc ? $existing[ $parent_oc ] : 0;
			$name        = oc_text( $row->name );
			$slug        = oc_slug( $oc, 'category_id=' . $oc_id );
			$description = oc_html( $row->description );

			if ( isset( $existing[ $oc_id ] ) ) {
				wp_update_term(
					$existing[ $oc_id ],
					'product_cat',
					array( 'name' => $name, 'description' => $description, 'parent' => $parent_term )
				);
				$term_id = $existing[ $oc_id ];
				$updated++;
			} else {
				$term = wp_insert_term(
					$name,
					'product_cat',
					array(
						'slug'        => $slug,
						'description' => $description,
						'parent'      => $parent_term,
					)
				);
				if ( is_wp_error( $term ) ) {
					// A name/slug clash with an existing term: reuse it.
					$conflict = $term->get_error_data();
					$term_id  = is_array( $conflict ) && isset( $conflict['term_id'] ) ? (int) $conflict['term_id'] : ( is_numeric( $conflict ) ? (int) $conflict : 0 );
					if ( ! $term_id ) {
						oc_log( sprintf( '  ! category %d (%s): %s', $oc_id, $name, $term->get_error_message() ) );
						unset( $pending[ $oc_id ] );
						$progress = true;
						continue;
					}
				} else {
					$term_id = (int) $term['term_id'];
				}
				$created++;
			}

			update_term_meta( $term_id, '_oc_category_id', $oc_id );
			update_term_meta( $term_id, 'order', (int) $row->sort_order );

			if ( ! empty( $row->image ) ) {
				$thumb = oc_attachment_id( $row->image, $name );
				if ( $thumb ) {
					update_term_meta( $term_id, 'thumbnail_id', $thumb );
				}
			}

			$existing[ $oc_id ] = $term_id;
			unset( $pending[ $oc_id ] );
			$progress = true;
		}
	} while ( $progress && $pending );

	if ( $pending ) {
		oc_log( sprintf( '  ! %d categories skipped (orphaned parent)', count( $pending ) ) );
	}
	oc_log( sprintf( 'categories done: %d created, %d updated', $created, $updated ) );
}

/* -------------------------------------------------------------------------
 * Phase: brands (product_brand is core in WooCommerce 9.6+)
 * ---------------------------------------------------------------------- */

function oc_phase_brands( wpdb $oc ): void {
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		oc_log( 'product_brand taxonomy missing - skipping brands' );
		return;
	}

	$rows    = $oc->get_results( 'SELECT manufacturer_id, name, image FROM oc_manufacturer ORDER BY manufacturer_id' );
	$created = 0;
	foreach ( $rows as $row ) {
		$name = oc_text( $row->name );
		if ( '' === $name ) {
			continue;
		}

		$term = get_terms(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => false,
				'number'     => 1,
				'meta_key'   => '_oc_manufacturer_id',
				'meta_value' => (int) $row->manufacturer_id,
			)
		);

		if ( ! empty( $term ) && ! is_wp_error( $term ) ) {
			$term_id = (int) $term[0]->term_id;
		} else {
			$inserted = wp_insert_term( $name, 'product_brand' );
			if ( is_wp_error( $inserted ) ) {
				$data    = $inserted->get_error_data();
				$term_id = is_array( $data ) && isset( $data['term_id'] ) ? (int) $data['term_id'] : 0;
				if ( ! $term_id ) {
					continue;
				}
			} else {
				$term_id = (int) $inserted['term_id'];
				$created++;
			}
		}

		update_term_meta( $term_id, '_oc_manufacturer_id', (int) $row->manufacturer_id );
		if ( ! empty( $row->image ) ) {
			$thumb = oc_attachment_id( $row->image, $name );
			if ( $thumb ) {
				update_term_meta( $term_id, 'thumbnail_id', $thumb );
			}
		}
	}
	oc_log( sprintf( 'brands done: %d created of %d', $created, count( $rows ) ) );
}

/* -------------------------------------------------------------------------
 * Phase: attributes - one global attribute per OpenCart option in use
 * ---------------------------------------------------------------------- */

function oc_attribute_slug( wpdb $oc, int $option_id ): string {
	static $cache = array();
	if ( isset( $cache[ $option_id ] ) ) {
		return $cache[ $option_id ];
	}

	$name = $oc->get_var(
		$oc->prepare(
			'SELECT name FROM oc_option_description WHERE option_id = %d AND language_id = %d',
			$option_id,
			OC_LANG
		)
	);

	// "Escolha o tamanho desejado" is the size picker on 4,250 products; give it
	// the obvious slug rather than a 25-character one.
	$slug = ( 10 === $option_id ) ? 'tamanho' : sanitize_title( oc_text( $name ) );
	$slug = substr( $slug, 0, 27 ); // wc attribute names are capped at 32 incl. "pa_"

	return $cache[ $option_id ] = $slug;
}

function oc_phase_attributes( wpdb $oc ): void {
	$options = $oc->get_col( 'SELECT DISTINCT option_id FROM oc_product_option ORDER BY option_id' );
	oc_log( sprintf( 'attributes: %d options in use', count( $options ) ) );

	$existing = array();
	foreach ( wc_get_attribute_taxonomies() as $tax ) {
		$existing[ $tax->attribute_name ] = (int) $tax->attribute_id;
	}

	foreach ( $options as $option_id ) {
		$option_id = (int) $option_id;
		$slug      = oc_attribute_slug( $oc, $option_id );
		if ( '' === $slug ) {
			continue;
		}

		$label = oc_text(
			$oc->get_var(
				$oc->prepare(
					'SELECT name FROM oc_option_description WHERE option_id = %d AND language_id = %d',
					$option_id,
					OC_LANG
				)
			)
		);

		if ( ! isset( $existing[ $slug ] ) ) {
			$attribute_id = wc_create_attribute(
				array(
					'name'         => ( 10 === $option_id ) ? 'Tamanho' : $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $attribute_id ) ) {
				oc_log( sprintf( '  ! attribute %s: %s', $slug, $attribute_id->get_error_message() ) );
				continue;
			}
			$existing[ $slug ] = (int) $attribute_id;
			oc_log( sprintf( '  + pa_%s (%s)', $slug, $label ) );
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false, 'public' => false ) );
		}

		$values = $oc->get_results(
			$oc->prepare(
				"SELECT DISTINCT ovd.option_value_id, ovd.name
				 FROM oc_option_value_description ovd
				 JOIN oc_product_option_value pov ON pov.option_value_id = ovd.option_value_id
				 WHERE ovd.option_id = %d AND ovd.language_id = %d",
				$option_id,
				OC_LANG
			)
		);

		foreach ( $values as $value ) {
			$name = oc_text( $value->name );
			if ( '' === $name ) {
				continue;
			}
			$term = term_exists( $name, $taxonomy );
			if ( ! $term ) {
				$term = wp_insert_term( $name, $taxonomy );
			}
			if ( ! is_wp_error( $term ) ) {
				update_term_meta( (int) $term['term_id'], '_oc_option_value_id', (int) $value->option_value_id );
			}
		}
	}

	delete_transient( 'wc_attribute_taxonomies' );
	oc_log( 'attributes done' );
}

/* -------------------------------------------------------------------------
 * Phase: products
 * ---------------------------------------------------------------------- */

function oc_product_map(): array {
	global $wpdb;
	$map  = array();
	$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_oc_product_id'" );
	foreach ( $rows as $row ) {
		$map[ (int) $row->meta_value ] = (int) $row->post_id;
	}
	return $map;
}

function oc_term_lookup( string $meta_key, string $taxonomy ): array {
	$map = array();
	foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $term ) {
		$oc_id = (int) get_term_meta( $term->term_id, $meta_key, true );
		if ( $oc_id ) {
			$map[ $oc_id ] = (int) $term->term_id;
		}
	}
	return $map;
}

function oc_unique_sku( string $candidate, int $oc_product_id, int $product_id ): string {
	$candidate = trim( $candidate );
	if ( '' !== $candidate ) {
		$owner = wc_get_product_id_by_sku( $candidate );
		if ( ! $owner || $owner === $product_id ) {
			return $candidate;
		}
	}
	return 'OC-' . $oc_product_id;
}

function oc_phase_products( wpdb $oc, int $batch, int $after_id, int $max_batches = 0 ): void {
	global $wpdb;

	$cat_terms   = oc_term_lookup( '_oc_category_id', 'product_cat' );
	$brand_terms = taxonomy_exists( 'product_brand' ) ? oc_term_lookup( '_oc_manufacturer_id', 'product_brand' ) : array();
	$product_map = oc_product_map();

	// option_value_id -> attribute term, for variations.
	$value_terms = array();
	foreach ( wc_get_attribute_taxonomies() as $tax ) {
		$taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name );
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $term ) {
			$oc_id = (int) get_term_meta( $term->term_id, '_oc_option_value_id', true );
			if ( $oc_id ) {
				$value_terms[ $oc_id ] = array( 'taxonomy' => $taxonomy, 'slug' => $term->slug, 'term_id' => (int) $term->term_id );
			}
		}
	}

	// Only specials whose window covers today become sale prices.
	$specials = array();
	foreach (
		$oc->get_results(
			"SELECT product_id, price FROM oc_product_special
			 WHERE (date_start = '0000-00-00' OR date_start <= CURDATE())
			   AND (date_end   = '0000-00-00' OR date_end   >= CURDATE())
			 ORDER BY priority, price"
		) as $row
	) {
		$pid = (int) $row->product_id;
		if ( ! isset( $specials[ $pid ] ) ) {
			$specials[ $pid ] = (float) $row->price;
		}
	}
	oc_log( sprintf( 'products: %d categories, %d brands, %d attribute values, %d live specials', count( $cat_terms ), count( $brand_terms ), count( $value_terms ), count( $specials ) ) );

	$total     = (int) $oc->get_var( 'SELECT COUNT(*) FROM oc_product' );
	$done      = 0;
	$created   = 0;
	$skipped   = 0;
	$variations_made = 0;
	$batches   = 0;
	$started   = microtime( true );

	while ( true ) {
		$rows = $oc->get_results(
			$oc->prepare(
				"SELECT p.*, pd.name, pd.description, pd.meta_description, pd.tag
				 FROM oc_product p
				 JOIN oc_product_description pd
				   ON pd.product_id = p.product_id AND pd.language_id = %d
				 WHERE p.product_id > %d
				 ORDER BY p.product_id
				 LIMIT %d",
				OC_LANG,
				$after_id,
				$batch
			)
		);

		if ( empty( $rows ) ) {
			break;
		}

		foreach ( $rows as $row ) {
			$oc_id    = (int) $row->product_id;
			$after_id = $oc_id;
			$done++;

			$name = oc_text( $row->name );
			if ( '' === $name ) {
				$skipped++;
				continue;
			}

			$option_rows = $oc->get_results(
				$oc->prepare(
					'SELECT product_option_id, option_id FROM oc_product_option WHERE product_id = %d',
					$oc_id
				)
			);

			$existing_id = $product_map[ $oc_id ] ?? 0;
			$is_variable = ! empty( $option_rows );

			if ( $existing_id ) {
				$product = wc_get_product( $existing_id );
				if ( ! $product || ( $product->is_type( 'variable' ) !== $is_variable ) ) {
					$product = $is_variable ? new WC_Product_Variable( $existing_id ) : new WC_Product_Simple( $existing_id );
				}
			} else {
				$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
				$created++;
			}

			$price = round( (float) $row->price, 2 );
			$product->set_name( $name );
			$product->set_description( oc_html( $row->description ) );
			$product->set_short_description( oc_text( $row->meta_description ) );
			$product->set_status( oc_target_status( (int) $row->status ) );
			$product->set_catalog_visibility( 'visible' );
			$product->set_regular_price( (string) $price );
			$product->set_sale_price( isset( $specials[ $oc_id ] ) ? (string) round( $specials[ $oc_id ], 2 ) : '' );
			$product->set_date_created( $row->date_added && '0000-00-00 00:00:00' !== $row->date_added ? $row->date_added : null );

			$slug = oc_slug( $oc, 'product_id=' . $oc_id );
			if ( '' !== $slug ) {
				$product->set_slug( $slug );
			}

			// OpenCart holds weights in pounds (weight_class_id 5); the store is kg.
			$weight = (float) $row->weight;
			if ( 5 === (int) $row->weight_class_id ) {
				$weight *= OC_LB_TO_KG;
			}
			$product->set_weight( $weight > 0 ? (string) round( $weight, 3 ) : '' );

			$quantity = (int) $row->quantity;
			$product->set_manage_stock( ! $is_variable );
			if ( ! $is_variable ) {
				$product->set_stock_quantity( $quantity );
			}
			$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );

			$product->set_category_ids(
				array_values(
					array_filter(
						array_map(
							static function ( $cat_id ) use ( $cat_terms ) {
								return $cat_terms[ (int) $cat_id ] ?? null;
							},
							$oc->get_col( $oc->prepare( 'SELECT category_id FROM oc_product_to_category WHERE product_id = %d', $oc_id ) )
						)
					)
				)
			);

			$tags = array();
			foreach ( explode( ',', oc_text( $row->tag ) ) as $tag ) {
				$tag = trim( $tag );
				if ( '' !== $tag && mb_strlen( $tag ) <= 80 ) {
					$tags[] = $tag;
				}
			}
			if ( $tags ) {
				$product->set_tag_ids( array() ); // replaced below by name
			}

			// Images: featured plus gallery, all linked from the read-only mount.
			$thumb = oc_attachment_id( (string) $row->image, $name );
			if ( $thumb ) {
				$product->set_image_id( $thumb );
			}
			$gallery = array();
			foreach (
				$oc->get_results( $oc->prepare( 'SELECT image FROM oc_product_image WHERE product_id = %d ORDER BY sort_order, product_image_id', $oc_id ) ) as $img
			) {
				$id = oc_attachment_id( (string) $img->image, $name );
				if ( $id && $id !== $thumb ) {
					$gallery[] = $id;
				}
			}
			$product->set_gallery_image_ids( array_values( array_unique( $gallery ) ) );

			// Attributes (used for variations when the product has options).
			$attributes   = array();
			$option_terms = array();
			foreach ( $option_rows as $option_row ) {
				$taxonomy = null;
				$slugs    = array();
				foreach (
					$oc->get_results(
						$oc->prepare(
							'SELECT product_option_value_id, option_value_id, price, price_prefix, quantity, sku
							 FROM oc_product_option_value WHERE product_option_id = %d',
							$option_row->product_option_id
						)
					) as $value_row
				) {
					$term = $value_terms[ (int) $value_row->option_value_id ] ?? null;
					if ( ! $term ) {
						continue;
					}
					$taxonomy                 = $term['taxonomy'];
					$slugs[ $term['slug'] ]   = $term['term_id'];
					$option_terms[ $taxonomy ][ $term['slug'] ] = $value_row;
				}

				if ( $taxonomy && $slugs ) {
					$attribute = new WC_Product_Attribute();
					$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
					$attribute->set_name( $taxonomy );
					$attribute->set_options( array_values( $slugs ) );
					$attribute->set_visible( true );
					$attribute->set_variation( true );
					$attributes[ $taxonomy ] = $attribute;
				}
			}
			$product->set_attributes( $attributes );

			$product_id = $product->save();
			if ( ! $product_id ) {
				$skipped++;
				continue;
			}

			$product_map[ $oc_id ] = $product_id;
			update_post_meta( $product_id, '_oc_product_id', $oc_id );
			update_post_meta( $product_id, '_oc_model', (string) $row->model );

			$sku = oc_unique_sku( (string) $row->sku ?: (string) $row->model, $oc_id, $product_id );
			try {
				$product->set_sku( $sku );
				$product->save();
			} catch ( Exception $e ) {
				// Duplicate SKU: fall back to the OpenCart id, which is unique.
				$product->set_sku( 'OC-' . $oc_id );
				$product->save();
			}

			if ( $tags ) {
				wp_set_object_terms( $product_id, $tags, 'product_tag', false );
			}

			$brand = $brand_terms[ (int) $row->manufacturer_id ] ?? null;
			if ( $brand ) {
				wp_set_object_terms( $product_id, array( $brand ), 'product_brand', false );
			}

			if ( $attributes && $option_terms ) {
				$variations_made += oc_sync_variations( $product_id, $price, $option_terms );
			}
		}

		update_option( 'oc_import_last_product_id', $after_id, false );
		$batches++;

		$rate = $done / max( 0.001, microtime( true ) - $started );
		oc_log(
			sprintf(
				'  %d/%d products (id<=%d) | %d new, %d skipped, %d variations | %.1f/s',
				$done,
				$total,
				$after_id,
				$created,
				$skipped,
				$variations_made,
				$rate
			)
		);

		if ( $max_batches && $batches >= $max_batches ) {
			oc_log( sprintf( 'stopping after %d batch(es); resume with: products %d %d', $batches, $batch, $after_id ) );
			break;
		}
	}

	wp_defer_term_counting( false );
	oc_log( sprintf( 'products done: %d processed, %d created, %d variations, %d skipped', $done, $created, $variations_made, $skipped ) );
}

/**
 * Create/refresh the variations of one variable product.
 * OpenCart products carry one option each (33 carry two), so the cartesian
 * product stays small; it is capped anyway.
 */
function oc_sync_variations( int $product_id, float $base_price, array $option_terms ): int {
	$combinations = array( array() );
	foreach ( $option_terms as $taxonomy => $values ) {
		$next = array();
		foreach ( $combinations as $combo ) {
			foreach ( $values as $slug => $value_row ) {
				$combo[ $taxonomy ] = array( 'slug' => $slug, 'row' => $value_row );
				$next[]             = $combo;
			}
		}
		$combinations = $next;
		if ( count( $combinations ) > OC_MAX_VARIATIONS ) {
			$combinations = array_slice( $combinations, 0, OC_MAX_VARIATIONS );
			break;
		}
	}

	$existing = array();
	foreach ( wc_get_product( $product_id )->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );
		if ( $variation ) {
			$existing[ md5( wp_json_encode( $variation->get_attributes() ) ) ] = $child_id;
		}
	}

	$made = 0;
	foreach ( $combinations as $combo ) {
		if ( empty( $combo ) ) {
			continue;
		}

		$attributes = array();
		$delta      = 0.0;
		$quantity   = 0;
		$sku        = '';
		foreach ( $combo as $taxonomy => $pick ) {
			$attributes[ $taxonomy ] = $pick['slug'];
			$row                     = $pick['row'];
			$delta                  += ( '-' === $row->price_prefix ? -1 : 1 ) * (float) $row->price;
			$quantity                = max( $quantity, (int) $row->quantity );
			$sku                     = $sku ?: trim( (string) $row->sku );
		}

		$key       = md5( wp_json_encode( $attributes ) );
		$variation = isset( $existing[ $key ] ) ? new WC_Product_Variation( $existing[ $key ] ) : new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( $attributes );
		$variation->set_regular_price( (string) round( max( 0, $base_price + $delta ), 2 ) );
		$variation->set_manage_stock( $quantity > 0 );
		if ( $quantity > 0 ) {
			$variation->set_stock_quantity( $quantity );
		}
		$variation->set_stock_status( 'instock' );
		if ( '' !== $sku && ! wc_get_product_id_by_sku( $sku ) ) {
			try {
				$variation->set_sku( $sku );
			} catch ( Exception $e ) {
				// leave the variation without an explicit SKU
			}
		}
		$variation->save();
		$made++;
	}

	WC_Product_Variable::sync( $product_id );
	return $made;
}

/* ---------------------------------------------------------------------- */

switch ( $phase ) {
	case 'categories':
		oc_phase_categories( $oc );
		break;
	case 'brands':
		oc_phase_brands( $oc );
		break;
	case 'attributes':
		oc_phase_attributes( $oc );
		break;
	case 'products':
		oc_phase_products(
			$oc,
			(int) ( $oc_argv[1] ?? 100 ),
			(int) ( $oc_argv[2] ?? get_option( 'oc_import_last_product_id', 0 ) ),
			(int) ( $oc_argv[3] ?? 0 )
		);
		break;
	default:
		oc_abort( 'usage: <categories|brands|attributes|products> [batch] [after_id] [max_batches]' );
}
