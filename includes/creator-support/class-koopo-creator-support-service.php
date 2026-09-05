<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Creator_Support_Service {
    const USER_META_PRODUCT_ID        = '_koopo_creator_support_product_id';
    const PRODUCT_META_CREATOR_ID     = '_koopo_creator_support_creator_id';
    const PRODUCT_META_KIND           = '_koopo_creator_support_product_kind';
    const PRODUCT_KIND                = 'creator_support';

    const REQUEST_FLAG                = 'koopo_creator_support';
    const REQUEST_CREATOR_ID          = 'koopo_creator_id';
    const REQUEST_AMOUNT              = 'koopo_creator_amount';
    const REQUEST_MODULE              = 'koopo_creator_module';
    const REQUEST_SURFACE             = 'koopo_creator_surface';
    const REQUEST_CONTEXT_ID          = 'koopo_creator_context_id';
    const REQUEST_CONTEXT_TYPE        = 'koopo_creator_context_type';

    const CART_ITEM_FLAG              = 'koopo_creator_support';
    const CART_ITEM_CREATOR_ID        = 'koopo_creator_support_creator_id';
    const CART_ITEM_AMOUNT            = 'koopo_creator_support_amount';
    const CART_ITEM_MODULE            = 'koopo_creator_support_module';
    const CART_ITEM_SURFACE           = 'koopo_creator_support_surface';
    const CART_ITEM_CONTEXT_ID        = 'koopo_creator_support_context_id';
    const CART_ITEM_CONTEXT_TYPE      = 'koopo_creator_support_context_type';

    const ORDER_ITEM_META_FLAG        = '_koopo_creator_support';
    const ORDER_ITEM_META_CREATOR_ID  = '_koopo_creator_support_creator_id';
    const ORDER_ITEM_META_AMOUNT      = '_koopo_creator_support_amount';
    const ORDER_ITEM_META_MODULE      = '_koopo_creator_support_module';
    const ORDER_ITEM_META_SURFACE     = '_koopo_creator_support_surface';
    const ORDER_ITEM_META_CONTEXT_ID  = '_koopo_creator_support_context_id';
    const ORDER_ITEM_META_CONTEXT_TYPE = '_koopo_creator_support_context_type';

    const LEGACY_VIDEO_USER_META_PRODUCT_ID    = '_koopo_video_donation_product_id';
    const LEGACY_VIDEO_PRODUCT_META_CREATOR_ID = '_koopo_video_donation_creator_id';
    const LEGACY_VIDEO_PRODUCT_META_KIND       = '_koopo_video_donation_product_kind';
    const LEGACY_VIDEO_PRODUCT_KIND            = 'creator_donation';
    const LEGACY_VIDEO_ORDER_ITEM_CREATOR_ID   = '_koopo_video_donation_creator_id';
    const LEGACY_VIDEO_ORDER_ITEM_FLAG         = '_koopo_video_donation';

    public function hooks() {
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 6 );
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 4 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_item_prices' ), 20 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_order_line_item_meta' ), 10, 4 );
        add_filter( 'woocommerce_product_get_image', array( $this, 'filter_product_image' ), 10, 6 );
        add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'filter_cart_item_thumbnail' ), 10, 3 );
    }

    public function creator_support_access_state( $creator_id ) {
        $creator_id = absint( (int) $creator_id );

        $state = array(
            'creator_id'             => $creator_id,
            'can_accept'             => false,
            'reason'                 => 'seller_required',
            'message'                => __( 'Enable selling to accept donations from supporters.', 'koopo' ),
            'has_store_permissions'  => false,
            'has_public_store'       => false,
            'has_product'            => false,
            'product_id'             => 0,
            'can_create_product'     => false,
            'setup_message'          => '',
        );

        if ( ! $creator_id ) {
            return $state;
        }

        $is_admin_bypass = $this->is_admin_bypass( $creator_id );

        if ( $is_admin_bypass ) {
            $state['has_store_permissions'] = true;
            $state['has_public_store']      = true;
        } else {
            $state['has_store_permissions'] = $this->creator_has_store_permissions( $creator_id );
            $state['has_public_store']      = $this->creator_has_public_store( $creator_id );
        }

        if ( ! $state['has_store_permissions'] ) {
            return $state;
        }

        if ( ! $state['has_public_store'] ) {
            $state['reason']  = 'seller_pending';
            $state['message'] = __( 'Finish activating selling before supporters can donate.', 'koopo' );
            return $state;
        }

        $state['product_id']  = $this->product_id_for_creator( $creator_id );
        $state['has_product'] = $state['product_id'] > 0;

        if ( ! $state['has_product'] ) {
            $state['reason']  = 'product_missing';
            $state['message'] = __( 'Donations are not enabled for this creator yet.', 'koopo' );

            $product_capacity = $this->seller_product_capacity_state( $creator_id );
            if ( ! empty( $product_capacity['can_create_product'] ) ) {
                $state['can_create_product'] = true;
            }
            if ( ! empty( $product_capacity['message'] ) ) {
                $state['setup_message'] = (string) $product_capacity['message'];
            }

            if ( '' === $state['setup_message'] ) {
                $state['setup_message'] = __( 'Create a donation product to start accepting support.', 'koopo' );
            }

            return $state;
        }

        $state['can_accept'] = true;
        $state['reason']     = $is_admin_bypass ? 'admin_bypass' : 'allowed';
        $state['message']    = $is_admin_bypass
            ? __( 'Administrators can accept creator donations without seller restrictions.', 'koopo' )
            : __( 'Supporters can donate to this creator.', 'koopo' );

        return $state;
    }

    public function checkout_url_for_creator( $creator_id, $amount, $args = array() ) {
        $creator_id = absint( (int) $creator_id );
        $amount     = $this->normalize_amount( $amount );
        $args       = is_array( $args ) ? $args : array();

        if ( ! $creator_id || $amount <= 0 ) {
            return new WP_Error( 'invalid_donation', __( 'A valid creator and donation amount are required.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( ! function_exists( 'wc_get_checkout_url' ) ) {
            return new WP_Error( 'woocommerce_required', __( 'WooCommerce checkout is required for donations.', 'koopo' ), array( 'status' => 500 ) );
        }

        $access_state = $this->creator_support_access_state( $creator_id );
        if ( empty( $access_state['can_accept'] ) ) {
            return new WP_Error(
                'donations_unavailable',
                ! empty( $access_state['message'] ) ? (string) $access_state['message'] : __( 'Donations are not available for this creator right now.', 'koopo' ),
                array(
                    'status'       => 403,
                    'access_state' => $access_state,
                )
            );
        }

        $product_id = $this->product_id_for_creator( $creator_id );
        if ( ! $product_id ) {
            return new WP_Error(
                'donations_unavailable',
                __( 'Donations are not enabled for this creator right now.', 'koopo' ),
                array(
                    'status'       => 403,
                    'access_state' => $access_state,
                )
            );
        }

        $module       = isset( $args['module'] ) ? sanitize_key( (string) $args['module'] ) : 'general';
        $surface      = isset( $args['surface'] ) ? sanitize_key( (string) $args['surface'] ) : 'default';
        $context_id   = isset( $args['context_post_id'] ) ? absint( (int) $args['context_post_id'] ) : 0;
        $context_type = isset( $args['context_post_type'] ) ? sanitize_key( (string) $args['context_post_type'] ) : '';

        if ( ! $context_type && $context_id ) {
            $context_type = sanitize_key( (string) get_post_type( $context_id ) );
        }

        return add_query_arg(
            array(
                'add-to-cart'               => $product_id,
                self::REQUEST_FLAG          => '1',
                self::REQUEST_CREATOR_ID    => $creator_id,
                self::REQUEST_AMOUNT        => wc_format_decimal( $amount, $this->price_decimals() ),
                self::REQUEST_MODULE        => $module,
                self::REQUEST_SURFACE       => $surface,
                self::REQUEST_CONTEXT_ID    => $context_id,
                self::REQUEST_CONTEXT_TYPE  => $context_type,
            ),
            wc_get_checkout_url()
        );
    }

    public function creator_revenue_summary( $creator_id, $days = 0, $module = '' ) {
        $summary = array(
            'total'       => 0.0,
            'order_count' => 0,
            'item_count'  => 0,
            'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
        );

        $creator_id = absint( (int) $creator_id );
        $days       = absint( (int) $days );
        $module     = sanitize_key( (string) $module );

        if ( ! $creator_id || ! function_exists( 'wc_get_orders' ) ) {
            return $summary;
        }

        $orders = wc_get_orders( $this->paid_order_query_args( $days ) );
        if ( ! is_array( $orders ) ) {
            return $summary;
        }

        $order_ids = array();

        foreach ( $orders as $order ) {
            if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_items' ) ) {
                continue;
            }

            foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
                if ( ! is_object( $item ) ) {
                    continue;
                }

                $matches_generic = $this->order_item_matches_support_context( $item, $creator_id, $module );
                $matches_legacy  = 'videos' === $module && $this->order_item_matches_legacy_video_support( $item, $creator_id );

                if ( ! $matches_generic && ! $matches_legacy ) {
                    continue;
                }

                $summary['total']      += (float) $item->get_total() + (float) $item->get_total_tax();
                $summary['item_count'] += 1;
                $order_ids[ (int) $order->get_id() ] = true;
            }
        }

        $summary['total']       = round( max( 0, (float) $summary['total'] ), $this->price_decimals() );
        $summary['order_count'] = count( $order_ids );

        return $summary;
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
        unset( $quantity, $variation_id, $variations, $cart_item_data );

        if ( ! $this->request_is_support_checkout() ) {
            return $passed;
        }

        $creator_id = $this->requested_creator_id();
        $amount     = $this->requested_amount();

        if ( ! $creator_id || $amount <= 0 ) {
            wc_add_notice( __( 'Please choose a valid donation amount.', 'koopo' ), 'error' );
            return false;
        }

        if ( ! $this->is_creator_support_product( $product_id, $creator_id ) ) {
            wc_add_notice( __( 'This donation product is no longer valid.', 'koopo' ), 'error' );
            return false;
        }

        return $passed;
    }

    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
        unset( $variation_id, $quantity );

        if ( ! $this->request_is_support_checkout() ) {
            return $cart_item_data;
        }

        $creator_id = $this->requested_creator_id();
        $amount     = $this->requested_amount();
        $module     = $this->requested_module();
        $surface    = $this->requested_surface();
        $context_id = $this->requested_context_id();
        $context_type = $this->requested_context_type();

        if ( ! $creator_id || $amount <= 0 || ! $this->is_creator_support_product( $product_id, $creator_id ) ) {
            return $cart_item_data;
        }

        $cart_item_data[ self::CART_ITEM_FLAG ]         = true;
        $cart_item_data[ self::CART_ITEM_CREATOR_ID ]   = $creator_id;
        $cart_item_data[ self::CART_ITEM_AMOUNT ]       = wc_format_decimal( $amount, $this->price_decimals() );
        $cart_item_data[ self::CART_ITEM_MODULE ]       = $module;
        $cart_item_data[ self::CART_ITEM_SURFACE ]      = $surface;
        $cart_item_data[ self::CART_ITEM_CONTEXT_ID ]   = $context_id;
        $cart_item_data[ self::CART_ITEM_CONTEXT_TYPE ] = $context_type;
        $cart_item_data['unique_key']                   = md5( $creator_id . '|' . $amount . '|' . $module . '|' . $surface . '|' . microtime( true ) );

        return $cart_item_data;
    }

    public function apply_cart_item_prices( $cart ) {
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( empty( $cart_item[ self::CART_ITEM_FLAG ] ) || empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
                continue;
            }

            $amount = $this->normalize_amount( isset( $cart_item[ self::CART_ITEM_AMOUNT ] ) ? $cart_item[ self::CART_ITEM_AMOUNT ] : 0 );
            if ( $amount <= 0 || ! method_exists( $cart_item['data'], 'set_price' ) ) {
                continue;
            }

            $cart->cart_contents[ $cart_item_key ]['data']->set_price( $amount );
        }
    }

    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item[ self::CART_ITEM_FLAG ] ) ) {
            return $item_data;
        }

        $creator_id = absint( (int) ( isset( $cart_item[ self::CART_ITEM_CREATOR_ID ] ) ? $cart_item[ self::CART_ITEM_CREATOR_ID ] : 0 ) );
        $amount     = $this->normalize_amount( isset( $cart_item[ self::CART_ITEM_AMOUNT ] ) ? $cart_item[ self::CART_ITEM_AMOUNT ] : 0 );

        if ( $creator_id ) {
            $item_data[] = array(
                'key'   => __( 'Creator', 'koopo' ),
                'value' => $this->creator_display_name( $creator_id ),
            );
        }

        if ( $amount > 0 && function_exists( 'wc_price' ) ) {
            $item_data[] = array(
                'key'   => __( 'Donation', 'koopo' ),
                'value' => wp_strip_all_tags( wc_price( $amount ) ),
            );
        }

        return $item_data;
    }

    public function persist_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        unset( $cart_item_key, $order );

        if ( ! is_object( $item ) || empty( $values[ self::CART_ITEM_FLAG ] ) ) {
            return;
        }

        $creator_id   = absint( (int) ( isset( $values[ self::CART_ITEM_CREATOR_ID ] ) ? $values[ self::CART_ITEM_CREATOR_ID ] : 0 ) );
        $amount       = $this->normalize_amount( isset( $values[ self::CART_ITEM_AMOUNT ] ) ? $values[ self::CART_ITEM_AMOUNT ] : 0 );
        $module       = isset( $values[ self::CART_ITEM_MODULE ] ) ? sanitize_key( (string) $values[ self::CART_ITEM_MODULE ] ) : 'general';
        $surface      = isset( $values[ self::CART_ITEM_SURFACE ] ) ? sanitize_key( (string) $values[ self::CART_ITEM_SURFACE ] ) : 'default';
        $context_id   = isset( $values[ self::CART_ITEM_CONTEXT_ID ] ) ? absint( (int) $values[ self::CART_ITEM_CONTEXT_ID ] ) : 0;
        $context_type = isset( $values[ self::CART_ITEM_CONTEXT_TYPE ] ) ? sanitize_key( (string) $values[ self::CART_ITEM_CONTEXT_TYPE ] ) : '';

        $item->add_meta_data( self::ORDER_ITEM_META_FLAG, '1', true );
        $item->add_meta_data( self::ORDER_ITEM_META_CREATOR_ID, $creator_id, true );
        $item->add_meta_data( self::ORDER_ITEM_META_AMOUNT, wc_format_decimal( $amount, $this->price_decimals() ), true );
        $item->add_meta_data( self::ORDER_ITEM_META_MODULE, $module, true );
        $item->add_meta_data( self::ORDER_ITEM_META_SURFACE, $surface, true );
        $item->add_meta_data( self::ORDER_ITEM_META_CONTEXT_ID, $context_id, true );
        $item->add_meta_data( self::ORDER_ITEM_META_CONTEXT_TYPE, $context_type, true );
    }

    public function filter_product_image( $image, $product, $size = 'woocommerce_thumbnail', $attr = array(), $placeholder = true, $image_id = 0 ) {
        unset( $placeholder, $image_id );

        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return $image;
        }

        $product_id = absint( (int) $product->get_id() );
        if ( ! $this->is_creator_support_product( $product_id ) ) {
            return $image;
        }

        if ( has_post_thumbnail( $product_id ) ) {
            return $image;
        }

        return $this->default_product_image_html( $size, is_array( $attr ) ? $attr : array(), $product_id );
    }

    public function filter_cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
        unset( $cart_item_key );

        $product_id = 0;
        if ( ! empty( $cart_item['product_id'] ) ) {
            $product_id = absint( (int) $cart_item['product_id'] );
        } elseif ( ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_id' ) ) {
            $product_id = absint( (int) $cart_item['data']->get_id() );
        }

        if ( ! $this->is_creator_support_product( $product_id ) ) {
            return $thumbnail;
        }

        if ( has_post_thumbnail( $product_id ) ) {
            return $thumbnail;
        }

        return $this->default_product_image_html( 'woocommerce_thumbnail', array(), $product_id );
    }

    public function ensure_product_for_creator( $creator_id ) {
        $creator_id = absint( (int) $creator_id );
        if ( ! $creator_id ) {
            return new WP_Error( 'invalid_creator', __( 'Creator not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $existing_product_id = $this->product_id_for_creator( $creator_id );
        if ( $existing_product_id ) {
            $this->sync_legacy_video_product_meta( $creator_id, $existing_product_id );
            return $existing_product_id;
        }

        $product_capacity = $this->seller_product_capacity_state( $creator_id );
        if ( empty( $product_capacity['can_create_product'] ) ) {
            return new WP_Error(
                'product_limit_reached',
                ! empty( $product_capacity['message'] ) ? (string) $product_capacity['message'] : __( 'You have reached the product limit for your current seller pack.', 'koopo' ),
                array(
                    'status'           => 403,
                    'product_capacity' => $product_capacity,
                )
            );
        }

        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return new WP_Error( 'woocommerce_required', __( 'WooCommerce is required to accept donations.', 'koopo' ), array( 'status' => 500 ) );
        }

        $creator = get_userdata( $creator_id );
        if ( ! $creator ) {
            return new WP_Error( 'invalid_creator', __( 'Creator not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $creator_name = $this->creator_display_name( $creator_id );
        $product      = new WC_Product_Simple();
        $product->set_name(
            sprintf(
                /* translators: %s: creator name */
                __( 'Support %s', 'koopo' ),
                $creator_name
            )
        );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_virtual( true );
        $product->set_regular_price( '0' );
        $product->set_price( '0' );
        $product->set_sold_individually( false );
        $product->set_description( $this->support_product_warning_text( $creator_name ) );
        $product->set_short_description( $this->support_product_warning_text( $creator_name ) );

        if ( method_exists( $product, 'set_tax_status' ) ) {
            $product->set_tax_status( 'none' );
        }

        $product->update_meta_data( self::PRODUCT_META_CREATOR_ID, $creator_id );
        $product->update_meta_data( self::PRODUCT_META_KIND, self::PRODUCT_KIND );

        $product_id = absint( (int) $product->save() );
        if ( ! $product_id ) {
            return new WP_Error( 'product_create_failed', __( 'Could not create a donation product for this creator.', 'koopo' ), array( 'status' => 500 ) );
        }

        wp_update_post(
            array(
                'ID'          => $product_id,
                'post_author' => $creator_id,
            )
        );

        update_user_meta( $creator_id, self::USER_META_PRODUCT_ID, $product_id );
        $this->sync_legacy_video_product_meta( $creator_id, $product_id );

        return $product_id;
    }

    public function product_id_for_creator( $creator_id ) {
        $creator_id = absint( (int) $creator_id );
        if ( ! $creator_id ) {
            return 0;
        }

        $stored_product_id = absint( (int) get_user_meta( $creator_id, self::USER_META_PRODUCT_ID, true ) );
        if ( $stored_product_id && $this->is_creator_support_product( $stored_product_id, $creator_id ) ) {
            return $stored_product_id;
        }

        if ( $stored_product_id ) {
            delete_user_meta( $creator_id, self::USER_META_PRODUCT_ID );
        }

        $legacy_product_id = absint( (int) get_user_meta( $creator_id, self::LEGACY_VIDEO_USER_META_PRODUCT_ID, true ) );
        if ( $legacy_product_id && $this->is_legacy_video_support_product( $legacy_product_id, $creator_id ) ) {
            $this->adopt_legacy_video_product( $legacy_product_id, $creator_id );
            return $legacy_product_id;
        }

        $product_id = $this->find_existing_support_product( $creator_id );
        if ( $product_id ) {
            update_user_meta( $creator_id, self::USER_META_PRODUCT_ID, $product_id );
            $this->sync_legacy_video_product_meta( $creator_id, $product_id );
            return $product_id;
        }

        return 0;
    }

    public function is_creator_support_product( $product_id, $creator_id = 0 ) {
        $product_id = absint( (int) $product_id );
        $creator_id = absint( (int) $creator_id );

        if ( ! $product_id ) {
            return false;
        }

        $kind = (string) get_post_meta( $product_id, self::PRODUCT_META_KIND, true );
        if ( self::PRODUCT_KIND === $kind ) {
            if ( ! $creator_id ) {
                return true;
            }

            return $creator_id === absint( (int) get_post_meta( $product_id, self::PRODUCT_META_CREATOR_ID, true ) );
        }

        if ( $this->is_legacy_video_support_product( $product_id, $creator_id ) ) {
            $this->adopt_legacy_video_product( $product_id, $creator_id ? $creator_id : absint( (int) get_post_meta( $product_id, self::LEGACY_VIDEO_PRODUCT_META_CREATOR_ID, true ) ) );
            return true;
        }

        return false;
    }

    public function seller_product_capacity_state( $user_id ) {
        $user_id = absint( (int) $user_id );

        if ( class_exists( 'Koopo_Video_Access' ) ) {
            return Koopo_Video_Access::seller_product_capacity_state( $user_id );
        }

        $state = array(
            'user_id'               => $user_id,
            'pack_id'               => $this->vendor_pack_id( $user_id ),
            'can_create_product'    => false,
            'reason'                => 'seller_required',
            'message'               => __( 'Enable selling to create products for this creator profile.', 'koopo' ),
            'has_store_permissions' => $this->creator_has_store_permissions( $user_id ),
            'has_public_store'      => $this->creator_has_public_store( $user_id ),
            'current_products'      => 0,
            'max_products'          => 0,
            'remaining_products'    => 0,
            'unlimited'             => false,
        );

        if ( ! $user_id ) {
            return $state;
        }

        if ( $this->is_admin_bypass( $user_id ) ) {
            $state['can_create_product'] = true;
            $state['reason']             = 'admin_bypass';
            $state['message']            = __( 'Administrators can create support products without seller pack limits.', 'koopo' );
            $state['unlimited']          = true;
            return $state;
        }

        if ( ! $state['has_store_permissions'] ) {
            return $state;
        }

        if ( ! $state['has_public_store'] ) {
            $state['reason']  = 'seller_pending';
            $state['message'] = __( 'Your seller account is pending activation. Finish store setup before creating more products.', 'koopo' );
            return $state;
        }

        $state['current_products'] = $this->creator_product_count( $user_id );
        $remaining_products        = null;
        $max_products              = null;

        if ( class_exists( 'DokanPro\\Modules\\Subscription\\Helper' ) && method_exists( 'DokanPro\\Modules\\Subscription\\Helper', 'get_vendor_remaining_products' ) ) {
            $remaining = \DokanPro\Modules\Subscription\Helper::get_vendor_remaining_products( $user_id );
            if ( true === $remaining ) {
                $state['can_create_product'] = true;
                $state['reason']             = 'unlimited';
                $state['message']            = __( 'Support products count toward your seller product allowance.', 'koopo' );
                $state['unlimited']          = true;
                return $state;
            }

            if ( is_numeric( $remaining ) ) {
                $remaining_products = max( 0, (int) $remaining );
                $max_products       = $state['current_products'] + $remaining_products;
            }
        }

        if ( null === $remaining_products ) {
            $pack_limit = $this->vendor_pack_product_limit( $state['pack_id'] );

            if ( is_int( $pack_limit ) ) {
                if ( $pack_limit < 0 ) {
                    $state['can_create_product'] = true;
                    $state['reason']             = 'unlimited';
                    $state['message']            = __( 'Support products count toward your seller product allowance.', 'koopo' );
                    $state['unlimited']          = true;
                    return $state;
                }

                $max_products       = max( 0, $pack_limit );
                $remaining_products = max( 0, $max_products - $state['current_products'] );
            }
        }

        if ( null === $remaining_products ) {
            $state['can_create_product'] = true;
            $state['reason']             = 'module_unavailable';
            $state['message']            = __( 'Support products count toward your seller product allowance.', 'koopo' );
            $state['unlimited']          = true;
            return $state;
        }

        $state['max_products']       = max( 0, (int) $max_products );
        $state['remaining_products'] = max( 0, (int) $remaining_products );
        $state['can_create_product'] = $state['remaining_products'] > 0;

        if ( $state['can_create_product'] ) {
            $state['reason']  = 'limited';
            $state['message'] = sprintf(
                /* translators: 1: used product count, 2: total product count */
                __( 'Support products count toward your seller product allowance. %1$s of %2$s product slots are already in use.', 'koopo' ),
                number_format_i18n( (int) $state['current_products'] ),
                number_format_i18n( (int) $state['max_products'] )
            );
        } else {
            $state['reason']  = 'limit_reached';
            $state['message'] = __( 'You have reached the product limit for your current seller pack. Upgrade your seller subscription to add more products.', 'koopo' );
        }

        return $state;
    }

    private function order_item_matches_support_context( $item, $creator_id, $module ) {
        $flag = (string) $item->get_meta( self::ORDER_ITEM_META_FLAG, true );
        if ( '1' !== $flag ) {
            return false;
        }

        $item_creator_id = absint( (int) $item->get_meta( self::ORDER_ITEM_META_CREATOR_ID, true ) );
        if ( $item_creator_id !== $creator_id ) {
            return false;
        }

        if ( '' === $module ) {
            return true;
        }

        return $module === sanitize_key( (string) $item->get_meta( self::ORDER_ITEM_META_MODULE, true ) );
    }

    private function order_item_matches_legacy_video_support( $item, $creator_id ) {
        $flag = (string) $item->get_meta( self::LEGACY_VIDEO_ORDER_ITEM_FLAG, true );
        if ( '1' !== $flag ) {
            return false;
        }

        return $creator_id === absint( (int) $item->get_meta( self::LEGACY_VIDEO_ORDER_ITEM_CREATOR_ID, true ) );
    }

    private function request_is_support_checkout() {
        return '1' === (string) $this->request_param( self::REQUEST_FLAG );
    }

    private function requested_creator_id() {
        return absint( (int) $this->request_param( self::REQUEST_CREATOR_ID ) );
    }

    private function requested_amount() {
        return $this->normalize_amount( $this->request_param( self::REQUEST_AMOUNT ) );
    }

    private function requested_module() {
        $module = sanitize_key( (string) $this->request_param( self::REQUEST_MODULE ) );
        return '' !== $module ? $module : 'general';
    }

    private function requested_surface() {
        $surface = sanitize_key( (string) $this->request_param( self::REQUEST_SURFACE ) );
        return '' !== $surface ? $surface : 'default';
    }

    private function requested_context_id() {
        return absint( (int) $this->request_param( self::REQUEST_CONTEXT_ID ) );
    }

    private function requested_context_type() {
        return sanitize_key( (string) $this->request_param( self::REQUEST_CONTEXT_TYPE ) );
    }

    private function request_param( $key ) {
        if ( ! isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '';
        }

        return wp_unslash( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private function normalize_amount( $amount ) {
        $amount = max( 0, (float) $amount );
        return round( $amount, $this->price_decimals() );
    }

    private function price_decimals() {
        return function_exists( 'wc_get_price_decimals' ) ? absint( (int) wc_get_price_decimals() ) : 2;
    }

    private function paid_order_query_args( $days = 0 ) {
        $args = array(
            'limit'   => -1,
            'status'  => function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' ),
            'return'  => 'objects',
            'orderby' => 'date',
            'order'   => 'DESC',
        );

        $days = absint( (int) $days );
        if ( $days > 0 ) {
            $args['date_created'] = '>=' . gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        }

        return $args;
    }

    public function creator_display_name( $creator_id ) {
        $creator_id = absint( (int) $creator_id );
        if ( ! $creator_id ) {
            return '';
        }

        if ( class_exists( 'Koopo_Video_Frontend' ) && method_exists( 'Koopo_Video_Frontend', 'profile_channel_name' ) ) {
            $channel_name = (string) Koopo_Video_Frontend::profile_channel_name( $creator_id );
            if ( '' !== trim( $channel_name ) ) {
                return $channel_name;
            }
        }

        $fallback = (string) get_the_author_meta( 'display_name', $creator_id );
        return function_exists( 'koopo_get_user_display_name' ) ? koopo_get_user_display_name( $creator_id, $fallback ) : $fallback;
    }

    private function support_product_warning_text( $creator_name ) {
        return sprintf(
            /* translators: %s: creator name */
            __( 'Koopo system donation product for %s. Do not delete this product. If it is deleted, supporters will not be able to donate until you recreate the donation product.', 'koopo' ),
            $creator_name
        );
    }

    private function default_product_image_html( $size = 'woocommerce_thumbnail', $attr = array(), $product_id = 0 ) {
        $dimensions = $this->image_size_dimensions( $size );
        $classes    = array(
            'attachment-' . sanitize_html_class( is_string( $size ) ? $size : 'woocommerce_thumbnail' ),
            'size-' . sanitize_html_class( is_string( $size ) ? $size : 'woocommerce_thumbnail' ),
        );

        if ( ! empty( $attr['class'] ) ) {
            $classes[] = sanitize_html_class( (string) $attr['class'] );
        }

        $alt = $product_id ? get_the_title( $product_id ) : '';
        if ( '' === $alt ) {
            $alt = __( 'Support creators on Koopo', 'koopo' );
        }

        $extra_attrs = array(
            'src'     => esc_url( $this->default_product_image_url() ),
            'alt'     => esc_attr( $alt ),
            'class'   => esc_attr( implode( ' ', array_filter( $classes ) ) ),
            'loading' => 'lazy',
        );

        if ( ! empty( $dimensions['width'] ) ) {
            $extra_attrs['width'] = (string) absint( (int) $dimensions['width'] );
        }

        if ( ! empty( $dimensions['height'] ) ) {
            $extra_attrs['height'] = (string) absint( (int) $dimensions['height'] );
        }

        foreach ( (array) $attr as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( '' === $key || array_key_exists( $key, $extra_attrs ) ) {
                continue;
            }

            $extra_attrs[ $key ] = (string) $value;
        }

        $html = '<img';
        foreach ( $extra_attrs as $name => $value ) {
            $html .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
        }
        $html .= ' />';

        return $html;
    }

    private function default_product_image_url() {
        return plugins_url( 'assets/koopo-creator-support-product.svg', __FILE__ );
    }

    private function image_size_dimensions( $size ) {
        if ( is_array( $size ) ) {
            return array(
                'width'  => isset( $size[0] ) ? absint( (int) $size[0] ) : 600,
                'height' => isset( $size[1] ) ? absint( (int) $size[1] ) : 600,
            );
        }

        if ( function_exists( 'wc_get_image_size' ) && is_string( $size ) ) {
            $dimensions = wc_get_image_size( $size );
            if ( is_array( $dimensions ) ) {
                return array(
                    'width'  => isset( $dimensions['width'] ) ? absint( (int) $dimensions['width'] ) : 600,
                    'height' => isset( $dimensions['height'] ) ? absint( (int) $dimensions['height'] ) : 600,
                );
            }
        }

        return array(
            'width'  => 600,
            'height' => 600,
        );
    }

    private function find_existing_support_product( $creator_id ) {
        $generic_products = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'author'         => $creator_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => self::PRODUCT_META_CREATOR_ID,
                        'value' => $creator_id,
                    ),
                    array(
                        'key'   => self::PRODUCT_META_KIND,
                        'value' => self::PRODUCT_KIND,
                    ),
                ),
            )
        );

        if ( is_array( $generic_products ) && ! empty( $generic_products ) ) {
            return absint( (int) $generic_products[0] );
        }

        $legacy_products = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'author'         => $creator_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'   => self::LEGACY_VIDEO_PRODUCT_META_CREATOR_ID,
                        'value' => $creator_id,
                    ),
                    array(
                        'key'   => self::LEGACY_VIDEO_PRODUCT_META_KIND,
                        'value' => self::LEGACY_VIDEO_PRODUCT_KIND,
                    ),
                ),
            )
        );

        if ( is_array( $legacy_products ) && ! empty( $legacy_products ) ) {
            $product_id = absint( (int) $legacy_products[0] );
            $this->adopt_legacy_video_product( $product_id, $creator_id );
            return $product_id;
        }

        return 0;
    }

    private function adopt_legacy_video_product( $product_id, $creator_id ) {
        $product_id = absint( (int) $product_id );
        $creator_id = absint( (int) $creator_id );
        if ( ! $product_id || ! $creator_id ) {
            return;
        }

        update_post_meta( $product_id, self::PRODUCT_META_CREATOR_ID, $creator_id );
        update_post_meta( $product_id, self::PRODUCT_META_KIND, self::PRODUCT_KIND );
        update_user_meta( $creator_id, self::USER_META_PRODUCT_ID, $product_id );
        $this->sync_legacy_video_product_meta( $creator_id, $product_id );
    }

    private function sync_legacy_video_product_meta( $creator_id, $product_id ) {
        $creator_id = absint( (int) $creator_id );
        $product_id = absint( (int) $product_id );

        if ( ! $creator_id || ! $product_id ) {
            return;
        }

        update_user_meta( $creator_id, self::LEGACY_VIDEO_USER_META_PRODUCT_ID, $product_id );
        update_post_meta( $product_id, self::LEGACY_VIDEO_PRODUCT_META_CREATOR_ID, $creator_id );
        update_post_meta( $product_id, self::LEGACY_VIDEO_PRODUCT_META_KIND, self::LEGACY_VIDEO_PRODUCT_KIND );
    }

    private function is_legacy_video_support_product( $product_id, $creator_id = 0 ) {
        $product_id = absint( (int) $product_id );
        $creator_id = absint( (int) $creator_id );

        if ( ! $product_id ) {
            return false;
        }

        if ( self::LEGACY_VIDEO_PRODUCT_KIND !== (string) get_post_meta( $product_id, self::LEGACY_VIDEO_PRODUCT_META_KIND, true ) ) {
            return false;
        }

        if ( ! $creator_id ) {
            return true;
        }

        return $creator_id === absint( (int) get_post_meta( $product_id, self::LEGACY_VIDEO_PRODUCT_META_CREATOR_ID, true ) );
    }

    private function is_admin_bypass( $user_id = null ) {
        $user_id = $user_id ? absint( (int) $user_id ) : get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        if ( class_exists( 'Koopo_Video_Access' ) ) {
            return Koopo_Video_Access::is_admin_bypass( $user_id );
        }

        if ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) ) {
            return true;
        }

        return user_can( $user_id, 'manage_options' );
    }

    private function creator_has_store_permissions( $user_id ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( class_exists( 'Koopo_Video_Access' ) ) {
            return Koopo_Video_Access::creator_has_store_permissions( $user_id );
        }

        if ( function_exists( 'dokan_is_user_seller' ) ) {
            return (bool) dokan_is_user_seller( $user_id );
        }

        return (bool) apply_filters( 'koopo_creator_support_has_store_permissions', false, $user_id );
    }

    private function creator_has_public_store( $user_id ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( class_exists( 'Koopo_Video_Access' ) ) {
            return Koopo_Video_Access::creator_has_public_store( $user_id );
        }

        if ( ! $this->creator_has_store_permissions( $user_id ) ) {
            return false;
        }

        if ( function_exists( 'dokan_is_seller_enabled' ) && ! dokan_is_seller_enabled( $user_id ) ) {
            return false;
        }

        return (bool) apply_filters( 'koopo_creator_support_has_public_store', true, $user_id );
    }

    private function vendor_pack_id( $user_id ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        $meta_keys = array(
            'product_package_id',
            'dokan_subscription_pack_id',
            'dokan_product_subscription_pack_id',
        );

        foreach ( $meta_keys as $key ) {
            $candidate = absint( (int) get_user_meta( $user_id, $key, true ) );
            if ( $candidate > 0 ) {
                return $candidate;
            }
        }

        return 0;
    }

    private function creator_product_count( $user_id ) {
        global $wpdb;

        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        $allowed_statuses = array( 'publish', 'pending' );
        $placeholders     = implode( ',', array_fill( 0, count( $allowed_statuses ), '%s' ) );
        $query            = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'product' AND post_status IN ({$placeholders})";
        $params           = array_merge( array( $user_id ), $allowed_statuses );
        $count            = $wpdb->get_var( $wpdb->prepare( $query, $params ) );

        return absint( (int) $count );
    }

    private function vendor_pack_product_limit( $pack_id ) {
        $pack_id = absint( (int) $pack_id );
        if ( ! $pack_id ) {
            return null;
        }

        $value = get_post_meta( $pack_id, '_no_of_product', true );
        if ( '' === $value || null === $value ) {
            return null;
        }

        if ( '-1' === (string) $value ) {
            return -1;
        }

        if ( is_numeric( $value ) ) {
            return max( 0, (int) $value );
        }

        return null;
    }
}
