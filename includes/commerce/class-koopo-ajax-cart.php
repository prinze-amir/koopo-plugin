<?php
/** Progressive enhancement for classic WooCommerce product forms and loops. */
defined( 'ABSPATH' ) || exit;

final class Koopo_Ajax_Cart {
    const VERSION = '2.61';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'init' ), 20 );
    }

    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) || ! apply_filters( 'koopo_ajax_cart_enabled', 'no' !== get_option( 'koopo_ajax_cart_enabled', 'yes' ) ) ) {
            return;
        }
        if ( isset( $_GET['wc-ajax'] ) && in_array( $_GET['wc-ajax'], array( 'koopo_cart_add', 'koopo_product_view' ), true ) ) {
            // Reject malformed requests in our endpoint before any native form mutation can occur.
            remove_action( 'wp_loaded', array( 'WC_Form_Handler', 'add_to_cart_action' ), 20 );
        }
        add_filter( 'woocommerce_loop_add_to_cart_args', array( __CLASS__, 'loop_args' ), 30, 2 );
        add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'form_marker' ) );
        add_action( 'wc_ajax_koopo_product_view', array( __CLASS__, 'quick_view' ) );
        add_action( 'wc_ajax_koopo_cart_add', array( __CLASS__, 'add' ) );
        add_action( 'wp_footer', array( __CLASS__, 'footer_styles' ), 19 );
    }

    public static function supported( $product ) {
        return $product instanceof WC_Product
            && in_array( $product->get_type(), array( 'simple', 'variable', 'grouped' ), true )
            && 'publish' === $product->get_status()
            && ! post_password_required( $product->get_id() )
            && ! $product->get_meta( '_koopo_creator_support_creator_id' )
            && ! $product->get_meta( '_koopo_ticket_type_id' )
            && 'yes' !== $product->get_meta( '_koopo_pd_design_enabled' )
            && ! isset( $product->get_attributes()['ticket-type'] )
            && apply_filters( 'koopo_ajax_cart_product_supported', true, $product );
    }

    public static function assets( $options = false ) {
        $base = plugins_url( 'assets/', __FILE__ );
        wp_enqueue_style( 'koopo-ajax-cart', $base . 'koopo-ajax-cart.css', array(), self::VERSION );
        wp_enqueue_script( 'koopo-ajax-cart', $base . 'koopo-ajax-cart.js', array( 'jquery', 'wc-add-to-cart' ), self::VERSION, true );
        if ( $options && ! in_array( 'wc-add-to-cart-variation', wp_scripts()->registered['koopo-ajax-cart']->deps, true ) ) {
            wp_scripts()->registered['koopo-ajax-cart']->deps[] = 'wc-add-to-cart-variation';
        }
        static $localized = false;
        if ( ! $localized ) {
            wp_localize_script( 'koopo-ajax-cart', 'koopoCart', array(
                'viewUrl' => WC_AJAX::get_endpoint( 'koopo_product_view' ),
                'addUrl' => WC_AJAX::get_endpoint( 'koopo_cart_add' ),
                'cartUrl' => wc_get_cart_url(),
                'adding' => __( 'Adding…', 'koopo' ),
                'added' => __( 'Added', 'koopo' ),
                'loading' => __( 'Loading product…', 'koopo' ),
                'close' => __( 'Close product', 'koopo' ),
                'viewCart' => __( 'View cart', 'koopo' ),
                'options' => __( 'Please choose your product options.', 'koopo' ),
                'uncertain' => __( 'We could not confirm the result. Check your cart before trying again.', 'koopo' ),
                'loadError' => __( 'The product could not be loaded. Open its page to choose your options.', 'koopo' ),
                'viewProduct' => __( 'View product', 'koopo' ),
                'back' => __( 'Back to group', 'koopo' ),
            ) );
            $localized = true;
        }
    }

    public static function footer_styles() {
        if ( wp_style_is( 'koopo-ajax-cart', 'enqueued' ) ) {
            wp_print_styles( 'koopo-ajax-cart' );
        }
    }

    public static function loop_args( $args, $product ) {
        if ( ! self::supported( $product ) || ! $product->is_in_stock() || ( $product->is_type( 'simple' ) && ! $product->is_purchasable() ) ) {
            return $args;
        }
        self::assets( ! $product->is_type( 'simple' ) );
        $args['attributes']['data-koopo-product'] = $product->get_id();
        $args['attributes']['data-koopo-cart'] = $product->is_type( 'simple' ) && ! $product->has_options() ? 'add' : 'view';
        if ( 'view' === $args['attributes']['data-koopo-cart'] ) {
            $args['attributes']['aria-haspopup'] = 'dialog';
        }
        return $args;
    }

    public static function form_marker() {
        global $product;
        if ( self::supported( $product ) ) {
            self::assets( ! $product->is_type( 'simple' ) );
            echo '<input type="hidden" name="koopo_product_id" value="' . esc_attr( $product->get_id() ) . '">';
        }
    }

    /** Custom header requires same-origin JS; no cached page nonce or guest session bootstrap. */
    private static function request_product() {
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
        $expected = wp_parse_url( home_url() );
        $actual = $origin ? wp_parse_url( $origin ) : $expected;
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || '1' !== ( $_SERVER['HTTP_X_KOOPO_CART'] ?? '' )
            || 'cross-site' === ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' )
            || ! is_array( $actual ) || ( $actual['host'] ?? '' ) !== ( $expected['host'] ?? '' )
            || ( $actual['scheme'] ?? '' ) !== ( $expected['scheme'] ?? '' )
            || ( $actual['port'] ?? null ) !== ( $expected['port'] ?? null ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid cart request.', 'koopo' ) ), 403 );
        }
        $id = isset( $_POST['koopo_product_id'] ) && is_scalar( $_POST['koopo_product_id'] ) ? absint( $_POST['koopo_product_id'] ) : 0;
        $product = wc_get_product( $id );
        if ( ! self::supported( $product ) ) {
            wp_send_json_error( array( 'message' => __( 'Please open the product page to continue.', 'koopo' ) ), 400 );
        }
        return $product;
    }

    public static function quick_view() {
        $item = self::request_product();
        global $product, $post;
        $previous_product = $product;
        $previous_post = $post;
        $product = $item;
        $post = get_post( $item->get_id() );
        setup_postdata( $post );
        ob_start();
        echo '<div class="koopo-qv-layout product">';
        echo '<div class="koopo-qv-image">' . $item->get_image( 'woocommerce_single' ) . '</div>';
        echo '<div class="koopo-qv-details summary"><h2 id="koopo-qv-title">' . esc_html( $item->get_name() ) . '</h2>';
        echo '<div class="price">' . wp_kses_post( $item->get_price_html() ) . '</div>';
        echo '<div class="woocommerce-product-details__short-description">' . wp_kses_post( wpautop( $item->get_short_description() ) ) . '</div>';
        woocommerce_template_single_add_to_cart();
        echo '<a class="koopo-qv-product-link" href="' . esc_url( $item->get_permalink() ) . '">' . esc_html__( 'View full product details', 'koopo' ) . '</a></div></div>';
        $html = ob_get_clean();
        $product = $previous_product;
        $post = $previous_post;
        if ( $post ) { setup_postdata( $post ); }
        wp_send_json_success( array( 'html' => $html ) );
    }

    public static function add() {
        $product = self::request_product();
        // Never send add-to-cart from the browser: WC's wp_loaded handler would run before this endpoint.
        if ( isset( $_POST['add-to-cart'] ) || isset( $_GET['add-to-cart'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid cart request.', 'koopo' ) ), 400 );
        }
        $quantity = $_POST['quantity'] ?? 1;
        if ( $product->is_type( 'grouped' ) ) {
            if ( ! is_array( $quantity ) || count( $quantity ) > 100 ) {
                wp_send_json_error( array( 'message' => __( 'Choose product quantities.', 'koopo' ) ), 400 );
            }
            foreach ( $quantity as $id => $amount ) {
                $child = wc_get_product( absint( $id ) );
                if ( ! is_scalar( $amount ) || ( '' !== $amount && ! is_numeric( $amount ) ) || (float) $amount < 0
                    || ! in_array( (int) $id, $product->get_children(), true )
                    || ! self::supported( $child ) || ! $child->is_type( 'simple' ) || $child->has_options() ) {
                    wp_send_json_error( array( 'message' => __( 'Choose this item’s options on its product page.', 'koopo' ) ), 400 );
                }
            }
        } elseif ( ! is_scalar( $quantity ) || ! is_numeric( $quantity ) || $quantity <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Choose a valid quantity.', 'koopo' ) ), 400 );
        }
        foreach ( $_POST as $key => $value ) {
            if ( ( 'variation_id' === $key || 0 === strpos( $key, 'attribute_' ) ) && ! is_scalar( $value ) ) {
                wp_send_json_error( array( 'message' => __( 'Choose valid product options.', 'koopo' ) ), 400 );
            }
        }
        // Delegate to Woo's native simple/variable/grouped handlers and extension validation hooks.
        $_REQUEST = $_POST;
        $_REQUEST['add-to-cart'] = $product->get_id();
        $added = array();
        $record = static function ( $key, $id, $qty ) use ( &$added ) { $added[ $id ] = $qty; };
        add_action( 'woocommerce_add_to_cart', $record, 100, 3 );
        add_filter( 'woocommerce_add_to_cart_redirect', '__return_false', PHP_INT_MAX );
        $no_redirect = static function () { return 'no'; };
        add_filter( 'pre_option_woocommerce_cart_redirect_after_add', $no_redirect );
        wc_clear_notices();
        try {
            WC_Form_Handler::add_to_cart_action();
        } catch ( Exception $error ) {
            wc_add_notice( __( 'The product could not be added. Please check your cart before trying again.', 'koopo' ), 'error' );
        } finally {
            remove_action( 'woocommerce_add_to_cart', $record, 100 );
            remove_filter( 'woocommerce_add_to_cart_redirect', '__return_false', PHP_INT_MAX );
            remove_filter( 'pre_option_woocommerce_cart_redirect_after_add', $no_redirect );
        }
        $ok = ! empty( $added ) && 0 === wc_notice_count( 'error' );
        if ( empty( $added ) && ! wc_notice_count( 'error' ) ) {
            wc_add_notice( __( 'The product was not added. Please check your selections.', 'koopo' ), 'error' );
        }
        $notices = wc_print_notices( true );
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
        wp_send_json_success( array(
            'added' => $ok,
            'added_ids' => array_keys( $added ),
            'notices' => $notices,
            'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>' ) ),
            'cart_hash' => WC()->cart->get_cart_hash(),
        ) );
    }
}
