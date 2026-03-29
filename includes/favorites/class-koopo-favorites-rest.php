<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Favorites_REST {
    private $namespace = 'koopo/v1';
    private $service;

    public function __construct( Koopo_Favorites_Service $service ) {
        $this->service = $service;
    }

    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/favorites/settings',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_settings' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/lists',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_lists' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/lists/(?P<list_id>[a-zA-Z0-9\-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/lists/(?P<list_id>[a-zA-Z0-9\-]+)/items',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'add_item' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/lists/(?P<list_id>[a-zA-Z0-9\-]+)/items/(?P<post_id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'remove_item' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/lists/(?P<list_id>[a-zA-Z0-9\-]+)/share',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'share_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/lists/(?P<list_id>[a-zA-Z0-9\-]+)/publish',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'publish_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/items/transfer',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'transfer_item' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/items/bulk',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'bulk_update_items' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/post/(?P<post_id>\d+)/status',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_post_status' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/shared/(?P<slug>[a-zA-Z0-9\-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_shared_list' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/favorites/shared/(?P<slug>[a-zA-Z0-9\-]+)/import',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'import_shared_list' ),
                    'permission_callback' => array( $this, 'must_be_logged_in' ),
                ),
            )
        );
    }

    public function must_be_logged_in() {
        return is_user_logged_in();
    }

    public function get_settings() {
        return rest_ensure_response(
            array(
                'enabled_post_types' => $this->service->get_enabled_post_types(),
            )
        );
    }

    public function get_lists() {
        $user_id = get_current_user_id();
        return rest_ensure_response( $this->service->get_user_lists_for_response( $user_id, true ) );
    }

    public function create_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $name    = $request->get_param( 'name' );

        $result = $this->service->create_list( $user_id, $name );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function get_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $list_id = sanitize_text_field( (string) $request['list_id'] );
        $list    = $this->service->get_list_by_id( $user_id, $list_id );

        if ( ! $list ) {
            return new WP_Error( 'list_not_found', __( 'List not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $this->service->format_list_for_response( $list, $user_id, true ) );
    }

    public function update_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $list_id = sanitize_text_field( (string) $request['list_id'] );

        $result = $this->service->update_list(
            $user_id,
            $list_id,
            array(
                'name'      => $request->get_param( 'name' ),
                'is_public' => $request->get_param( 'is_public' ),
            )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function delete_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $list_id = sanitize_text_field( (string) $request['list_id'] );

        $result = $this->service->delete_list( $user_id, $list_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function add_item( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $list_id = sanitize_text_field( (string) $request['list_id'] );
        $post_id = absint( $request->get_param( 'post_id' ) );

        $result = $this->service->add_item_to_list( $user_id, $list_id, $post_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function remove_item( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $list_id = sanitize_text_field( (string) $request['list_id'] );
        $post_id = absint( $request['post_id'] );

        $result = $this->service->remove_item_from_list( $user_id, $list_id, $post_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function share_list( WP_REST_Request $request ) {
        $user_id   = get_current_user_id();
        $list_id   = sanitize_text_field( (string) $request['list_id'] );
        $is_public = $request->has_param( 'is_public' ) ? (bool) $request->get_param( 'is_public' ) : true;

        $result = $this->service->update_list(
            $user_id,
            $list_id,
            array(
                'is_public' => $is_public,
            )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function publish_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $list_id = sanitize_text_field( (string) $request['list_id'] );
        $status  = sanitize_key( (string) $request->get_param( 'status' ) );

        $result = $this->service->publish_list_as_post( $user_id, $list_id, $status ? $status : 'draft' );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function transfer_item( WP_REST_Request $request ) {
        $user_id          = get_current_user_id();
        $source_list_id   = sanitize_text_field( (string) $request->get_param( 'source_list_id' ) );
        $target_list_id   = sanitize_text_field( (string) $request->get_param( 'target_list_id' ) );
        $target_list_name = sanitize_text_field( (string) $request->get_param( 'target_list_name' ) );
        $post_id          = absint( $request->get_param( 'post_id' ) );
        $mode             = sanitize_key( (string) $request->get_param( 'mode' ) );

        $result = $this->service->transfer_item( $user_id, $source_list_id, $post_id, $mode, $target_list_id, $target_list_name );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function bulk_update_items( WP_REST_Request $request ) {
        $user_id          = get_current_user_id();
        $source_list_id   = sanitize_text_field( (string) $request->get_param( 'source_list_id' ) );
        $target_list_id   = sanitize_text_field( (string) $request->get_param( 'target_list_id' ) );
        $target_list_name = sanitize_text_field( (string) $request->get_param( 'target_list_name' ) );
        $post_ids         = $request->get_param( 'post_ids' );
        $operation        = sanitize_key( (string) $request->get_param( 'operation' ) );

        $result = $this->service->bulk_update_items( $user_id, $source_list_id, $post_ids, $operation, $target_list_id, $target_list_name );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public function get_post_status( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $post_id = absint( $request['post_id'] );

        return rest_ensure_response( $this->service->get_post_status_for_user( $user_id, $post_id ) );
    }

    public function get_shared_list( WP_REST_Request $request ) {
        $slug = sanitize_text_field( (string) $request['slug'] );
        $list = $this->service->get_public_list_by_slug( $slug );

        if ( ! $list ) {
            return new WP_Error( 'list_not_found', __( 'Shared list not found.', 'koopo' ), array( 'status' => 404 ) );
        }

        return rest_ensure_response( $list );
    }

    public function import_shared_list( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $slug    = sanitize_text_field( (string) $request['slug'] );
        $name    = sanitize_text_field( (string) $request->get_param( 'name' ) );

        $result = $this->service->import_public_list_as_new_list( $user_id, $slug, $name );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }
}
