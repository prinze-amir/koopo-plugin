<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Creator_Support_REST {
    private $service;

    public function __construct( Koopo_Creator_Support_Service $service ) {
        $this->service = $service;
    }

    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            'koopo/v1',
            '/creator-support/creator/(?P<id>\\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'get_creator_state' ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/creator-support/checkout',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'create_checkout' ),
                'args'                => array(
                    'creator_id'        => array( 'required' => true, 'type' => 'integer' ),
                    'amount'            => array( 'required' => true, 'type' => 'number' ),
                    'module'            => array( 'required' => false, 'type' => 'string' ),
                    'surface'           => array( 'required' => false, 'type' => 'string' ),
                    'context_post_id'   => array( 'required' => false, 'type' => 'integer' ),
                    'context_post_type' => array( 'required' => false, 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/creator-support/product',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => array( $this, 'require_auth' ),
                'callback'            => array( $this, 'create_product' ),
                'args'                => array(
                    'creator_id' => array( 'required' => true, 'type' => 'integer' ),
                ),
            )
        );
    }

    public function require_auth() {
        return is_user_logged_in();
    }

    public function get_creator_state( WP_REST_Request $request ) {
        $creator_id = absint( (int) $request['id'] );
        $creator    = get_user_by( 'id', $creator_id );

        if ( ! $creator ) {
            return new WP_Error( 'not_found', __( 'Creator not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $viewer_id = get_current_user_id();

        return rest_ensure_response(
            array(
                'creator' => array(
                    'id'           => $creator_id,
                    'display_name' => $this->service->creator_display_name( $creator_id ),
                    'avatar_url'   => (string) get_avatar_url( $creator_id, array( 'size' => 120 ) ),
                ),
                'access_state'          => $this->service->creator_support_access_state( $creator_id ),
                'viewer_can_manage'     => $viewer_id && ( $viewer_id === $creator_id || current_user_can( 'edit_user', $creator_id ) || current_user_can( 'manage_options' ) ),
                'woocommerce_available' => function_exists( 'wc_get_checkout_url' ),
            )
        );
    }

    public function create_checkout( WP_REST_Request $request ) {
        $creator_id = absint( (int) $request->get_param( 'creator_id' ) );
        $creator    = get_user_by( 'id', $creator_id );

        if ( ! $creator ) {
            return new WP_Error( 'not_found', __( 'Creator not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $rate_limit = $this->enforce_rate_limit( 'checkout', $request, (string) $creator_id );
        if ( is_wp_error( $rate_limit ) ) {
            return $rate_limit;
        }

        $amount = max( 0, (float) $request->get_param( 'amount' ) );
        if ( $amount <= 0 ) {
            return new WP_Error( 'invalid_amount', __( 'Please enter a valid donation amount.', 'koopo' ), array( 'status' => 400 ) );
        }

        $checkout_url = $this->service->checkout_url_for_creator(
            $creator_id,
            $amount,
            array(
                'module'            => sanitize_key( (string) $request->get_param( 'module' ) ),
                'surface'           => sanitize_key( (string) $request->get_param( 'surface' ) ),
                'context_post_id'   => absint( (int) $request->get_param( 'context_post_id' ) ),
                'context_post_type' => sanitize_key( (string) $request->get_param( 'context_post_type' ) ),
            )
        );

        if ( is_wp_error( $checkout_url ) ) {
            return $checkout_url;
        }

        return rest_ensure_response(
            array(
                'creator_id'   => $creator_id,
                'creator_name' => $this->service->creator_display_name( $creator_id ),
                'checkout_url' => $checkout_url,
                'amount'       => $amount,
            )
        );
    }

    public function create_product( WP_REST_Request $request ) {
        $creator_id = absint( (int) $request->get_param( 'creator_id' ) );
        $creator    = get_user_by( 'id', $creator_id );

        if ( ! $creator ) {
            return new WP_Error( 'not_found', __( 'Creator not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $viewer_id = get_current_user_id();
        if ( ! $viewer_id || ( $viewer_id !== $creator_id && ! current_user_can( 'edit_user', $creator_id ) && ! current_user_can( 'manage_options' ) ) ) {
            return new WP_Error( 'forbidden', __( 'You cannot manage creator donations for this account.', 'koopo' ), array( 'status' => 403 ) );
        }

        $access_state = $this->service->creator_support_access_state( $creator_id );
        if ( empty( $access_state['has_store_permissions'] ) || empty( $access_state['has_public_store'] ) ) {
            return new WP_Error(
                'donations_unavailable',
                ! empty( $access_state['message'] ) ? (string) $access_state['message'] : __( 'Donations are not available for this creator right now.', 'koopo' ),
                array(
                    'status'       => 403,
                    'access_state' => $access_state,
                )
            );
        }

        $had_product = ! empty( $access_state['has_product'] );
        $product_id  = $this->service->ensure_product_for_creator( $creator_id );
        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }

        return rest_ensure_response(
            array(
                'creator_id'   => $creator_id,
                'product_id'   => absint( (int) $product_id ),
                'created'      => ! $had_product,
                'message'      => $had_product
                    ? __( 'Support product is already enabled for this creator.', 'koopo' )
                    : __( 'Support product created. Supporters can now donate.', 'koopo' ),
                'access_state' => $this->service->creator_support_access_state( $creator_id ),
            )
        );
    }

    private function enforce_rate_limit( $scope, WP_REST_Request $request, $context = '' ) {
        if ( class_exists( 'Koopo_Video_REST' ) && method_exists( 'Koopo_Video_REST', 'enforce_rate_limit' ) ) {
            return Koopo_Video_REST::enforce_rate_limit( 'donate', $request, $context );
        }

        $rule = array(
            'limit'   => 10,
            'window'  => 15 * MINUTE_IN_SECONDS,
            'message' => __( 'Too many donation attempts. Please wait and try again.', 'koopo' ),
        );

        $identity  = $this->rate_limit_identity( $request );
        $bucket    = (int) floor( time() / $rule['window'] );
        $cache_key = 'koopo_creator_support_rl_' . md5( implode( '|', array( (string) $scope, (string) $context, (string) $identity, (string) $bucket ) ) );
        $count     = (int) get_transient( $cache_key );

        if ( $count >= $rule['limit'] ) {
            return new WP_Error(
                'rate_limited',
                $rule['message'],
                array(
                    'status'      => 429,
                    'retry_after' => max( 1, ( ( $bucket + 1 ) * $rule['window'] ) - time() ),
                    'scope'       => (string) $scope,
                )
            );
        }

        set_transient( $cache_key, $count + 1, $rule['window'] + MINUTE_IN_SECONDS );

        return true;
    }

    private function rate_limit_identity( WP_REST_Request $request ) {
        unset( $request );

        $viewer_id = get_current_user_id();
        if ( $viewer_id ) {
            return 'user:' . $viewer_id;
        }

        $ip         = $this->request_remote_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        return 'guest:' . md5( $ip . '|' . $user_agent );
    }

    private function request_remote_ip() {
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }
}
