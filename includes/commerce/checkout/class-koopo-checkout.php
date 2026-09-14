<?php
/** Responsive presentation for the assigned classic WooCommerce checkout. */
defined( 'ABSPATH' ) || exit;

final class Koopo_Checkout {
    const VERSION = '2.71';
    private static $rendering = false;
    private static $modal = false;

    public static function modal_assets() {
        self::$modal = apply_filters( 'koopo_checkout_enabled', 'no' !== get_option( 'koopo_checkout_enabled', 'yes' ) );
        self::assets();
    }

    public static function boot() {
        add_filter( 'woocommerce_locate_template', array( __CLASS__, 'template' ), 50, 3 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
        add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'item_name' ), 30, 3 );
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
