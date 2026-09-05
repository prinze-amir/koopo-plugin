<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps selected products out of public catalogs while allowing them on a
 * linked GeoDirectory listing.
 */
final class Koopo_Place_Only_Products {
	const META_ENABLED             = '_koopo_place_only';
	const META_PREVIOUS_VISIBILITY = '_koopo_place_only_previous_visibility';
	const META_LISTING_ID          = '_geomp_gd_listing_id';
	const NONCE_ACTION             = 'koopo_place_only_product';
	const NONCE_NAME               = 'koopo_place_only_nonce';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_admin_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_admin_field' ), 30 );

		add_action( 'dokan_product_edit_after_main', array( __CLASS__, 'render_dokan_field' ), 110, 2 );
		add_action( 'dokan_process_product_meta', array( __CLASS__, 'save_dokan_field' ), 30 );

		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'include_place_only_products' ), 30, 3 );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'make_place_only_product_visible' ), 30, 2 );
	}

	public static function render_admin_field() {
		global $post;

		if ( ! $post || 'product' !== $post->post_type || ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		woocommerce_wp_checkbox(
			array(
				'id'          => 'koopo_place_only',
				'value'       => get_post_meta( $post->ID, self::META_ENABLED, true ),
				'label'       => __( 'Place-only product', 'koopo' ),
				'description' => __( 'Hide this product from the shop, product search, and vendor store. It remains available on its linked GeoDirectory Place.', 'koopo' ),
				'desc_tip'    => true,
			)
		);
	}

	public static function render_dokan_field( $post, $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return;
		}

		$enabled = 'yes' === get_post_meta( $product_id, self::META_ENABLED, true );
		?>
		<div class="dokan-edit-row dokan-clearfix koopo-place-only-product">
			<div class="dokan-section-heading" data-togglehandler="dokan_koopo_place_only">
				<h2><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php esc_html_e( 'Place visibility', 'koopo' ); ?></h2>
				<p><?php esc_html_e( 'Control where this linked product can be discovered.', 'koopo' ); ?></p>
				<a href="#" class="dokan-section-toggle"><i class="fas fa-sort-down fa-flip-vertical" aria-hidden="true"></i></a>
				<div class="dokan-clearfix"></div>
			</div>
			<div class="dokan-section-content">
				<div class="dokan-form-group">
					<label for="koopo_place_only">
						<input type="checkbox" id="koopo_place_only" name="koopo_place_only" value="yes" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Show only on the linked Place', 'koopo' ); ?>
					</label>
					<p class="help-block">
						<?php esc_html_e( 'The product stays published and purchasable, but is hidden from the shop, product search, and your public vendor store.', 'koopo' ); ?>
					</p>
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function save_admin_field( $product ) {
		if ( ! $product instanceof WC_Product || ! self::valid_save_request( $product->get_id() ) ) {
			return;
		}

		$enabled    = isset( $_POST['koopo_place_only'] );
		$listing_id = self::requested_listing_id( $product->get_id() );
		if ( $enabled && ! $listing_id ) {
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				WC_Admin_Meta_Boxes::add_error( __( 'A place-only product must be linked to a GeoDirectory Place.', 'koopo' ) );
			}
			return;
		}

		self::apply_setting( $product, $enabled );
	}

	public static function save_dokan_field( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || ! self::valid_save_request( $product_id ) ) {
			return;
		}

		$enabled    = isset( $_POST['koopo_place_only'] );
		$listing_id = self::requested_listing_id( $product_id );
		if ( $enabled && ! $listing_id ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'A place-only product must be linked to a GeoDirectory Place.', 'koopo' ), 'error' );
			}
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		if ( self::apply_setting( $product, $enabled ) ) {
			$product->save();
			wc_delete_product_transients( $product_id );
		}
	}

	private static function valid_save_request( $product_id ) {
		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';

		return $nonce
			&& wp_verify_nonce( $nonce, self::NONCE_ACTION )
			&& current_user_can( 'edit_post', absint( $product_id ) );
	}

	private static function requested_listing_id( $product_id ) {
		if ( isset( $_POST['gdmp_post_id'] ) ) {
			return absint( $_POST['gdmp_post_id'] );
		}

		return absint( get_post_meta( $product_id, self::META_LISTING_ID, true ) );
	}

	private static function apply_setting( $product, $enabled ) {
		$was_enabled = 'yes' === $product->get_meta( self::META_ENABLED, true );
		$visibility  = $product->get_catalog_visibility();

		if ( $enabled ) {
			if ( ! $was_enabled ) {
				$product->update_meta_data( self::META_PREVIOUS_VISIBILITY, $visibility );
			}
			$product->update_meta_data( self::META_ENABLED, 'yes' );
			if ( 'hidden' !== $visibility ) {
				$product->set_catalog_visibility( 'hidden' );
			}
			return ! $was_enabled || 'hidden' !== $visibility;
		}

		if ( ! $was_enabled ) {
			return false;
		}

		$previous = sanitize_key( (string) $product->get_meta( self::META_PREVIOUS_VISIBILITY, true ) );
		if ( ! in_array( $previous, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
			$previous = 'visible';
		}

		$product->set_catalog_visibility( $previous );
		$product->delete_meta_data( self::META_ENABLED );
		$product->delete_meta_data( self::META_PREVIOUS_VISIBILITY );
		return true;
	}

	public static function include_place_only_products( $query_args, $attributes, $type ) {
		if ( 'gd_marketplace' !== $type ) {
			return $query_args;
		}

		global $gd_post;
		$listing_id = ! empty( $gd_post->ID ) ? absint( $gd_post->ID ) : 0;
		if ( ! $listing_id ) {
			return $query_args;
		}

		$query_args['tax_query'] = self::remove_catalog_visibility_exclusions(
			isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] )
				? $query_args['tax_query']
				: array()
		);

		$hidden_without_place_access = self::hidden_linked_products_without_place_access( $listing_id );
		if ( $hidden_without_place_access ) {
			$query_args['post__not_in'] = array_values(
				array_unique(
					array_merge(
						isset( $query_args['post__not_in'] ) ? array_map( 'absint', (array) $query_args['post__not_in'] ) : array(),
						$hidden_without_place_access
					)
				)
			);
		}

		return $query_args;
	}

	public static function make_place_only_product_visible( $visible, $product_id ) {
		if ( $visible || 'gd_marketplace' !== wc_get_loop_prop( 'name' ) ) {
			return $visible;
		}

		global $gd_post;
		$listing_id = ! empty( $gd_post->ID ) ? absint( $gd_post->ID ) : 0;
		$product_id = absint( $product_id );
		if ( ! $listing_id || ! $product_id ) {
			return $visible;
		}

		$place_only = 'yes' === get_post_meta( $product_id, self::META_ENABLED, true );
		$linked_id  = absint( get_post_meta( $product_id, self::META_LISTING_ID, true ) );

		return $place_only && $listing_id === $linked_id ? true : $visible;
	}

	private static function remove_catalog_visibility_exclusions( $tax_query ) {
		$visibility_terms = function_exists( 'wc_get_product_visibility_term_ids' )
			? wc_get_product_visibility_term_ids()
			: array();
		$remove_ids       = array_filter(
			array(
				absint( isset( $visibility_terms['exclude-from-catalog'] ) ? $visibility_terms['exclude-from-catalog'] : 0 ),
				absint( isset( $visibility_terms['exclude-from-search'] ) ? $visibility_terms['exclude-from-search'] : 0 ),
			)
		);
		$remove_names     = array( 'exclude-from-catalog', 'exclude-from-search' );

		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}

			if ( empty( $clause['taxonomy'] ) ) {
				$tax_query[ $key ] = self::remove_catalog_visibility_exclusions( $clause );
				if ( ! array_filter( $tax_query[ $key ], 'is_array' ) ) {
					unset( $tax_query[ $key ] );
				}
				continue;
			}

			if (
				'product_visibility' !== $clause['taxonomy']
				|| 'NOT IN' !== strtoupper( isset( $clause['operator'] ) ? $clause['operator'] : 'IN' )
			) {
				continue;
			}

			$terms = (array) ( isset( $clause['terms'] ) ? $clause['terms'] : array() );
			$field = isset( $clause['field'] ) ? $clause['field'] : 'term_id';
			if ( in_array( $field, array( 'name', 'slug' ), true ) ) {
				$terms = array_values( array_diff( $terms, $remove_names ) );
			} else {
				$terms = array_values( array_diff( array_map( 'absint', $terms ), $remove_ids ) );
			}

			if ( ! $terms ) {
				unset( $tax_query[ $key ] );
			} else {
				$tax_query[ $key ]['terms'] = $terms;
			}
		}

		return $tax_query;
	}

	private static function hidden_linked_products_without_place_access( $listing_id ) {
		$product_ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'meta_key'               => self::META_LISTING_ID,
				'meta_value'             => absint( $listing_id ),
			)
		);

		$excluded = array();
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if (
				$product
				&& 'hidden' === $product->get_catalog_visibility()
				&& 'yes' !== $product->get_meta( self::META_ENABLED, true )
			) {
				$excluded[] = absint( $product_id );
			}
		}

		return $excluded;
	}
}
