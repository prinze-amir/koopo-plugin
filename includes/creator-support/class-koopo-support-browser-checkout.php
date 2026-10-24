<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-koopo-support-browser-sessions.php';

/** App handoff to a single WooCommerce support order, using the existing payment lifecycle. */
class Koopo_Support_Browser_Checkout {
    private $sessions;
    private $checkout;
    public function __construct( $sessions = null, $checkout = null ) {
        $this->sessions = $sessions ?: new Koopo_Support_Browser_Sessions();
        $this->checkout = $checkout ?: new Koopo_Creator_Support_Checkout();
    }
    public function hooks() {
        $this->sessions->hooks();
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }
    public function routes() {
        $base = '/creator-support/browser';
        register_rest_route( 'koopo/v1', $base, array( 'methods' => 'POST', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'create' ) ) );
        register_rest_route( 'koopo/v1', $base . '/exchange', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'exchange' ) ) );
        register_rest_route( 'koopo/v1', $base . '/session', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( $this, 'status' ) ) );
        register_rest_route( 'koopo/v1', $base . '/confirm', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'confirm' ) ) );
        register_rest_route( 'koopo/v1', $base . '/cancel', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'cancel' ) ) );
        register_rest_route( 'koopo/v1', $base . '/orders/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'app_status' ) ) );
    }
    private function error( $message, $status = 400 ) { return new WP_Error( 'koopo_browser_checkout', $message, array( 'status' => $status ) ); }
    private function response( $data ) {
        if ( is_wp_error( $data ) ) { return $data; }
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'no-store, private' );
        return $response;
    }
    private function request_for( $params ) {
        $request = new WP_REST_Request( 'POST' );
        foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
        return $request;
    }
    public function create( WP_REST_Request $request ) {
        if ( defined( 'KOOPO_BROWSER_CHECKOUT_REQUIRE_TEST_MODE' ) && KOOPO_BROWSER_CHECKOUT_REQUIRE_TEST_MODE ) {
            $settings = '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Settings';
            $sandbox = class_exists( $settings ) && method_exists( $settings, 'is_sandbox_mode' ) && $settings::is_sandbox_mode();
            if ( ! $sandbox && ( ! class_exists( $settings ) || ! $settings::is_test_mode() ) ) {
                return $this->error( 'Staging checkout requires a Stripe test or sandbox environment.', 503 );
            }
        }
        $creator_id = filter_var( $request->get_param( 'creator_id' ), FILTER_VALIDATE_INT );
        $amount = $request->get_param( 'amount' );
        $request_key = $request->get_param( 'request_key' );
        if ( ! $creator_id || $creator_id < 1 || ! get_userdata( $creator_id ) || ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 1 || (float) $amount > 10000 || ! is_string( $request_key ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $request_key ) ) {
            return $this->error( 'Choose a valid creator and support amount.' );
        }
        $handoff_code = $request->get_param( 'handoff_code' );
        $message = $request->get_param( 'message' ) ?? '';
        if ( ( null !== $handoff_code && ( ! is_string( $handoff_code ) || ! preg_match( '/^[a-f0-9]{64}$/D', $handoff_code ) ) ) || ! is_string( $message ) || mb_strlen( $message ) > 500 ) { return $this->error( 'Invalid checkout note or handoff.' ); }
        $rate_key = 'kcs_browser_rate_' . get_current_user_id();
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 20 ) { return $this->error( 'Too many checkout links. Please wait before trying again.', 429 ); }
        set_transient( $rate_key, $attempts + 1, 900 );
        $context = Koopo_Creator_Support_Checkout::context_token( array( 'creator_id' => $creator_id, 'module' => 'videos', 'surface' => 'mobile_browser' ) );
        $binding = array( 'user_id' => get_current_user_id(), 'creator_id' => $creator_id, 'context' => $context, 'amount' => (float) $amount, 'request_key' => $request_key, 'message' => $message );
        $payload = $this->checkout->create( $this->request_for( array_intersect_key( $binding, array_flip( array( 'context', 'amount', 'request_key', 'message' ) ) ) ) );
        if ( is_wp_error( $payload ) ) { return $payload; }
        $binding['order_id'] = (int) $payload['order_id'];
        // No general-purpose access token, browser login, or payment secret is placed in the launch URL.
        return $this->response( array( 'code' => $this->sessions->issue( $binding, $handoff_code ), 'order_id' => $binding['order_id'], 'expires_in' => Koopo_Support_Browser_Sessions::HANDOFF_TTL ) );
    }
    public function exchange( WP_REST_Request $request ) {
        $code = $request->get_param( 'code' );
        if ( ! is_string( $code ) || ! preg_match( '/^[a-f0-9]{64}$/D', $code ) ) { return $this->error( 'This checkout link is invalid.', 401 ); }
        $redeemed = $this->sessions->redeem( $code, $request->get_header( 'x-koopo-checkout-token' ) );
        if ( ! $redeemed ) { return $this->error( 'This checkout link has expired or was already opened. Start again in Koopo.', 401 ); }
        return $this->response( array( 'session_token' => $redeemed['token'], 'order_id' => $redeemed['binding']['order_id'], 'expires_in' => max( 0, $redeemed['binding']['expires_at'] - time() ) ) );
    }
    public function status( WP_REST_Request $request ) { return $this->read( $request, false ); }
    public function confirm( WP_REST_Request $request ) { return $this->read( $request, true ); }
    public function cancel( WP_REST_Request $request ) { return $this->read( $request, false, true ); }
    private function read( $request, $confirm, $cancel = false ) {
        $binding = $this->sessions->read( 'session', $request->get_header( 'x-koopo-checkout-token' ) );
        if ( ! $binding ) { return $this->error( 'Your checkout session expired. Return to Koopo to start again.', 401 ); }
        return $this->response( $this->with_customer( $binding, ! $confirm && ! $cancel, $confirm, $cancel ) );
    }
    private function with_customer( $binding, $prepare = false, $confirm = false, $cancel = false ) {
        $order = wc_get_order( $binding['order_id'] );
        if ( ! $order || (int) $order->get_customer_id() !== (int) $binding['user_id'] || ! $order->get_meta( '_koopo_support_session' ) || ! get_userdata( $binding['user_id'] ) ) { return $this->error( 'Support checkout not found.', 404 ); }
        $previous_user = get_current_user_id();
        try {
            // Capability is validated above and can operate on this bound order only.
            wp_set_current_user( $binding['user_id'] );
            if ( $prepare && ! $order->is_paid() ) {
                $payload = $this->checkout->create( $this->request_for( array_intersect_key( $binding, array_flip( array( 'context', 'amount', 'request_key', 'message' ) ) ) ) );
            } else {
                $request = $this->request_for( array( 'id' => $binding['order_id'] ) );
                $payload = $cancel ? $this->checkout->cancel( $request ) : ( $confirm ? $this->checkout->confirm( $request ) : $this->checkout->status( $request ) );
            }
            if ( is_wp_error( $payload ) ) { return $payload; }
            if ( (int) $payload['order_id'] !== (int) $binding['order_id'] ) { return $this->error( 'Checkout order mismatch.', 409 ); }
            $payload['creator'] = array( 'id' => $binding['creator_id'], 'name' => Koopo_Creator_Support::instance()->service()->creator_display_name( $binding['creator_id'] ) );
            $payload['kind'] = 'koopo_love';
            $payload['test_mode'] = $order->get_meta( '_koopo_support_environment' ) !== 'live';
            return $payload;
        } finally { wp_set_current_user( $previous_user ); }
    }
    public function app_status( WP_REST_Request $request ) {
        // Existing controller enforces both ownership and the support-order marker.
        return $this->response( $this->checkout->confirm( $request ) );
    }
}
