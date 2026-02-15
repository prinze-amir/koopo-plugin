<?php
// koopo/includes/dokan/class-koopo-dokan-upgrade.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Dokan_Upgrade {
    const VERSION = '2.9.0'; // bump this to verify server deployment
    private static $instance = null;
    private $namespace = 'koopo/v1';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_hooks' ] );
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_hooks() {
        // reserved for future hooks
    }

    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        wp_register_style( 'koopo-upgrade-css', plugins_url( 'assets/koopo-upgrade.css', __FILE__ ) );
        wp_enqueue_style( 'koopo-upgrade-css' );

        wp_register_script( 'koopo-upgrade-js', plugins_url( 'assets/koopo-upgrade.js', __FILE__ ), [ 'jquery' ], false, true );
        wp_enqueue_script( 'koopo-upgrade-js' );

        // Get Stripe publishable key from Dokan Stripe Helper if available
        $stripe_pubkey = $this->get_stripe_publishable_key();

        wp_localize_script( 'koopo-upgrade-js', 'KoopoUpgradeData', [
            'restUrl' => esc_url_raw( rest_url( $this->namespace . '/upgrade' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'currentUserId' => get_current_user_id(),
            'stripePublishableKey' => esc_attr( $stripe_pubkey ),
            'version' => self::VERSION,
        ] );
    }

    /**
     * Get Stripe publishable key from Dokan Stripe helpers
     */
    private function get_stripe_publishable_key() {
        // Try Stripe Express first (if installed)
        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Settings' ) ) {
            $key = \WeDevs\DokanPro\Modules\StripeExpress\Support\Settings::get_publishable_key();
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Fallback to regular Dokan Stripe Helper
        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\Stripe\\Helper' ) ) {
            $key = \WeDevs\DokanPro\Modules\Stripe\Helper::get_publishable_key();
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Final fallback to WooCommerce Stripe settings
        $stripe_settings = get_option( 'woocommerce_stripe_settings', [] );
        if ( ! empty( $stripe_settings['testmode'] ) && 'yes' === $stripe_settings['testmode'] ) {
            return isset( $stripe_settings['test_publishable_key'] ) ? $stripe_settings['test_publishable_key'] : '';
        }
        return isset( $stripe_settings['publishable_key'] ) ? $stripe_settings['publishable_key'] : '';
    }

    /**
     * Get Stripe secret key from Dokan Stripe helpers
     */
    private function get_stripe_secret_key() {
        // Try Stripe Express first (if installed)
        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Settings' ) ) {
            $key = \WeDevs\DokanPro\Modules\StripeExpress\Support\Settings::get_secret_key();
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Fallback to regular Dokan Stripe Helper
        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\Stripe\\Helper' ) ) {
            $key = \WeDevs\DokanPro\Modules\Stripe\Helper::get_secret_key();
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Final fallback to WooCommerce Stripe settings
        $stripe_settings = get_option( 'woocommerce_stripe_settings', [] );
        if ( ! empty( $stripe_settings['testmode'] ) && 'yes' === $stripe_settings['testmode'] ) {
            return isset( $stripe_settings['test_secret_key'] ) ? $stripe_settings['test_secret_key'] : '';
        }
        return isset( $stripe_settings['secret_key'] ) ? $stripe_settings['secret_key'] : '';
    }

    /**
     * Get the Stripe Customer ID for a vendor.
     * Dokan Stripe Express stores it via get_user_option() with key
     * _dokan_stripe_express_customer_id (live) or _dokan_stripe_express_test_customer_id (test).
     */
    private function get_stripe_customer_id( $vendor_id ) {
        // Determine if Dokan Stripe Express is in test mode
        $is_test = false;
        if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Settings' ) ) {
            $is_test = \WeDevs\DokanPro\Modules\StripeExpress\Support\Settings::is_test_mode();
        }

        // Check mode-appropriate key first (uses get_user_option, not get_user_meta)
        if ( $is_test ) {
            $customer_id = get_user_option( '_dokan_stripe_express_test_customer_id', $vendor_id );
        } else {
            $customer_id = get_user_option( '_dokan_stripe_express_customer_id', $vendor_id );
        }

        // Fallback: try the other mode key
        if ( empty( $customer_id ) ) {
            $alt_key = $is_test ? '_dokan_stripe_express_customer_id' : '_dokan_stripe_express_test_customer_id';
            $customer_id = get_user_option( $alt_key, $vendor_id );
        }

        // Fallback: try get_user_meta (single-site compatibility)
        if ( empty( $customer_id ) ) {
            $meta_key = $is_test ? '_dokan_stripe_express_test_customer_id' : '_dokan_stripe_express_customer_id';
            $customer_id = get_user_meta( $vendor_id, $meta_key, true );
        }

        // Legacy Dokan Stripe Connect key
        if ( empty( $customer_id ) ) {
            $customer_id = get_user_meta( $vendor_id, 'dokan_stripe_customer_id', true );
        }

        return $customer_id ?: '';
    }

    public function register_routes() {
        register_rest_route( $this->namespace, '/upgrade/packs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_packs' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ] );

        register_rest_route( $this->namespace, '/upgrade/subscription', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_current_subscription' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ] );

        register_rest_route( $this->namespace, '/upgrade/payment-methods', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_payment_methods' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ] );

        register_rest_route( $this->namespace, '/upgrade/calc', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_calc' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ] );

        register_rest_route( $this->namespace, '/upgrade/pay', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_pay' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ] );

        register_rest_route( $this->namespace, '/upgrade/finalize', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_finalize' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ] );
    }

    /**
     * Get available subscription packs for vendor
     */
    public function rest_get_packs() {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'tax_query' => [
                [
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => 'product_pack',
                ]
            ],
        ];
        $packs_query = new WP_Query( $args );
        $packs = [];
        
        if ( $packs_query->have_posts() ) {
            while ( $packs_query->have_posts() ) {
                $packs_query->the_post();
                $product = wc_get_product( get_the_ID() );
                $packs[] = [
                    'id' => intval( get_the_ID() ),
                    'title' => get_the_title(),
                    'price' => wc_format_decimal( $product->get_price(), 2 ),
                    'price_html' => $product->get_price_html(),
                ];
            }
            wp_reset_postdata();
        }
        
        return rest_ensure_response( $packs );
    }

    /**
     * Get current vendor subscription
     */
    public function rest_get_current_subscription() {
        $vendor_id = get_current_user_id();
        $pack_id = get_user_meta( $vendor_id, 'product_package_id', true );
        
        if ( empty( $pack_id ) ) {
            return new WP_REST_Response( null, 404 );
        }
        
        $product = wc_get_product( intval( $pack_id ) );
        
        return rest_ensure_response( [
            'product_package_id' => intval( $pack_id ),
            'product_id' => intval( $pack_id ),
            'price' => wc_format_decimal( $product->get_price(), 2 ),
            'title' => $product->get_title(),
            'start_date' => get_user_meta( $vendor_id, 'product_pack_startdate', true ),
            'end_date' => get_user_meta( $vendor_id, 'product_pack_enddate', true ),
        ] );
    }

    public function rest_calc( WP_REST_Request $request ) {
        $vendor_id = $request->get_param( 'vendor_id' ) ? intval( $request->get_param( 'vendor_id' ) ) : get_current_user_id();
        $new_pack_id = intval( $request->get_param( 'new_pack_id' ) );

        if ( ! $vendor_id || ! $new_pack_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing vendor_id or new_pack_id' ], 400 );
        }

        $current_pack_id = get_user_meta( $vendor_id, 'product_package_id', true );
        $start_date = get_user_meta( $vendor_id, 'product_pack_startdate', true );
        $end_date = get_user_meta( $vendor_id, 'product_pack_enddate', true );

        if ( ! $current_pack_id || ! $start_date || ! $end_date ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'No active Dokan subscription found for vendor'
            ], 400 );
        }

        $current_product = wc_get_product( intval( $current_pack_id ) );
        $new_product = wc_get_product( $new_pack_id );

        if ( ! $current_product || ! $new_product ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid pack IDs' ], 400 );
        }

        $current_price = floatval( $current_product->get_price() );
        $new_price = floatval( $new_product->get_price() );

        if ( $new_price <= $current_price ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Selected pack is not an upgrade'
            ], 400 );
        }

        // Parse dates more reliably - handle multiple formats and unlimited subscriptions
        $start_ts = is_numeric( $start_date ) ? intval( $start_date ) : strtotime( $start_date );
        
        // If end_date is the literal 'unlimited' Dokan sometimes uses that
        // for recurring subscriptions. Try to derive the current period length
        // from the pack product meta so we can compute proration. If no
        // period meta exists, fall back to no proration behaviour.
        $derived_debug = null;
        if ( is_string( $end_date ) && 'unlimited' === strtolower( $end_date ) ) {
            $period = get_post_meta( intval( $current_pack_id ), '_dokan_subscription_period', true );
            if ( empty( $period ) ) {
                // try alternate meta keys used by some Dokan versions
                $period = get_post_meta( intval( $current_pack_id ), '_subscription_period', true );
            }
            $interval = intval( get_post_meta( intval( $current_pack_id ), '_dokan_subscription_period_interval', true ) );
            if ( empty( $interval ) ) {
                $interval = intval( get_post_meta( intval( $current_pack_id ), '_subscription_period_interval', true ) );
            }
            if ( $interval < 1 ) {
                $interval = 1;
            }

            if ( ! empty( $period ) ) {
                $period = strtolower( $period );
                switch ( $period ) {
                    case 'day':
                        $days_per_unit = 1;
                        break;
                    case 'week':
                        $days_per_unit = 7;
                        break;
                    case 'month':
                        $days_per_unit = 30;
                        break;
                    case 'year':
                        $days_per_unit = 365;
                        break;
                    default:
                        $days_per_unit = 30;
                        break;
                }

                $days_total = max( 1, $interval * $days_per_unit );
                $start_ts = is_numeric( $start_date ) ? intval( $start_date ) : strtotime( $start_date );
                $end_ts = $start_ts + ( $days_total * DAY_IN_SECONDS );

                $derived_debug = [
                    'message' => 'Derived end_ts from product meta',
                    'period_meta' => $period,
                    'period_interval' => $interval,
                    'derived_days_total' => $days_total,
                ];
            } else {
                // No period meta available — treat as non-proratable unlimited
                $current_product = wc_get_product( intval( $current_pack_id ) );
                $new_product = wc_get_product( $new_pack_id );

                if ( ! $current_product || ! $new_product ) {
                    return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid pack IDs' ], 400 );
                }

                $current_price = floatval( $current_product->get_price() );
                $new_price = floatval( $new_product->get_price() );

                if ( $new_price <= $current_price ) {
                    return new WP_REST_Response( [
                        'success' => false,
                        'message' => 'Selected pack is not an upgrade'
                    ], 400 );
                }

                return rest_ensure_response( [
                    'success' => true,
                    'data' => [
                        'current_pack_id' => intval( $current_pack_id ),
                        'current_price' => wc_format_decimal( $current_price, wc_get_price_decimals() ),
                        'new_pack_id' => $new_pack_id,
                        'new_price' => wc_format_decimal( $new_price, wc_get_price_decimals() ),
                        'days_remaining' => 0,
                        'days_total' => 0,
                        'credit' => wc_format_decimal( 0, wc_get_price_decimals() ),
                        'first_payment' => wc_format_decimal( $new_price, wc_get_price_decimals() ),
                        '_debug' => [
                            'message' => 'Unlimited subscription and no product period meta - no proration',
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                        ],
                    ],
                ] );
            }
        }
        
        if ( empty( $end_ts ) ) {
            $end_ts = is_numeric( $end_date ) ? intval( $end_date ) : strtotime( $end_date );
        }
        
        // If strtotime failed, try parsing as ISO date format
        if ( ! $start_ts && ! empty( $start_date ) ) {
            $start_ts = strtotime( str_replace( 'T', ' ', $start_date ) );
        }
        if ( ! $end_ts && ! empty( $end_date ) ) {
            $end_ts = strtotime( str_replace( 'T', ' ', $end_date ) );
        }
        
        $now = current_time( 'timestamp' );

        // Validate timestamp parsing - provide detailed error
        if ( ! $start_ts || ! $end_ts ) {
            $debug = [
                'start_date_raw' => $start_date,
                'end_date_raw' => $end_date,
                'start_ts' => $start_ts,
                'end_ts' => $end_ts,
                'now' => $now,
            ];
            dokan_log( 'Upgrade calc error - invalid dates: ' . json_encode( $debug ) );
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Invalid subscription dates',
                '_debug' => $debug,
            ], 400 );
        }

        // Calculate days
        if ( $end_ts <= $now ) {
            // Subscription already expired
            $days_remaining = 0;
            $days_total = max( 1, ceil( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) );
        } else {
            // Subscription still active
            $days_remaining = ceil( ( $end_ts - $now ) / DAY_IN_SECONDS );
            $days_total = max( 1, ceil( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) );
        }

        // Calculate credit (pro-rata based on days remaining)
        $credit = ( $days_remaining / $days_total ) * $current_price;
        $first_payment = max( 0, $new_price - $credit );

        return rest_ensure_response( [
            'success' => true,
            'data' => [
                'current_pack_id' => intval( $current_pack_id ),
                'current_price' => wc_format_decimal( $current_price, wc_get_price_decimals() ),
                'new_pack_id' => $new_pack_id,
                'new_price' => wc_format_decimal( $new_price, wc_get_price_decimals() ),
                'days_remaining' => intval( $days_remaining ),
                'days_total' => intval( $days_total ),
                'credit' => wc_format_decimal( $credit, wc_get_price_decimals() ),
                'first_payment' => wc_format_decimal( $first_payment, wc_get_price_decimals() ),
                '_debug' => [
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'start_ts' => $start_ts,
                    'end_ts' => $end_ts,
                    'now' => $now,
                    'derived' => $derived_debug,
                ],
            ],
        ] );
    }

    public function rest_pay( WP_REST_Request $request ) {
        $vendor_id = $request->get_param( 'vendor_id' ) ? intval( $request->get_param( 'vendor_id' ) ) : get_current_user_id();
        $new_pack_id = intval( $request->get_param( 'new_pack_id' ) );
        $payment_method_id = $request->get_param( 'payment_method_id' ) ? sanitize_text_field( $request->get_param( 'payment_method_id' ) ) : '';

        if ( ! $vendor_id || ! $new_pack_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing vendor_id or new_pack_id' ], 400 );
        }

        // Recalculate to ensure consistency
        $calc_req = new WP_REST_Request( 'POST' );
        $calc_req->set_body_params( [ 'vendor_id' => $vendor_id, 'new_pack_id' => $new_pack_id ] );
        $calc_resp = $this->rest_calc( $calc_req );

        if ( is_wp_error( $calc_resp ) || empty( $calc_resp->data ) || empty( $calc_resp->data['success'] ) || ! isset( $calc_resp->data['data'] ) ) {
            // If the response has error info, return it; otherwise return a generic error.
            if ( is_wp_error( $calc_resp ) || ! empty( $calc_resp->data ) ) {
                return $calc_resp;
            }
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Proration calculation failed' ], 500 );
        }
        $calc_data = $calc_resp->data['data'];
        $amount = floatval( $calc_data['first_payment'] );

        // Use Dokan/Stripe helpers when available to compute the correct
        // amount in the smallest currency unit (cents). Fall back to basic
        // rounding if helpers are not available.
        $amount_cents = 0;
        if ( class_exists( '\WeDevs\DokanPro\Modules\StripeExpress\Support\Helper' ) ) {
            $amount_cents = \WeDevs\DokanPro\Modules\StripeExpress\Support\Helper::get_stripe_amount( $amount, strtolower( get_woocommerce_currency() ) );
        } elseif ( class_exists( '\WeDevs\DokanPro\Modules\Stripe\Helper' ) ) {
            $amount_cents = \WeDevs\DokanPro\Modules\Stripe\Helper::get_stripe_amount( $amount );
        } else {
            $amount_cents = round( $amount * 100 );
        }

        // Get Stripe secret key using Dokan Stripe Helper
        $secret = $this->get_stripe_secret_key();

        if ( empty( $secret ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Stripe secret key not configured.' ], 500 );
        }

        if ( ! class_exists( '\\Stripe\\StripeClient' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Stripe PHP library not available on server.' ], 500 );
        }

        try {
            // If the computed amount is zero (or not a positive amount after
            // currency conversion), we won't create a Stripe PaymentIntent.
            // Instead, complete the upgrade immediately (no charge) and
            // ensure the previous subscription is cancelled so renewals don't overlap.
            if ( 0 >= $amount_cents ) {
                // Create an order and mark it completed immediately
                $order = wc_create_order();
                $order->add_product( wc_get_product( $new_pack_id ), 1 );
                $order->set_customer_id( $vendor_id );

                $credit = floatval( $calc_data['credit'] );
                if ( $credit > 0 ) {
                    $fee = new \WC_Order_Item_Fee();
                    $fee->set_name( 'Prorated Discount (subscription credit)' );
                    $fee->set_amount( -$credit );
                    $fee->set_total( -$credit );
                    $order->add_item( $fee );
                }

                $order->set_total( $amount );
                $order->set_payment_method( 'dokan_stripe_express' );
                $order->set_payment_method_title( 'Stripe' );
                $order->update_status( 'completed', 'Upgrade applied - no payment required' );
                $order->update_meta_data( '_upgrade_proration_discount', $credit );
                $order->update_meta_data( '_is_upgrade_purchase', 'yes' );
                $order->save();

                // Read previous subscription data BEFORE activation overwrites product_order_id
                $previous_order_id = get_user_meta( $vendor_id, 'product_order_id', true );
                $old_stripe_sub_id = get_user_meta( $vendor_id, '_dokan_stripe_express_subscription_id', true );

                // Activate new subscription FIRST, before canceling old one
                $activation_method = 'none';
                $class_exists = class_exists( '\WeDevs\DokanPro\Modules\Subscription\SubscriptionPack' );
                if ( $new_pack_id > 0 ) {
                    if ( $class_exists ) {
                        $subscription = new \WeDevs\DokanPro\Modules\Subscription\SubscriptionPack( $new_pack_id, $vendor_id );
                        $subscription->activate_subscription( $order );
                        $activation_method = 'dokan_api';

                        $activated_pack = get_user_meta( $vendor_id, 'product_package_id', true );
                        if ( intval( $activated_pack ) !== intval( $new_pack_id ) ) {
                            error_log( "Koopo Upgrade (\$0 path): activate_subscription() did not set product_package_id. Manual fallback." );
                            $this->manual_activate_subscription( $vendor_id, $new_pack_id, $order );
                            $activation_method = 'manual_fallback';
                        }
                    } else {
                        $this->manual_activate_subscription( $vendor_id, $new_pack_id, $order );
                        $activation_method = 'manual_direct';
                    }
                }

                // Create Stripe Subscription for future recurring billing
                $is_recurring = 'yes' === get_post_meta( $new_pack_id, '_enable_recurring_payment', true );
                if ( $new_pack_id > 0 && $is_recurring ) {
                    $this->create_stripe_subscription( $vendor_id, $new_pack_id, $order );
                }

                // Cancel old Stripe subscription — break webhook lookup chain first
                // (see rest_finalize() for detailed explanation of why this is needed)
                if ( ! empty( $previous_order_id ) && intval( $previous_order_id ) !== intval( $order->get_id() ) ) {
                    $old_order = wc_get_order( intval( $previous_order_id ) );

                    if ( ! empty( $old_stripe_sub_id ) ) {
                        try {
                            $secret = $this->get_stripe_secret_key();
                            if ( ! empty( $secret ) ) {
                                $stripe_client = new \Stripe\StripeClient( $secret );

                                // Clear old order meta so webhook can't find vendor via order lookup
                                if ( $old_order ) {
                                    $old_order->delete_meta_data( '_dokan_stripe_express_stripe_subscription_id' );
                                    $old_order->save();
                                }

                                // Clear Stripe subscription metadata so fallback also fails
                                try {
                                    $stripe_client->subscriptions->update( $old_stripe_sub_id, [
                                        'metadata' => [ 'order_id' => '' ],
                                    ] );
                                } catch ( \Throwable $meta_err ) {
                                    error_log( 'Koopo Upgrade ($0 path): clear Stripe sub metadata failed: ' . $meta_err->getMessage() );
                                }

                                // Now safe to cancel — webhook handler won't find vendor
                                $stripe_client->subscriptions->cancel( $old_stripe_sub_id );
                            }
                        } catch ( \Throwable $stripe_err ) {
                            error_log( 'Koopo Upgrade ($0 path): cancel old Stripe sub failed: ' . $stripe_err->getMessage() );
                        }
                    }

                    if ( $old_order && ! in_array( $old_order->get_status(), [ 'cancelled', 'refunded' ], true ) ) {
                        $old_order->update_status( 'cancelled', 'Subscription upgraded to new plan.' );
                    }
                }

                return rest_ensure_response([
                    'success' => true,
                    'data' => [
                        'no_payment_required' => true,
                        'order_id' => $order->get_id(),
                        'first_payment' => $amount,
                        'activation_method' => $activation_method,
                    ],
                ]);
            }

            $stripe = new \Stripe\StripeClient( $secret );

            // Build rich metadata for Stripe dashboard and email receipts
            $new_product_obj = wc_get_product( $new_pack_id );
            $new_product_name = $new_product_obj ? $new_product_obj->get_name() : 'Subscription #' . $new_pack_id;
            $vendor_user = get_userdata( $vendor_id );
            $vendor_email = $vendor_user ? $vendor_user->user_email : '';
            $vendor_name = $vendor_user ? trim( $vendor_user->first_name . ' ' . $vendor_user->last_name ) : '';
            if ( empty( $vendor_name ) && $vendor_user ) {
                $vendor_name = $vendor_user->display_name;
            }

            $description = 'Upgrade Koopo subscription to ' . $new_product_name;
            $metadata = [
                'koopo_upgrade' => '1',
                'vendor_id'     => $vendor_id,
                'new_pack_id'   => $new_pack_id,
                'product_name'  => $new_product_name,
                'vendor_name'   => $vendor_name,
                'vendor_email'  => $vendor_email,
            ];

            // Get the Stripe Customer ID so saved payment methods appear in the form.
            $stripe_customer_id = $this->get_stripe_customer_id( $vendor_id );
            error_log( "Koopo Upgrade: stripe_customer_id={$stripe_customer_id}" );

            $pi_args = [
                'amount' => $amount_cents,
                'currency' => strtolower( get_woocommerce_currency() ),
                'description' => $description,
                'automatic_payment_methods' => [ 'enabled' => true ],
                'metadata' => $metadata,
                'setup_future_usage' => 'off_session',
            ];

            if ( ! empty( $vendor_email ) ) {
                $pi_args['receipt_email'] = $vendor_email;
            }

            // Attach the Stripe customer so saved payment methods show up
            if ( ! empty( $stripe_customer_id ) ) {
                $pi_args['customer'] = $stripe_customer_id;
            }

            // Create PI; if customer is invalid, retry without it
            try {
                $pi = $stripe->paymentIntents->create( $pi_args );
            } catch ( \Throwable $pi_err ) {
                if ( strpos( $pi_err->getMessage(), 'No such customer' ) !== false && ! empty( $pi_args['customer'] ) ) {
                    error_log( "Koopo Upgrade: customer {$stripe_customer_id} invalid, retrying without customer" );
                    unset( $pi_args['customer'] );
                    $stripe_customer_id = '';
                    $pi = $stripe->paymentIntents->create( $pi_args );
                } else {
                    throw $pi_err;
                }
            }

            // Create a pending order record that will be activated after payment
            $order = wc_create_order();
            $order->add_product( wc_get_product( $new_pack_id ), 1 );
            $order->set_customer_id( $vendor_id );

            // Add prorated discount as a visible fee line so it appears on the order
            $credit = floatval( $calc_data['credit'] );
            if ( $credit > 0 ) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name( 'Prorated Discount (subscription credit)' );
                $fee->set_amount( -$credit );
                $fee->set_total( -$credit );
                $order->add_item( $fee );
            }

            $order->set_total( $amount );
            $order->set_payment_method( 'dokan_stripe_express' );
            $order->set_payment_method_title( 'Stripe' );
            $order->update_status( 'pending', 'Upgrade initiated via modal - awaiting payment confirmation' );
            $order->update_meta_data( '_upgrade_proration_discount', $credit );
            $order->update_meta_data( '_is_upgrade_purchase', 'yes' );
            $order->update_meta_data( '_payment_intent_id', $pi->id );
            $order->update_meta_data( '_stripe_customer_id', $stripe_customer_id ?: '' );
            $order->save();

            // Update the PaymentIntent with the order ID now that it exists
            $stripe->paymentIntents->update( $pi->id, [
                'metadata' => [ 'order_id' => $order->get_id() ],
            ] );

            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'client_secret' => $pi->client_secret,
                    'payment_intent_id' => $pi->id,
                    'order_id' => $order->get_id(),
                    'first_payment' => $amount,
                ],
            ]);

        } catch ( \Throwable $e ) {
            error_log( 'Koopo Upgrade: EXCEPTION in rest_pay: ' . $e->getMessage() );
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * Finalize the upgrade after successful Stripe payment or when no payment is required
     * This endpoint triggers the native Dokan subscription activation flow
     */
    public function rest_finalize( WP_REST_Request $request ) {
        error_log( 'Koopo Upgrade v' . self::VERSION . ': rest_finalize called.' );

        $vendor_id = $request->get_param( 'vendor_id' ) ? intval( $request->get_param( 'vendor_id' ) ) : get_current_user_id();
        $order_id = intval( $request->get_param( 'order_id' ) );
        $payment_intent_id = $request->get_param( 'payment_intent_id' ) ? sanitize_text_field( $request->get_param( 'payment_intent_id' ) ) : '';

        error_log( "Koopo Upgrade: vendor_id={$vendor_id}, order_id={$order_id}, pi_id={$payment_intent_id}" );

        // vendor_id and order_id are required; payment_intent_id is optional (only needed if payment was required)
        if ( ! $vendor_id || ! $order_id ) {
            error_log( 'Koopo Upgrade: EARLY EXIT - missing vendor_id or order_id' );
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing vendor_id or order_id' ], 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "Koopo Upgrade: EARLY EXIT - order {$order_id} not found" );
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid order or unauthorized' ], 403 );
        }
        if ( $order->get_customer_id() != $vendor_id ) {
            error_log( "Koopo Upgrade: EARLY EXIT - customer_id mismatch: order has {$order->get_customer_id()}, expected {$vendor_id}" );
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid order or unauthorized' ], 403 );
        }

        // Retrieve the new product ID from order and fetch the product
        $new_pack_id = 0;
        $order_items = $order->get_items();
        if ( ! empty( $order_items ) ) {
            foreach ( $order_items as $item ) {
                $new_pack_id = $item->get_product_id();
                break;
            }
        }
        error_log( "Koopo Upgrade: new_pack_id={$new_pack_id}, order_items_count=" . count( $order_items ) );

        $new_product = null;
        if ( $new_pack_id > 0 ) {
            $new_product = wc_get_product( $new_pack_id );
        }

        try {
            // If a payment_intent_id is provided, verify payment was successful
            if ( ! empty( $payment_intent_id ) ) {
                $secret = $this->get_stripe_secret_key();
                if ( empty( $secret ) ) {
                    error_log( 'Koopo Upgrade: EARLY EXIT - Stripe key not configured' );
                    return new WP_REST_Response( [ 'success' => false, 'message' => 'Stripe key not configured' ], 500 );
                }

                $stripe = new \Stripe\StripeClient( $secret );
                $intent = $stripe->paymentIntents->retrieve( $payment_intent_id );
                error_log( "Koopo Upgrade: Stripe PI status = {$intent->status}" );

                if ( $intent->status !== 'succeeded' ) {
                    error_log( "Koopo Upgrade: EARLY EXIT - payment not succeeded: {$intent->status}" );
                    return new WP_REST_Response( [ 'success' => false, 'message' => 'Payment not confirmed: ' . $intent->status ], 400 );
                }

                $order->update_status( 'completed', 'Payment successful via Stripe. Subscription upgrade finalized.' );
                error_log( 'Koopo Upgrade: order status updated to completed' );
            } else {
                error_log( 'Koopo Upgrade: no payment_intent_id - zero-amount path' );
                // No payment required (proration covered the cost or was zero-amount)
                // Mark order as completed immediately
                if ( $order->get_status() === 'pending' ) {
                    $order->update_status( 'completed', 'Subscription upgrade completed - no payment required (proration applied).' );
                }
            }

            // Read previous subscription data BEFORE activation overwrites it.
            // manual_activate_subscription() updates product_order_id, so we must
            // capture the old value first for cancellation.
            $previous_order_id = get_user_meta( $vendor_id, 'product_order_id', true );
            $old_stripe_sub_id = get_user_meta( $vendor_id, '_dokan_stripe_express_subscription_id', true );
            error_log( "Koopo Upgrade: previous_order_id={$previous_order_id}, old_stripe_sub_id={$old_stripe_sub_id}" );

            // IMPORTANT: Activate new subscription BEFORE canceling old one.
            $activation_method = 'none';
            $class_exists = class_exists( '\WeDevs\DokanPro\Modules\Subscription\SubscriptionPack' );
            error_log( "Koopo Upgrade: class_exists(SubscriptionPack)=" . ( $class_exists ? 'true' : 'false' ) . ", new_pack_id={$new_pack_id}" );

            if ( $new_pack_id > 0 ) {
                if ( $class_exists ) {
                    $subscription = new \WeDevs\DokanPro\Modules\Subscription\SubscriptionPack( $new_pack_id, $vendor_id );
                    $subscription->activate_subscription( $order );
                    $activation_method = 'dokan_api';
                    error_log( 'Koopo Upgrade: activate_subscription() called' );

                    $activated_pack = get_user_meta( $vendor_id, 'product_package_id', true );
                    if ( intval( $activated_pack ) !== intval( $new_pack_id ) ) {
                        error_log( "Koopo Upgrade: activate_subscription() did not set product_package_id ({$activated_pack}). Manual fallback." );
                        $this->manual_activate_subscription( $vendor_id, $new_pack_id, $order );
                        $activation_method = 'manual_fallback';
                    } else {
                        error_log( "Koopo Upgrade: activation verified - product_package_id={$activated_pack}" );
                    }
                } else {
                    error_log( 'Koopo Upgrade: SubscriptionPack class not found, using manual activation.' );
                    $this->manual_activate_subscription( $vendor_id, $new_pack_id, $order );
                    $activation_method = 'manual_direct';
                }
            }

            // Create Stripe Subscription for recurring billing.
            // The one-time PaymentIntent covered the prorated amount; the Stripe
            // Subscription handles future recurring charges.
            $is_recurring = 'yes' === get_post_meta( $new_pack_id, '_enable_recurring_payment', true );
            if ( $new_pack_id > 0 && $is_recurring ) {
                $this->create_stripe_subscription( $vendor_id, $new_pack_id, $order );
            }

            // Cancel old Stripe subscription directly using the saved ID.
            // IMPORTANT: Before canceling, we must break the webhook lookup chain.
            // When Stripe sends a customer.subscription.deleted webhook, Dokan's
            // SubscriptionDeleted handler calls get_vendor_id_by_subscription() which:
            //   1. Searches usermeta for the old sub ID (won't match — already overwritten)
            //   2. Falls back to searching order meta for _dokan_stripe_express_stripe_subscription_id
            //   3. Falls back to Stripe subscription metadata['order_id']
            // If it finds the vendor, it deletes ALL subscription data (including the NEW sub).
            // We prevent this by clearing the old order meta and Stripe metadata first.
            if ( ! empty( $previous_order_id ) && intval( $previous_order_id ) !== intval( $order_id ) ) {
                $old_order = wc_get_order( intval( $previous_order_id ) );

                if ( ! empty( $old_stripe_sub_id ) ) {
                    try {
                        $secret = $this->get_stripe_secret_key();
                        if ( ! empty( $secret ) ) {
                            $stripe_client = new \Stripe\StripeClient( $secret );

                            // Step 1: Remove old sub ID from old order meta so webhook
                            // order-based lookup (get_order_by_subscription) finds nothing.
                            if ( $old_order ) {
                                $old_order->delete_meta_data( '_dokan_stripe_express_stripe_subscription_id' );
                                $old_order->save();
                                error_log( "Koopo Upgrade: cleared _dokan_stripe_express_stripe_subscription_id from old order {$previous_order_id}" );
                            }

                            // Step 2: Clear the order_id from Stripe subscription metadata
                            // so the metadata fallback in get_order_by_subscription also fails.
                            try {
                                $stripe_client->subscriptions->update( $old_stripe_sub_id, [
                                    'metadata' => [ 'order_id' => '' ],
                                ] );
                                error_log( "Koopo Upgrade: cleared order_id metadata from Stripe sub {$old_stripe_sub_id}" );
                            } catch ( \Throwable $meta_err ) {
                                error_log( 'Koopo Upgrade: WARNING - clear Stripe sub metadata failed: ' . $meta_err->getMessage() );
                            }

                            // Step 3: Now cancel the old Stripe subscription.
                            // The webhook will fire but get_vendor_id_by_subscription() will
                            // return null, so the handler exits without deleting anything.
                            $stripe_client->subscriptions->cancel( $old_stripe_sub_id );
                            error_log( "Koopo Upgrade: old Stripe subscription {$old_stripe_sub_id} canceled" );
                        }
                    } catch ( \Throwable $stripe_err ) {
                        error_log( 'Koopo Upgrade: WARNING - cancel old Stripe sub failed: ' . $stripe_err->getMessage() );
                    }
                }

                // Mark old order as cancelled for WP-side cleanup
                if ( $old_order && ! in_array( $old_order->get_status(), [ 'cancelled', 'refunded' ], true ) ) {
                    $old_order->update_status( 'cancelled', 'Subscription upgraded to new plan.' );
                    error_log( "Koopo Upgrade: old order {$previous_order_id} marked as cancelled" );
                }
            }

            error_log( "Koopo Upgrade: finalize complete. activation_method={$activation_method}" );

            return rest_ensure_response([
                'success' => true,
                'message' => 'Subscription upgrade completed successfully',
                'data' => [
                    'order_id' => $order_id,
                    'status' => 'completed',
                    'activation_method' => $activation_method,
                ],
            ]);

        } catch ( \Throwable $e ) {
            error_log( 'Koopo Upgrade: EXCEPTION in rest_finalize: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * Create a Stripe Subscription for recurring billing on the new plan.
     * The prorated one-time charge was already handled via PaymentIntent;
     * this subscription handles future recurring charges.
     */
    private function create_stripe_subscription( $vendor_id, $pack_id, $order ) {
        try {
            $secret = $this->get_stripe_secret_key();
            if ( empty( $secret ) ) {
                error_log( 'Koopo Upgrade: cannot create Stripe sub - no secret key' );
                return;
            }

            $stripe = new \Stripe\StripeClient( $secret );

            // Get or create Stripe Customer ID (required for subscriptions)
            $customer_id = $this->get_stripe_customer_id( $vendor_id );
            error_log( "Koopo Upgrade: create_stripe_subscription customer_id={$customer_id}" );

            // If no customer stored, or customer is invalid, create one
            if ( ! empty( $customer_id ) ) {
                try {
                    $stripe->customers->retrieve( $customer_id );
                } catch ( \Throwable $e ) {
                    error_log( "Koopo Upgrade: stored customer {$customer_id} invalid: {$e->getMessage()}" );
                    $customer_id = '';
                }
            }

            if ( empty( $customer_id ) ) {
                $vendor_user = get_userdata( $vendor_id );
                $cust_args = [ 'metadata' => [ 'vendor_id' => $vendor_id ] ];
                if ( $vendor_user ) {
                    $cust_args['email'] = $vendor_user->user_email;
                    $cust_args['name']  = trim( $vendor_user->first_name . ' ' . $vendor_user->last_name ) ?: $vendor_user->display_name;
                }
                $new_cust = $stripe->customers->create( $cust_args );
                $customer_id = $new_cust->id;

                // Determine correct meta key based on mode
                $is_test = false;
                if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Settings' ) ) {
                    $is_test = \WeDevs\DokanPro\Modules\StripeExpress\Support\Settings::is_test_mode();
                }
                $cust_key = $is_test ? '_dokan_stripe_express_test_customer_id' : '_dokan_stripe_express_customer_id';
                update_user_option( $vendor_id, $cust_key, $customer_id );
                error_log( "Koopo Upgrade: created new Stripe customer {$customer_id}" );
            }

            // Read billing config from product meta
            $period   = get_post_meta( $pack_id, '_dokan_subscription_period', true ); // day/week/month/year
            $interval = intval( get_post_meta( $pack_id, '_dokan_subscription_period_interval', true ) );
            if ( empty( $period ) || $interval < 1 ) {
                $interval = 1;
            }

            $product   = wc_get_product( $pack_id );
            $price     = $product ? floatval( $product->get_price() ) : 0;
            $prod_name = $product ? $product->get_name() : 'Subscription #' . $pack_id;

            if ( $price <= 0 || empty( $period ) ) {
                error_log( "Koopo Upgrade: skipping Stripe sub - price={$price}, period={$period}" );
                return;
            }

            $amount_cents = intval( round( $price * 100 ) );
            $currency     = strtolower( get_woocommerce_currency() );

            // Stripe Product ID if synced by Dokan Stripe Express
            $stripe_product_id = get_post_meta( $pack_id, '_dokan_stripe_express_product_id', true );

            // Build price_data
            $price_data = [
                'currency'  => $currency,
                'unit_amount' => $amount_cents,
                'recurring' => [
                    'interval'       => $period,
                    'interval_count' => $interval,
                ],
            ];

            if ( ! empty( $stripe_product_id ) ) {
                $price_data['product'] = $stripe_product_id;
            } else {
                $price_data['product_data'] = [ 'name' => $prod_name ];
            }

            // Defer first charge by one billing period since the prorated
            // amount already covers the current period.
            $trial_dt = new \DateTime( 'now', wp_timezone() );
            $add_s    = $interval > 1 ? 's' : '';
            $trial_dt->modify( "+{$interval} {$period}{$add_s}" );
            $trial_end = $trial_dt->getTimestamp();

            // Get the default payment method from the customer's most recent PI
            $default_pm = null;
            $pi_id = $order->get_meta( '_payment_intent_id' );
            if ( ! empty( $pi_id ) ) {
                try {
                    $pi = $stripe->paymentIntents->retrieve( $pi_id );
                    $default_pm = $pi->payment_method;
                } catch ( \Throwable $e ) {
                    error_log( 'Koopo Upgrade: could not retrieve PI for default_payment_method: ' . $e->getMessage() );
                }
            }

            $sub_args = [
                'customer'  => $customer_id,
                'items'     => [ [ 'price_data' => $price_data ] ],
                'trial_end' => $trial_end,
                'metadata'  => [
                    'koopo_upgrade' => '1',
                    'vendor_id'     => $vendor_id,
                    'pack_id'       => $pack_id,
                    'order_id'      => $order->get_id(),
                ],
            ];

            if ( ! empty( $default_pm ) ) {
                $sub_args['default_payment_method'] = $default_pm;
            }

            $stripe_sub = $stripe->subscriptions->create( $sub_args );
            error_log( "Koopo Upgrade: Stripe Subscription created: {$stripe_sub->id}" );

            // Store the new subscription ID in the same meta keys Dokan uses
            update_user_meta( $vendor_id, '_dokan_stripe_express_subscription_id', $stripe_sub->id );
            $order->update_meta_data( '_dokan_stripe_express_stripe_subscription_id', $stripe_sub->id );
            $order->update_meta_data( '_dokan_stripe_express_vendor_subscription_order', 'yes' );
            $order->save();

        } catch ( \Throwable $e ) {
            error_log( 'Koopo Upgrade: FAILED to create Stripe Subscription: ' . $e->getMessage() );
        }
    }

    /**
     * Manual fallback to activate a subscription when Dokan's
     * activate_subscription() silently fails (e.g. product type check).
     * Sets all the same user meta that SubscriptionPack::activate_subscription() would.
     */
    private function manual_activate_subscription( $vendor_id, $pack_id, $order ) {
        $product = wc_get_product( $pack_id );
        if ( ! $product ) {
            error_log( "Koopo Upgrade: manual_activate_subscription failed - product {$pack_id} not found." );
            return;
        }

        error_log( "Koopo Upgrade: Running manual subscription activation for vendor {$vendor_id}, pack {$pack_id}." );

        update_user_meta( $vendor_id, 'can_post_product', '1' );
        update_user_meta( $vendor_id, 'product_package_id', $pack_id );
        update_user_meta( $vendor_id, 'product_no_with_pack', get_post_meta( $pack_id, '_no_of_product', true ) );
        update_user_meta( $vendor_id, 'product_pack_startdate', current_time( 'Y-m-d H:i:s' ) );
        update_user_meta( $vendor_id, 'product_order_id', $order->get_id() );
        update_user_meta( $vendor_id, 'dokan_has_active_cancelled_subscrption', false );

        // Calculate end date from product meta
        $end_date = 'unlimited';
        $is_recurring = 'yes' === get_post_meta( $pack_id, '_enable_recurring_payment', true );
        $pack_validity = absint( get_post_meta( $pack_id, '_pack_validity', true ) );

        if ( $is_recurring ) {
            $sub_length = intval( get_post_meta( $pack_id, '_dokan_subscription_length', true ) );
            $sub_period = get_post_meta( $pack_id, '_dokan_subscription_period', true );
            if ( $sub_length > 0 && ! empty( $sub_period ) ) {
                $add_s = $sub_length > 1 ? 's' : '';
                try {
                    $dt = new \DateTime( 'now', wp_timezone() );
                    $dt->modify( "+{$sub_length} {$sub_period}{$add_s}" );
                    $end_date = $dt->format( 'Y-m-d H:i:s' );
                } catch ( \Exception $e ) {
                    $end_date = 'unlimited';
                }
            }
            update_user_meta( $vendor_id, '_customer_recurring_subscription', 'active' );
        } else {
            if ( $pack_validity > 0 ) {
                try {
                    $dt = new \DateTime( 'now', wp_timezone() );
                    $dt->modify( "+{$pack_validity} days" );
                    $end_date = $dt->format( 'Y-m-d H:i:s' );
                } catch ( \Exception $e ) {
                    $end_date = 'unlimited';
                }
            }
            update_user_meta( $vendor_id, '_customer_recurring_subscription', '' );
        }

        update_user_meta( $vendor_id, 'product_pack_enddate', $end_date );

        // Set up commissions from subscription product meta
        $admin_commission      = get_post_meta( $pack_id, '_subscription_product_admin_commission', true );
        $admin_additional_fee  = get_post_meta( $pack_id, '_subscription_product_admin_additional_fee', true );
        $admin_commission_type = get_post_meta( $pack_id, '_subscription_product_admin_commission_type', true );

        if ( function_exists( 'dokan' ) ) {
            $vendor = dokan()->vendor->get( $vendor_id );
            if ( $vendor && method_exists( $vendor, 'save_commission_settings' ) ) {
                $category_commission = get_post_meta( $pack_id, '_subscription_product_admin_category_based_commission', true );
                $vendor->save_commission_settings( [
                    'percentage'           => $admin_commission,
                    'type'                 => $admin_commission_type,
                    'flat'                 => $admin_additional_fee,
                    'category_commissions' => $category_commission,
                ] );
            }
        }

        do_action( 'dokan_vendor_purchased_subscription', $vendor_id );

        error_log( "Koopo Upgrade: Manual subscription activation complete for vendor {$vendor_id}. End date: {$end_date}" );
    }
}
