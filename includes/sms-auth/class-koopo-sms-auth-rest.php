<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_SMS_Auth_REST {
    private $service;

    public function __construct( Koopo_SMS_Auth_Service $service ) {
        $this->service = $service;
    }

    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            'koopo/v1',
            '/sms-auth/login/start',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'login_start' ),
                'args'                => array(
                    'phone' => array( 'required' => true, 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/sms-auth/login/verify',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'login_verify' ),
                'args'                => array(
                    'phone'      => array( 'required' => true, 'type' => 'string' ),
                    'request_id' => array( 'required' => true, 'type' => 'string' ),
                    'code'       => array( 'required' => true, 'type' => 'string' ),
                    'remember'   => array( 'required' => false, 'type' => 'boolean' ),
                ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/sms-auth/me',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => array( $this, 'require_auth' ),
                'callback'            => array( $this, 'me' ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/sms-auth/phone/start',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => array( $this, 'require_auth' ),
                'callback'            => array( $this, 'phone_start' ),
                'args'                => array(
                    'phone' => array( 'required' => true, 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/sms-auth/phone/verify',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => array( $this, 'require_auth' ),
                'callback'            => array( $this, 'phone_verify' ),
                'args'                => array(
                    'phone'      => array( 'required' => true, 'type' => 'string' ),
                    'request_id' => array( 'required' => true, 'type' => 'string' ),
                    'code'       => array( 'required' => true, 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/sms-auth/phone',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => array( $this, 'require_auth' ),
                'callback'            => array( $this, 'phone_delete' ),
            )
        );

        register_rest_route(
            'koopo/v1',
            '/sms-auth/token',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => array( $this, 'require_auth' ),
                'callback'            => array( $this, 'token_delete' ),
            )
        );
    }

    public function require_auth() {
        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error(
            'koopo_sms_auth_required',
            __( 'Authentication is required.', 'koopo' ),
            array( 'status' => 401 )
        );
    }

    public function login_start( WP_REST_Request $request ) {
        $result = $this->service->start_login( (string) $request->get_param( 'phone' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function login_verify( WP_REST_Request $request ) {
        $result = $this->service->verify_login(
            (string) $request->get_param( 'phone' ),
            (string) $request->get_param( 'request_id' ),
            (string) $request->get_param( 'code' ),
            (bool) $request->get_param( 'remember' )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function me() {
        return rest_ensure_response(
            array(
                'user'    => $this->service->user_payload( get_current_user_id() ),
                'enabled' => $this->service->is_enabled(),
            )
        );
    }

    public function phone_start( WP_REST_Request $request ) {
        $result = $this->service->start_phone_link( get_current_user_id(), (string) $request->get_param( 'phone' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function phone_verify( WP_REST_Request $request ) {
        $result = $this->service->verify_phone_link(
            get_current_user_id(),
            (string) $request->get_param( 'phone' ),
            (string) $request->get_param( 'request_id' ),
            (string) $request->get_param( 'code' )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'phone' => $result,
                'user'  => $this->service->user_payload( get_current_user_id() ),
            )
        );
    }

    public function phone_delete() {
        $result = $this->service->remove_phone( get_current_user_id() );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'phone' => $result,
                'user'  => $this->service->user_payload( get_current_user_id() ),
            )
        );
    }

    public function token_delete() {
        $this->service->revoke_current_token();
        return rest_ensure_response( array( 'revoked' => true ) );
    }
}
