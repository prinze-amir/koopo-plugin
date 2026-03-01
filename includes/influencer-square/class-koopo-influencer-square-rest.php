<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Influencer_Square_REST {
    private $namespace = 'koopo/v1';
    private $analytics;

    public function __construct( Koopo_Influencer_Square_Analytics $analytics ) {
        $this->analytics = $analytics;
    }

    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/influencer-square/post/(?P<post_id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_post_analytics' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/influencer-square/view',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'track_view' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/influencer-square/reaction',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'set_reaction' ),
                    'permission_callback' => array( $this, 'can_react' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/influencer-square/me',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_current_author_analytics' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/influencer-square/author/(?P<author_id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_author_analytics' ),
                    'permission_callback' => array( $this, 'can_view_author_analytics' ),
                ),
            )
        );
    }

    public function get_post_analytics( WP_REST_Request $request ) {
        $post_id = absint( $request['post_id'] );
        if ( ! $post_id || ! $this->analytics->is_trackable_post( $post_id ) ) {
            return new WP_Error( 'invalid_post', __( 'Post not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $viewer_id = get_current_user_id();
        return rest_ensure_response( $this->analytics->get_post_stats( $post_id, $viewer_id ) );
    }

    public function track_view( WP_REST_Request $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id || ! $this->analytics->is_trackable_post( $post_id ) ) {
            return new WP_Error( 'invalid_post', __( 'Post not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        $dedupe = $this->build_view_dedupe_key();
        $tracked = $this->analytics->track_view( $post_id, $dedupe );

        return rest_ensure_response(
            array(
                'tracked' => (bool) $tracked,
                'stats'   => $this->analytics->get_post_stats( $post_id, get_current_user_id() ),
            )
        );
    }

    public function set_reaction( WP_REST_Request $request ) {
        $user_id  = get_current_user_id();
        $post_id  = absint( $request->get_param( 'post_id' ) );
        $reaction = sanitize_key( (string) $request->get_param( 'reaction' ) );

        $stats = $this->analytics->set_user_reaction( $user_id, $post_id, $reaction );
        if ( is_wp_error( $stats ) ) {
            return $stats;
        }

        return rest_ensure_response( $stats );
    }

    public function get_current_author_analytics() {
        $author_id = get_current_user_id();
        $data      = $this->analytics->get_author_analytics( $author_id );
        if ( empty( $data ) ) {
            return new WP_Error( 'author_not_found', __( 'Author not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $data );
    }

    public function get_author_analytics( WP_REST_Request $request ) {
        $author_id = absint( $request['author_id'] );
        $data      = $this->analytics->get_author_analytics( $author_id );
        if ( empty( $data ) ) {
            return new WP_Error( 'author_not_found', __( 'Author not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $data );
    }

    public function must_be_logged_in() {
        return is_user_logged_in();
    }

    public function can_react( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $post_id = absint( $request->get_param( 'post_id' ) );
        return $post_id && $this->analytics->is_trackable_post( $post_id );
    }

    public function can_view_author_analytics( WP_REST_Request $request ) {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $author_id = absint( $request['author_id'] );
        if ( ! $author_id ) {
            return false;
        }

        return get_current_user_id() === $author_id || current_user_can( 'manage_options' );
    }

    private function build_view_dedupe_key() {
        if ( is_user_logged_in() ) {
            return 'u:' . get_current_user_id();
        }

        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip    = sanitize_text_field( trim( $parts[0] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] );
        }

        $ua = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
        return 'g:' . md5( $ip . '|' . $ua );
    }
}

