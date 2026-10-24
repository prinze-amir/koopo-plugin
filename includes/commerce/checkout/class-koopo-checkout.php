<?php
/** Responsive presentation for the assigned classic WooCommerce checkout. */
defined( 'ABSPATH' ) || exit;

final class Koopo_Checkout {
    const VERSION = '2.80.1';
    private static $rendering = false;
    private static $modal = false;

    public static function modal_assets() {
        self::$modal = apply_filters( 'koopo_checkout_enabled', 'no' !== get_option( 'koopo_checkout_enabled', 'yes' ) );
        self::assets();
    }

    public static function boot() {
        add_filter( 'woocommerce_locate_template', array( __CLASS__, 'template' ), 50, 3 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'location_autocomplete_assets' ), 31 );
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
        add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'item_name' ), 30, 3 );
        add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'shipping_package_name' ), 30, 3 );
    }

    public static function page() {
        return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url()
            && is_page( wc_get_page_id( 'checkout' ) )
            && has_shortcode( get_post_field( 'post_content', wc_get_page_id( 'checkout' ) ), 'woocommerce_checkout' )
            && apply_filters( 'koopo_checkout_enabled', 'no' !== get_option( 'koopo_checkout_enabled', 'yes' ) );
    }

    private static function request() {
        if ( self::$rendering ) {
            return true;
        }
        if ( ! wp_doing_ajax() || 'no' === get_option( 'koopo_checkout_enabled', 'yes' ) ) {
            return false;
        }
        $data = array();
        if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
            parse_str( wp_unslash( $_POST['post_data'] ), $data );
        }
        return '1' === ( $data['koopo_checkout'] ?? '' ) && apply_filters( 'koopo_checkout_enabled', true );
    }

    public static function template( $template, $name, $path ) {
        if ( 'checkout/form-checkout.php' === $name && ( self::$modal || ( self::page() && ! wp_doing_ajax() ) ) ) {
            self::$rendering = true;
            return KOOPO_PATH . 'templates/woocommerce/checkout/form-checkout.php';
        }
        if ( 'checkout/review-order.php' === $name && self::request() ) {
            return KOOPO_PATH . 'templates/woocommerce/checkout/review-order.php';
        }
        return $template;
    }

    public static function assets() {
        if ( ! self::$modal && ! self::page() ) {
            return;
        }
        $url = plugins_url( '', __FILE__ );
        wp_enqueue_style( 'koopo-checkout', $url . '/checkout.css', array(), self::VERSION );
        wp_enqueue_script( 'koopo-checkout', $url . '/checkout.js', array( 'jquery', 'wc-checkout' ), self::VERSION, true );
        wp_localize_script( 'koopo-checkout', 'koopoCheckout', array(
            'nextPayment' => __( 'Continue to Payment', 'koopo' ),
            'nextReview' => __( 'Continue to Review', 'koopo' ),
            'required' => __( 'Complete the highlighted fields to continue.', 'koopo' ),
            'shipping' => __( 'Confirm your delivery address and choose a delivery option for each shipment.', 'koopo' ),
            'payment' => __( 'Choose an available payment method to continue.', 'koopo' ),
            'busy' => __( 'Updating your order…', 'koopo' ),
            'failed' => __( 'Your order could not be updated. Refresh the page and try again.', 'koopo' ),
            'deliveryCopy' => __( 'Let’s get your order on its way.', 'koopo' ),
            'paymentCopy' => __( 'Choose how you’d like to pay.', 'koopo' ),
            'reviewCopy' => __( 'Review your order details and place your order.', 'koopo' ),
            'backPayment' => __( '← Back to Payment', 'koopo' ),
            'backDelivery' => __( '← Back to Delivery', 'koopo' ),
            'changed' => __( 'Your order changed. Please review the updated total before continuing.', 'koopo' ),
            'checkMessage' => __( 'Please check the order message above and try again.', 'koopo' ),
            'noPayment' => __( 'No payment required', 'koopo' ),
            'noShipping' => __( 'No delivery address required', 'koopo' ),
        ) );
    }

    /** Add the shared address autocomplete to WooCommerce checkout and saved-address forms. */
    public static function location_autocomplete_assets() {
        $checkout = function_exists( 'is_checkout' ) && is_checkout() && !( function_exists( 'is_order_received_page' ) && is_order_received_page() );
        $account_address = function_exists( 'is_account_page' ) && is_account_page() && function_exists( 'is_wc_endpoint_url' )
            && is_wc_endpoint_url( 'edit-address' );
        if ( ! $checkout && ! $account_address && get_query_var( 'koopo-professional-profile', null ) === null ) {
            return;
        }

        wp_enqueue_script( 'koopo-location' );
        wp_enqueue_style( 'koopo-location-autocomplete', plugins_url( 'location-autocomplete.css', __FILE__ ), array(), self::VERSION );
        wp_enqueue_script( 'koopo-woocommerce-address-autocomplete', plugins_url( 'address-autocomplete.js', __FILE__ ), array( 'jquery', 'koopo-location' ), self::VERSION, true );
        wp_localize_script( 'koopo-woocommerce-address-autocomplete', 'koopoAddressAutocomplete', array(
            'minimumLength' => 4,
            'messages' => array(
                'label' => __( 'Address suggestions', 'koopo' ),
                'hint' => __( 'Type at least 4 characters to search. Choose a suggestion or continue entering your address manually.', 'koopo' ),
                'loading' => __( 'Searching addresses…', 'koopo' ),
                'empty' => __( 'No matching addresses found. Continue entering your address manually.', 'koopo' ),
                'error' => __( 'Address suggestions are temporarily unavailable. You can still enter your address manually.', 'koopo' ),
            ),
        ) );
    }

    public static function body_class( $classes ) {
        if ( self::page() ) {
            $classes[] = 'koopo-checkout-page';
        }
        return $classes;
    }

    public static function item_name( $name, $item, $key ) {
        if ( ! self::request() || empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
            return $name;
        }
        $seller = '';
        if ( function_exists( 'dokan' ) ) {
            $vendor = dokan()->vendor->get( (int) get_post_field( 'post_author', $item['product_id'] ) );
            $seller = $vendor ? $vendor->get_shop_name() : '';
        }
        return '<span class="kc-item-image">' . $item['data']->get_image( 'woocommerce_thumbnail' ) . '</span><span class="kc-item-name">' . $name . '</span>'
            . ( $seller ? '<small class="kc-item-seller">' . esc_html( $seller ) . '</small>' : '' );
    }

    /**
     * Add clear product context to each package heading above its rates.
     *
     * The package heading is rendered as the shipping row's <th>, so keeping
     * this context here makes it remain attached to the correct rate group
     * when the enhanced checkout moves the native WooCommerce rows.
     */
    public static function shipping_package_name( $name, $index = 0, $package = array() ) {
        if ( ! self::request() || empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
            return $name;
        }

        $product_context = '';
        $total_items = 0;
        foreach ( $package['contents'] as $item ) {
            $product = isset( $item['data'] ) ? $item['data'] : false;
            if ( ! $product instanceof WC_Product || ! $product->exists() || empty( $item['quantity'] ) ) {
                continue;
            }

            $quantity = (int) $item['quantity'];
            $total_items += $quantity;
            $product_context .= '<span class="kc-shipping-product">'
                . '<span class="kc-shipping-product-image">' . $product->get_image( 'woocommerce_thumbnail' ) . '</span>'
                . '<span class="kc-shipping-product-copy"><span class="kc-shipping-product-name">' . esc_html( $product->get_name() ) . '</span>'
                . '<span class="kc-shipping-product-quantity">' . esc_html( sprintf( __( 'Quantity: %d', 'koopo' ), $quantity ) ) . '</span></span></span>';
        }

        if ( '' === $product_context ) {
            return $name;
        }

        return '<span class="kc-shipping-package-name">' . esc_html( wp_strip_all_tags( $name ) ) . '</span>'
            . '<span class="kc-shipping-package-summary">' . esc_html( sprintf( _n( '%d item in this package', '%d items in this package', $total_items, 'koopo' ), $total_items ) ) . '</span>'
            . '<span class="kc-shipping-products">' . $product_context . '</span>';
    }

    public static function icon( $name ) {
        $paths = array(
            'contact' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3Z"/>',
            'address' => '<path d="M20 10c0 6-8 13-8 13S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
            'payment' => '<rect x="2" y="4" width="20" height="16" rx="3"/><path d="M2 9h20M6 15h4"/>',
            'delivery' => '<path d="M2 5h12v13H2ZM14 10h4l4 5v3h-8"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',
        );
        return '<span class="kc-icon" aria-hidden="true"><svg viewBox="0 0 24 26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $name ] ?? $paths['address'] ) . '</svg></span>';
    }
}
