<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Koopo_Account_Settings_Rest' ) ) {

    class Koopo_Account_Settings_Rest {

        const REST_NAMESPACE          = 'koopo/v1';
        const META_CLOSE_FRIENDS      = '_koopo_story_close_friend_ids';
        const META_HIDDEN_STORY_USERS = '_koopo_story_hidden_author_ids';
        const META_BLOCKED_MEMBERS    = '_koopo_blocked_member_ids';
        const EXPORT_TRANSIENT_PREFIX = 'koopo_account_export_';
        const EXPORT_DOWNLOAD_ACTION  = 'koopo_account_export_download';

        /**
         * @var Koopo_BuddyBoss_Profile_Tabs|null
         */
        private $profile_tabs;

        public function __construct( $profile_tabs = null ) {
            $this->profile_tabs = $profile_tabs instanceof Koopo_BuddyBoss_Profile_Tabs ? $profile_tabs : null;
        }

        public function init() {
            add_action( 'rest_api_init', [ $this, 'register_routes' ] );
            add_action( 'admin_post_' . self::EXPORT_DOWNLOAD_ACTION, [ $this, 'stream_data_export' ] );
        }

        public function register_routes() {
            register_rest_route(
                self::REST_NAMESPACE,
                '/account/login-information',
                [
                    [
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => [ $this, 'get_login_information' ],
                        'permission_callback' => [ $this, 'require_authenticated_user' ],
                    ],
                    [
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => [ $this, 'update_login_information' ],
                        'permission_callback' => [ $this, 'require_authenticated_user' ],
                    ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/privacy',
                [
                    [
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => [ $this, 'get_privacy_settings' ],
                        'permission_callback' => [ $this, 'require_authenticated_user' ],
                    ],
                    [
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => [ $this, 'update_privacy_settings' ],
                        'permission_callback' => [ $this, 'require_authenticated_user' ],
                    ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/notifications',
                [
                    [
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => [ $this, 'get_notification_settings' ],
                        'permission_callback' => [ $this, 'require_authenticated_user' ],
                    ],
                    [
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => [ $this, 'update_notification_settings' ],
                        'permission_callback' => [ $this, 'require_authenticated_user' ],
                    ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/subscriptions',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_subscription_summary' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_story_settings' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories/archive',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_story_archive' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories/search-users',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'search_story_users' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories/close-friends',
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'add_close_friend' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories/close-friends/(?P<user_id>\d+)',
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'remove_close_friend' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories/hidden-authors',
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'add_hidden_author' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/stories/hidden-authors/(?P<user_id>\d+)',
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'remove_hidden_author' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/blocked-members',
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_blocked_members' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/blocked-members/(?P<block_id>\d+)',
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'remove_blocked_member' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/export',
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_data_export' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );

            register_rest_route(
                self::REST_NAMESPACE,
                '/account/delete',
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'delete_account' ],
                    'permission_callback' => [ $this, 'require_authenticated_user' ],
                ]
            );
        }

        public function require_authenticated_user() {
            if ( is_user_logged_in() ) {
                return true;
            }

            return new WP_Error(
                'koopo_account_auth_required',
                __( 'Authentication is required.', 'koopo' ),
                [ 'status' => 401 ]
            );
        }

        public function get_login_information() {
            $user = wp_get_current_user();

            return rest_ensure_response(
                [
                    'email' => $user instanceof WP_User ? (string) $user->user_email : '',
                ]
            );
        }

        public function update_login_information( WP_REST_Request $request ) {
            $user = wp_get_current_user();
            if ( ! ( $user instanceof WP_User ) || $user->ID <= 0 ) {
                return new WP_Error( 'koopo_account_invalid_user', __( 'Authenticated user could not be resolved.', 'koopo' ), [ 'status' => 401 ] );
            }

            $email            = $request->get_param( 'email' );
            $current_password = (string) $request->get_param( 'currentPassword' );
            $new_password     = (string) $request->get_param( 'newPassword' );
            $args             = [ 'ID' => $user->ID ];

            if ( null !== $email ) {
                $email = sanitize_email( (string) $email );
                if ( '' === $email || ! is_email( $email ) ) {
                    return new WP_Error( 'koopo_account_invalid_email', __( 'A valid email address is required.', 'koopo' ), [ 'status' => 400 ] );
                }

                $existing = email_exists( $email );
                if ( $existing && (int) $existing !== (int) $user->ID ) {
                    return new WP_Error( 'koopo_account_email_taken', __( 'That email address is already in use.', 'koopo' ), [ 'status' => 409 ] );
                }

                $args['user_email'] = $email;
            }

            if ( '' !== $new_password ) {
                if ( '' === trim( $current_password ) ) {
                    return new WP_Error( 'koopo_account_password_required', __( 'Current password is required to change your password.', 'koopo' ), [ 'status' => 400 ] );
                }

                if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
                    return new WP_Error( 'koopo_account_password_incorrect', __( 'Current password is incorrect.', 'koopo' ), [ 'status' => 403 ] );
                }

                if ( strlen( $new_password ) < 8 ) {
                    return new WP_Error( 'koopo_account_password_short', __( 'New password must be at least 8 characters.', 'koopo' ), [ 'status' => 400 ] );
                }

                $args['user_pass'] = $new_password;
            }

            if ( count( $args ) <= 1 ) {
                return new WP_Error( 'koopo_account_no_changes', __( 'No login information changes were supplied.', 'koopo' ), [ 'status' => 400 ] );
            }

            $updated = wp_update_user( $args );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            $updated_user = get_userdata( $user->ID );

            return rest_ensure_response(
                [
                    'email' => $updated_user instanceof WP_User ? (string) $updated_user->user_email : '',
                ]
            );
        }

        public function get_privacy_settings() {
            return rest_ensure_response( $this->get_privacy_settings_payload( get_current_user_id() ) );
        }

        public function update_privacy_settings( WP_REST_Request $request ) {
            $user_id = get_current_user_id();
            if ( $user_id <= 0 ) {
                return new WP_Error( 'koopo_account_invalid_user', __( 'Authenticated user could not be resolved.', 'koopo' ), [ 'status' => 401 ] );
            }

            $profile_tabs = $this->get_profile_tabs_controller();
            if ( $profile_tabs && null !== $request->get_param( 'profileVisibility' ) ) {
                $profile_tabs->update_profile_visibility_for_user( $user_id, (string) $request->get_param( 'profileVisibility' ) );
            }

            if ( $profile_tabs && null !== $request->get_param( 'visibleTabKeys' ) ) {
                $tabs         = $profile_tabs->get_controlled_tabs();
                $valid_slugs  = array_keys( $tabs );
                $visible_tabs = $request->get_param( 'visibleTabKeys' );

                if ( ! is_array( $visible_tabs ) ) {
                    return new WP_Error( 'koopo_account_invalid_tabs', __( 'visibleTabKeys must be an array.', 'koopo' ), [ 'status' => 400 ] );
                }

                $visible_tabs = array_values(
                    array_unique(
                        array_intersect(
                            array_map( 'sanitize_key', wp_unslash( $visible_tabs ) ),
                            $valid_slugs
                        )
                    )
                );

                $hidden_tabs = array_values( array_diff( $valid_slugs, $visible_tabs ) );
                update_user_meta( $user_id, Koopo_BuddyBoss_Profile_Tabs::META_HIDDEN_TABS, $hidden_tabs );
            }

            return rest_ensure_response( $this->get_privacy_settings_payload( $user_id ) );
        }

        public function get_notification_settings() {
            return rest_ensure_response( $this->get_notification_settings_payload() );
        }

        public function update_notification_settings( WP_REST_Request $request ) {
            $values = $request->get_param( 'values' );
            if ( ! is_array( $values ) || empty( $values ) ) {
                return new WP_Error( 'koopo_account_invalid_notifications', __( 'Notification values must be provided.', 'koopo' ), [ 'status' => 400 ] );
            }

            $endpoint = $this->get_buddyboss_account_settings_endpoint();
            if ( ! $endpoint || ! method_exists( $endpoint, 'update_notifications_fields' ) ) {
                return new WP_Error( 'koopo_account_notifications_unavailable', __( 'BuddyBoss notification settings are unavailable.', 'koopo' ), [ 'status' => 501 ] );
            }

            $normalized = [];
            foreach ( $values as $key => $value ) {
                $setting_key = sanitize_key( (string) $key );
                if ( '' === $setting_key ) {
                    continue;
                }

                $normalized[ $setting_key ] = rest_sanitize_boolean( $value ) ? 'yes' : 'no';
            }

            if ( empty( $normalized ) ) {
                return new WP_Error( 'koopo_account_invalid_notifications', __( 'Notification values must include at least one setting.', 'koopo' ), [ 'status' => 400 ] );
            }

            $update_request = new WP_REST_Request( 'PATCH' );
            $update_request->set_param( 'fields', $normalized );
            $updated = $endpoint->update_notifications_fields( $update_request );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            return rest_ensure_response( $this->get_notification_settings_payload() );
        }

        public function get_subscription_summary() {
            return rest_ensure_response( $this->get_subscription_summary_payload( get_current_user_id() ) );
        }

        public function get_story_settings() {
            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function search_story_users( WP_REST_Request $request ) {
            $query = trim( (string) $request->get_param( 'query' ) );
            if ( strlen( $query ) < 2 ) {
                return rest_ensure_response( [ 'items' => [] ] );
            }

            if ( $this->is_story_rest_available() ) {
                $response = $this->call_story_rest_handler(
                    [ 'Koopo_Stories_REST_Story', 'search_users' ],
                    [
                        'query' => $query,
                        'limit' => 12,
                    ],
                    'GET'
                );

                if ( is_wp_error( $response ) ) {
                    return $response;
                }

                $data  = $this->get_rest_response_data( $response );
                $items = [];
                foreach ( (array) ( $data['users'] ?? [] ) as $user ) {
                    $summary = $this->build_member_summary( $user );
                    if ( $summary && (int) $summary['id'] !== get_current_user_id() ) {
                        $items[] = $summary;
                    }
                }

                return rest_ensure_response( [ 'items' => $items ] );
            }

            $user_query = new WP_User_Query(
                [
                    'number'         => 12,
                    'exclude'        => [ get_current_user_id() ],
                    'search'         => '*' . esc_attr( $query ) . '*',
                    'search_columns' => [ 'user_login', 'display_name', 'user_nicename', 'user_email' ],
                    'orderby'        => 'display_name',
                    'order'          => 'ASC',
                ]
            );

            $items = [];
            foreach ( (array) $user_query->get_results() as $user ) {
                $summary = $this->build_member_summary( $user );
                if ( $summary ) {
                    $items[] = $summary;
                }
            }

            return rest_ensure_response( [ 'items' => $items ] );
        }

        public function add_close_friend( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'userId' );
            if ( $this->is_story_rest_available() ) {
                $response = $this->call_story_rest_handler(
                    [ 'Koopo_Stories_REST_Story', 'add_close_friend' ],
                    [ 'friend_id' => $target_user_id ],
                    'POST'
                );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
            } else {
                $result = $this->update_user_id_list( get_current_user_id(), self::META_CLOSE_FRIENDS, $target_user_id, 'add' );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function remove_close_friend( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'user_id' );
            if ( $this->is_story_rest_available() ) {
                $response = $this->call_story_rest_handler(
                    [ 'Koopo_Stories_REST_Story', 'remove_close_friend' ],
                    [ 'friend_id' => $target_user_id ],
                    'DELETE'
                );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
            } else {
                $result = $this->update_user_id_list( get_current_user_id(), self::META_CLOSE_FRIENDS, $target_user_id, 'remove' );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function add_hidden_author( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'userId' );
            if ( $this->is_story_rest_available() ) {
                $response = $this->call_story_rest_handler(
                    [ 'Koopo_Stories_REST_Story', 'add_hidden_all_user' ],
                    [ 'user_id' => $target_user_id ],
                    'POST'
                );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
            } else {
                $result = $this->update_user_id_list( get_current_user_id(), self::META_HIDDEN_STORY_USERS, $target_user_id, 'add' );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function remove_hidden_author( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'user_id' );
            if ( $this->is_story_rest_available() ) {
                $response = $this->call_story_rest_handler(
                    [ 'Koopo_Stories_REST_Story', 'remove_hidden_all_user' ],
                    [ 'user_id' => $target_user_id ],
                    'DELETE'
                );
                if ( is_wp_error( $response ) ) {
                    return $response;
                }
            } else {
                $result = $this->update_user_id_list( get_current_user_id(), self::META_HIDDEN_STORY_USERS, $target_user_id, 'remove' );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function get_story_archive() {
            return rest_ensure_response( $this->get_story_archive_payload( get_current_user_id() ) );
        }

        public function get_blocked_members() {
            return rest_ensure_response( $this->get_blocked_members_payload( get_current_user_id() ) );
        }

        public function remove_blocked_member( WP_REST_Request $request ) {
            $block_id = (int) $request->get_param( 'block_id' );

            if ( $this->is_moderation_blocking_available() ) {
                $moderation = $this->get_blocked_moderation( $block_id );
                if ( ! $moderation || empty( $moderation->item_id ) || empty( $moderation->item_type ) ) {
                    return new WP_Error( 'koopo_account_block_missing', __( 'The selected block could not be found.', 'koopo' ), [ 'status' => 404 ] );
                }

                $deleted = bp_moderation_delete(
                    [
                        'content_id'   => (int) $moderation->item_id,
                        'content_type' => (string) $moderation->item_type,
                    ]
                );

                if ( empty( $deleted ) || ( is_object( $deleted ) && ! empty( $deleted->report_id ) ) ) {
                    return new WP_Error( 'koopo_account_unblock_failed', __( 'Failed to unblock this member.', 'koopo' ), [ 'status' => 500 ] );
                }
            } else {
                $result = $this->update_user_id_list( get_current_user_id(), self::META_BLOCKED_MEMBERS, $block_id, 'remove' );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }

            return rest_ensure_response( $this->get_blocked_members_payload( get_current_user_id() ) );
        }

        public function create_data_export() {
            $user_id         = get_current_user_id();
            $generated_at_ts = current_time( 'timestamp', true );
            $generated_at    = gmdate( 'c', $generated_at_ts );
            $expires_at      = gmdate( 'c', $generated_at_ts + DAY_IN_SECONDS );

            $payload = [
                'generatedAt' => $generated_at,
                'user'        => $this->build_member_summary( get_userdata( $user_id ) ),
                'login'       => [
                    'email' => wp_get_current_user()->user_email,
                ],
                'privacy'     => $this->get_privacy_settings_payload( $user_id ),
                'stories'     => [
                    'settings' => $this->get_story_settings_payload( $user_id ),
                    'archive'  => $this->get_story_archive_payload( $user_id ),
                ],
                'blocked'     => $this->get_blocked_members_payload( $user_id ),
            ];

            $json     = wp_json_encode( $payload, JSON_PRETTY_PRINT );
            $filename = sprintf( 'koopo-account-export-%d-%s.json', $user_id, wp_generate_password( 12, false, false ) );
            $token    = $this->store_data_export( $user_id, $json, $generated_at_ts, $filename );
            if ( is_wp_error( $token ) ) {
                return $token;
            }

            if ( false === $json ) {
                return new WP_Error( 'koopo_account_export_failed', __( 'Failed to generate export file.', 'koopo' ), [ 'status' => 500 ] );
            }

            $privacy_payload      = $this->get_privacy_settings_payload( $user_id );
            $story_settings       = $this->get_story_settings_payload( $user_id );
            $blocked_members      = $this->get_blocked_members_payload( $user_id );
            $story_archive        = $this->get_story_archive_payload( $user_id );
            $hidden_profile_tabs  = 0;

            foreach ( (array) $privacy_payload['tabs'] as $tab ) {
                if ( empty( $tab['isVisible'] ) ) {
                    $hidden_profile_tabs++;
                }
            }

            return rest_ensure_response(
                [
                    'generatedAt' => $generated_at,
                    'expiresAt'   => $expires_at,
                    'fileName'    => $filename,
                    'downloadUrl' => $this->build_data_export_download_url( $token ),
                    'byteSize'    => strlen( $json ),
                    'summary'     => [
                        'blockedMembers'    => count( (array) $blocked_members['items'] ),
                        'closeFriends'      => count( (array) $story_settings['closeFriends'] ),
                        'hiddenStoryAuthors'=> count( (array) $story_settings['hiddenAuthors'] ),
                        'hiddenProfileTabs' => $hidden_profile_tabs,
                        'archivedStories'   => count( (array) $story_archive['items'] ),
                    ],
                ]
            );
        }

        public function stream_data_export() {
            if ( ! is_user_logged_in() ) {
                auth_redirect();
            }

            $user_id = get_current_user_id();
            $token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
            $stored  = $this->get_data_export_payload( $token );

            if ( empty( $token ) || ! is_array( $stored ) || (int) $stored['user_id'] !== (int) $user_id ) {
                wp_die( esc_html__( 'This export link is invalid or has expired.', 'koopo' ), 403 );
            }

            nocache_headers();

            header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
            header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $stored['file_name'] ) . '"' );
            header( 'Content-Length: ' . strlen( $stored['json'] ) );

            echo $stored['json'];
            exit;
        }

        private function store_data_export( $user_id, $json, $generated_at_ts, $filename ) {
            $user_id = absint( $user_id );
            $json    = is_string( $json ) ? $json : '';
            $filename = sanitize_file_name( (string) $filename );

            if ( ! $user_id || '' === $json || '' === $filename ) {
                return new WP_Error( 'koopo_account_export_failed', __( 'Failed to generate export file.', 'koopo' ), [ 'status' => 500 ] );
            }

            $token = wp_generate_uuid4();
            $saved = set_transient(
                $this->get_data_export_transient_key( $token ),
                [
                    'user_id'    => $user_id,
                    'json'       => $json,
                    'file_name'  => $filename,
                    'created_at' => (int) $generated_at_ts,
                ],
                DAY_IN_SECONDS
            );

            if ( ! $saved ) {
                return new WP_Error( 'koopo_account_export_failed', __( 'Failed to generate export file.', 'koopo' ), [ 'status' => 500 ] );
            }

            return $token;
        }

        private function build_data_export_download_url( $token ) {
            return add_query_arg(
                [
                    'action' => self::EXPORT_DOWNLOAD_ACTION,
                    'token'  => (string) $token,
                ],
                admin_url( 'admin-post.php' )
            );
        }

        private function get_data_export_payload( $token ) {
            if ( '' === $token ) {
                return null;
            }

            $stored = get_transient( $this->get_data_export_transient_key( $token ) );
            return is_array( $stored ) ? $stored : null;
        }

        private function get_data_export_transient_key( $token ) {
            return self::EXPORT_TRANSIENT_PREFIX . md5( (string) $token );
        }

        public function delete_account( WP_REST_Request $request ) {
            $user = wp_get_current_user();
            if ( ! ( $user instanceof WP_User ) || $user->ID <= 0 ) {
                return new WP_Error( 'koopo_account_invalid_user', __( 'Authenticated user could not be resolved.', 'koopo' ), [ 'status' => 401 ] );
            }

            $current_password = (string) $request->get_param( 'currentPassword' );
            $confirmation     = strtoupper( trim( (string) $request->get_param( 'confirmation' ) ) );

            if ( '' === trim( $current_password ) || 'DELETE' !== $confirmation ) {
                return new WP_Error( 'koopo_account_delete_invalid', __( 'Current password and DELETE confirmation are required.', 'koopo' ), [ 'status' => 400 ] );
            }

            if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
                return new WP_Error( 'koopo_account_password_incorrect', __( 'Current password is incorrect.', 'koopo' ), [ 'status' => 403 ] );
            }

            require_once ABSPATH . 'wp-admin/includes/user.php';

            $reassign_user_id = $this->get_reassign_user_id( $user->ID );
            $deleted          = wp_delete_user( $user->ID, $reassign_user_id );

            if ( ! $deleted ) {
                return new WP_Error( 'koopo_account_delete_failed', __( 'The account could not be deleted.', 'koopo' ), [ 'status' => 500 ] );
            }

            return rest_ensure_response( [ 'success' => true ] );
        }

        private function get_subscription_summary_payload( $user_id ) {
            $current_pack_id      = $this->get_vendor_pack_id( $user_id );
            $starter_pack_id      = $this->get_starter_pack_id( $user_id );
            $current_plan         = $this->build_current_subscription_payload( $user_id, $starter_pack_id );
            $packs                = $this->get_subscription_packs_payload( $user_id, $current_pack_id, $starter_pack_id );
            $seller_account_active = $this->is_user_seller( $user_id ) || $current_pack_id > 0;

            return [
                'currentPlan'         => $current_plan,
                'packs'               => $packs,
                'upgradeEntryPoints'  => $this->get_upgrade_entry_points_payload(),
                'recommendedProductId'=> $this->get_recommended_pack_product_id( $packs ),
                'starterPackProductId'=> $starter_pack_id > 0 ? $starter_pack_id : null,
                'sellerAccountActive' => $seller_account_active,
                'monetizationUnlocked'=> ! empty( $current_plan['isActive'] ),
            ];
        }

        private function get_subscription_packs_payload( $user_id, $current_pack_id, $starter_pack_id ) {
            $query = new WP_Query(
                [
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 100,
                    'fields'         => 'ids',
                    'orderby'        => 'menu_order title',
                    'order'          => 'ASC',
                    'tax_query'      => [
                        [
                            'taxonomy' => 'product_type',
                            'field'    => 'slug',
                            'terms'    => 'product_pack',
                        ],
                    ],
                    'no_found_rows'  => true,
                ]
            );

            $pack_ids = array_map( 'intval', (array) $query->posts );
            wp_reset_postdata();

            usort(
                $pack_ids,
                function ( $left, $right ) {
                    $left_product  = wc_get_product( $left );
                    $right_product = wc_get_product( $right );
                    $left_price    = $this->get_pack_price_value( $left_product );
                    $right_price   = $this->get_pack_price_value( $right_product );

                    if ( $left_price === $right_price ) {
                        return strnatcasecmp(
                            $left_product ? $left_product->get_name() : '',
                            $right_product ? $right_product->get_name() : ''
                        );
                    }

                    return $left_price < $right_price ? -1 : 1;
                }
            );

            $current_product = $current_pack_id > 0 ? wc_get_product( $current_pack_id ) : null;
            $current_price   = $this->get_pack_price_value( $current_product );
            $items           = [];

            foreach ( $pack_ids as $pack_id ) {
                $item = $this->build_subscription_pack_payload( $pack_id, $current_pack_id, $current_price, $starter_pack_id );
                if ( $item ) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        private function build_subscription_pack_payload( $pack_id, $current_pack_id, $current_price, $starter_pack_id ) {
            $product = wc_get_product( $pack_id );
            if ( ! $product ) {
                return null;
            }

            $price_value          = $this->get_pack_price_value( $product );
            $period_label         = $this->get_pack_period_label( $pack_id );
            $slug                 = $this->detect_pack_slug( $product->get_slug(), $product->get_name() );
            $is_current           = (int) $pack_id === (int) $current_pack_id;
            $is_starter           = (int) $pack_id === (int) $starter_pack_id;
            $is_free              = $price_value <= 0;
            $admin_commission     = get_post_meta( $pack_id, '_subscription_product_admin_commission', true );
            $admin_commission     = is_numeric( $admin_commission ) ? (float) $admin_commission : null;
            $description          = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
            $fallback_description = trim( wp_strip_all_tags( (string) $product->get_description() ) );
            $product_limit        = get_post_meta( $pack_id, '_no_of_product', true );
            $product_limit        = is_numeric( $product_limit ) ? (int) $product_limit : null;
            $features             = $this->build_subscription_features_payload( $pack_id );

            return [
                'productId'           => (int) $pack_id,
                'slug'                => $slug,
                'title'               => (string) $product->get_name(),
                'badge'               => $this->build_pack_badge( $slug, $is_free ),
                'price'               => wc_format_decimal( $price_value, 2 ),
                'priceLabel'          => $this->build_pack_price_label( $price_value, $period_label ),
                'description'         => '' !== $description ? $description : ( '' !== $fallback_description ? wp_trim_words( $fallback_description, 24 ) : '' ),
                'highlights'          => $this->build_pack_highlights( $pack_id, $features, $period_label, $is_starter, $admin_commission ),
                'features'            => $features,
                'isCurrent'           => $is_current,
                'canUpgrade'          => ! $is_current && ( 0 === (float) $current_price || $price_value > (float) $current_price ),
                'isFree'              => $is_free,
                'isStarter'           => $is_starter,
                'productLimit'        => ( null !== $product_limit && $product_limit >= 0 ) ? $product_limit : null,
                'adminCommissionRate' => $admin_commission,
                'intervalLabel'       => $period_label,
            ];
        }

        private function build_current_subscription_payload( $user_id, $starter_pack_id ) {
            $pack_id = $this->get_vendor_pack_id( $user_id );
            if ( $pack_id <= 0 ) {
                return null;
            }

            $product = wc_get_product( $pack_id );
            if ( ! $product ) {
                return null;
            }

            $price_value      = $this->get_pack_price_value( $product );
            $period_label     = $this->get_pack_period_label( $pack_id );
            $slug             = $this->detect_pack_slug( $product->get_slug(), $product->get_name() );
            $is_active        = $this->is_vendor_pack_active( $user_id );
            $start_date       = (string) get_user_meta( $user_id, 'product_pack_startdate', true );
            $end_date         = (string) get_user_meta( $user_id, 'product_pack_enddate', true );
            $admin_commission = get_post_meta( $pack_id, '_subscription_product_admin_commission', true );
            $admin_commission = is_numeric( $admin_commission ) ? (float) $admin_commission : null;
            $product_limit    = get_post_meta( $pack_id, '_no_of_product', true );
            $product_limit    = is_numeric( $product_limit ) ? (int) $product_limit : null;
            $status_label     = $is_active ? __( 'Active subscription', 'koopo' ) : __( 'Inactive subscription', 'koopo' );

            if ( $is_active && '' !== $end_date && 'unlimited' !== strtolower( $end_date ) ) {
                $status_label = sprintf( __( 'Active until %s', 'koopo' ), $this->format_subscription_date( $end_date ) );
            }

            return [
                'productId'           => (int) $pack_id,
                'slug'                => $slug,
                'title'               => (string) $product->get_name(),
                'price'               => wc_format_decimal( $price_value, 2 ),
                'priceLabel'          => $this->build_pack_price_label( $price_value, $period_label ),
                'statusLabel'         => $status_label,
                'startDate'           => '' !== $start_date ? $start_date : null,
                'endDate'             => '' !== $end_date ? $end_date : null,
                'isActive'            => $is_active,
                'isStarter'           => (int) $pack_id === (int) $starter_pack_id,
                'highlights'          => $this->build_pack_highlights( $pack_id, $this->build_subscription_features_payload( $pack_id ), $period_label, (int) $pack_id === (int) $starter_pack_id, $admin_commission ),
                'features'            => $this->build_subscription_features_payload( $pack_id ),
                'productLimit'        => ( null !== $product_limit && $product_limit >= 0 ) ? $product_limit : null,
                'adminCommissionRate' => $admin_commission,
            ];
        }

        private function build_subscription_features_payload( $pack_id ) {
            $feature_map = $this->get_pack_feature_map( $pack_id );

            return [
                [
                    'key'         => 'selling',
                    'label'       => __( 'Sell products', 'koopo' ),
                    'description' => __( 'Launch a storefront, publish products, and accept orders through the commerce flow.', 'koopo' ),
                    'enabled'     => true,
                ],
                [
                    'key'         => 'appointments',
                    'label'       => __( 'Book appointments', 'koopo' ),
                    'description' => __( 'Offer services, manage availability, and take bookings inside the appointments module.', 'koopo' ),
                    'enabled'     => ! empty( $feature_map['appointments'] ),
                ],
                [
                    'key'         => 'event_tickets',
                    'label'       => __( 'Sell event tickets', 'koopo' ),
                    'description' => __( 'Monetize events with paid ticket access and vendor-level ticket workflows.', 'koopo' ),
                    'enabled'     => ! empty( $feature_map['event_tickets'] ),
                ],
                [
                    'key'         => 'pod',
                    'label'       => __( 'Use print-on-demand tools', 'koopo' ),
                    'description' => __( 'Unlock Koopo POD imports and custom merchandise creation workflows.', 'koopo' ),
                    'enabled'     => ! empty( $feature_map['pod'] ),
                ],
                [
                    'key'         => 'ai_tools',
                    'label'       => __( 'Use premium AI tools', 'koopo' ),
                    'description' => __( 'Unlock premium Koopo AI assistants and supported AI-powered creation tools for this subscription pack.', 'koopo' ),
                    'enabled'     => ! empty( $feature_map['ai_tools'] ),
                ],
            ];
        }

        private function get_pack_feature_map( $pack_id ) {
            $features = get_post_meta( $pack_id, '_koopo_features', true );
            if ( is_string( $features ) ) {
                $decoded = json_decode( $features, true );
                if ( is_array( $decoded ) ) {
                    $features = $decoded;
                }
            }

            if ( ! is_array( $features ) ) {
                $features = [];
            }

            $ai_features = get_post_meta( $pack_id, '_koopo_ai_pack_features', true );
            if ( is_string( $ai_features ) ) {
                $decoded = json_decode( $ai_features, true );
                if ( is_array( $decoded ) ) {
                    $ai_features = $decoded;
                }
            }

            if ( ! is_array( $ai_features ) ) {
                $ai_features = [];
            }

            $has_ai_tools = ! empty( $ai_features['premium_ai_tools'] )
                || ! empty( $ai_features['blog_assistant'] )
                || ! empty( $ai_features['product_assistant'] )
                || ! empty( $ai_features['influencer_square'] );

            return [
                'appointments' => ! empty( $features['appointments'] ),
                'event_tickets'=> ! empty( $features['event_tickets'] ),
                'pod'          => 'yes' === (string) get_post_meta( $pack_id, '_koopo_pd_pack_enabled', true ),
                'ai_tools'     => $has_ai_tools,
            ];
        }

        private function get_upgrade_entry_points_payload() {
            return [
                [
                    'key'                 => 'selling',
                    'label'               => __( 'Selling', 'koopo' ),
                    'description'         => __( 'Create a storefront, publish products, and start taking payments.', 'koopo' ),
                    'ctaLabel'            => __( 'Unlock selling', 'koopo' ),
                    'requiredFeatureKeys' => [ 'selling' ],
                ],
                [
                    'key'                 => 'appointments',
                    'label'               => __( 'Appointments', 'koopo' ),
                    'description'         => __( 'Enable services, schedules, and appointment bookings for clients.', 'koopo' ),
                    'ctaLabel'            => __( 'Unlock bookings', 'koopo' ),
                    'requiredFeatureKeys' => [ 'appointments' ],
                ],
                [
                    'key'                 => 'event_tickets',
                    'label'               => __( 'Events', 'koopo' ),
                    'description'         => __( 'Monetize events with ticket sales and vendor event workflows.', 'koopo' ),
                    'ctaLabel'            => __( 'Unlock events', 'koopo' ),
                    'requiredFeatureKeys' => [ 'event_tickets' ],
                ],
                [
                    'key'                 => 'pod',
                    'label'               => __( 'Print on demand', 'koopo' ),
                    'description'         => __( 'Connect POD providers and expand into custom product workflows.', 'koopo' ),
                    'ctaLabel'            => __( 'Unlock POD', 'koopo' ),
                    'requiredFeatureKeys' => [ 'pod' ],
                ],
                [
                    'key'                 => 'ai_tools',
                    'label'               => __( 'Premium AI tools', 'koopo' ),
                    'description'         => __( 'Unlock premium AI assistants for supported Koopo workflows and creator tools.', 'koopo' ),
                    'ctaLabel'            => __( 'Unlock AI tools', 'koopo' ),
                    'requiredFeatureKeys' => [ 'ai_tools' ],
                ],
            ];
        }

        private function get_vendor_pack_id( $user_id ) {
            return absint( get_user_meta( $user_id, 'product_package_id', true ) );
        }

        private function is_user_seller( $user_id ) {
            if ( function_exists( 'dokan_is_user_seller' ) ) {
                return (bool) dokan_is_user_seller( $user_id );
            }

            $user = get_userdata( $user_id );
            if ( ! $user || ! is_array( $user->roles ) ) {
                return false;
            }

            return in_array( 'seller', $user->roles, true );
        }

        private function is_vendor_pack_active( $user_id ) {
            $pack_end = (string) get_user_meta( $user_id, 'product_pack_enddate', true );
            if ( '' === $pack_end ) {
                return false;
            }

            if ( 'unlimited' === strtolower( $pack_end ) ) {
                return true;
            }

            $timezone = wp_timezone();
            $end      = date_create_immutable_from_format( 'Y-m-d H:i:s', $pack_end, $timezone );
            if ( ! $end ) {
                try {
                    $end = new DateTimeImmutable( $pack_end, $timezone );
                } catch ( Exception $exception ) {
                    unset( $exception );
                    return false;
                }
            }

            $now = new DateTimeImmutable( 'now', $timezone );

            return $now < $end;
        }

        private function get_starter_pack_id( $user_id ) {
            $pack_id = absint( get_option( 'koopo_vendor_starter_pack_id', 0 ) );
            if ( $pack_id > 0 && $this->is_valid_pack_id( $pack_id ) ) {
                return $pack_id;
            }

            return $this->find_first_free_pack_id();
        }

        private function is_valid_pack_id( $pack_id ) {
            $product = wc_get_product( $pack_id );
            return $product && $product->is_type( 'product_pack' );
        }

        private function find_first_free_pack_id() {
            $query = new WP_Query(
                [
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                    'fields'         => 'ids',
                    'orderby'        => 'menu_order title',
                    'order'          => 'ASC',
                    'tax_query'      => [
                        [
                            'taxonomy' => 'product_type',
                            'field'    => 'slug',
                            'terms'    => 'product_pack',
                        ],
                    ],
                    'no_found_rows'  => true,
                ]
            );

            foreach ( (array) $query->posts as $pack_id ) {
                $product = wc_get_product( $pack_id );
                if ( $product && $this->get_pack_price_value( $product ) <= 0 ) {
                    wp_reset_postdata();
                    return absint( $pack_id );
                }
            }

            wp_reset_postdata();
            return 0;
        }

        private function get_pack_price_value( $product ) {
            if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
                return 0.0;
            }

            return (float) wc_format_decimal( $product->get_price(), 2 );
        }

        private function get_pack_period_label( $pack_id ) {
            $period = (string) get_post_meta( $pack_id, '_dokan_subscription_period', true );
            if ( '' === $period ) {
                $period = (string) get_post_meta( $pack_id, '_subscription_period', true );
            }

            $interval = absint( get_post_meta( $pack_id, '_dokan_subscription_period_interval', true ) );
            if ( $interval <= 0 ) {
                $interval = absint( get_post_meta( $pack_id, '_subscription_period_interval', true ) );
            }

            if ( '' !== $period ) {
                $interval = max( 1, $interval );
                $period   = strtolower( $period );

                if ( 1 === $interval ) {
                    return $period;
                }

                return sprintf( '%d %ss', $interval, $period );
            }

            $validity = absint( get_post_meta( $pack_id, '_pack_validity', true ) );
            if ( $validity > 0 ) {
                return sprintf( '%d days', $validity );
            }

            return __( 'forever', 'koopo' );
        }

        private function build_pack_price_label( $price_value, $period_label ) {
            if ( $price_value <= 0 ) {
                return __( 'Free', 'koopo' );
            }

            $formatted = html_entity_decode( wp_strip_all_tags( wc_price( $price_value ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
            if ( '' === $period_label || 'forever' === strtolower( $period_label ) ) {
                return $formatted;
            }

            return sprintf( '%1$s / %2$s', $formatted, $period_label );
        }

        private function build_pack_highlights( $pack_id, $features, $period_label, $is_starter, $admin_commission ) {
            $highlights    = [];
            $product_limit = get_post_meta( $pack_id, '_no_of_product', true );

            if ( '-1' === (string) $product_limit ) {
                $highlights[] = __( 'Unlimited product listings', 'koopo' );
            } elseif ( is_numeric( $product_limit ) && (int) $product_limit > 0 ) {
                $highlights[] = sprintf( __( 'List up to %d products', 'koopo' ), (int) $product_limit );
            }

            if ( '' !== $period_label ) {
                $highlights[] = sprintf( __( 'Billing cycle: %s', 'koopo' ), $period_label );
            }

            if ( $is_starter ) {
                $highlights[] = __( 'Assigned automatically when a seller account is activated', 'koopo' );
            } else {
                $highlights[] = __( 'Includes Starter tier access automatically', 'koopo' );
            }

            foreach ( (array) $features as $feature ) {
                if ( ! empty( $feature['enabled'] ) && ! empty( $feature['label'] ) && 'selling' !== $feature['key'] ) {
                    $highlights[] = (string) $feature['label'];
                }
            }

            if ( null !== $admin_commission ) {
                $highlights[] = sprintf( __( 'Referral fee: %s%%', 'koopo' ), wc_format_decimal( $admin_commission, 2 ) );
            }

            return array_slice( array_values( array_unique( array_filter( $highlights ) ) ), 0, 6 );
        }

        private function build_pack_badge( $slug, $is_free ) {
            if ( $is_free || false !== strpos( $slug, 'starter' ) || false !== strpos( $slug, 'free' ) ) {
                return __( 'Free', 'koopo' );
            }

            if ( false !== strpos( $slug, 'entrepreneur' ) || false !== strpos( $slug, 'growth' ) ) {
                return __( 'Growth', 'koopo' );
            }

            if ( false !== strpos( $slug, 'business' ) || false !== strpos( $slug, 'scale' ) ) {
                return __( 'Scale', 'koopo' );
            }

            if ( false !== strpos( $slug, 'ultimate' ) || false !== strpos( $slug, 'premium' ) ) {
                return __( 'Premium', 'koopo' );
            }

            return __( 'Plan', 'koopo' );
        }

        private function detect_pack_slug( $product_slug, $title ) {
            $slug = sanitize_title( $product_slug );
            if ( '' !== $slug ) {
                return $slug;
            }

            return sanitize_title( $title );
        }

        private function get_recommended_pack_product_id( $packs ) {
            $fallback = 0;

            foreach ( (array) $packs as $pack ) {
                if ( empty( $fallback ) && ! empty( $pack['productId'] ) ) {
                    $fallback = (int) $pack['productId'];
                }

                $slug = isset( $pack['slug'] ) ? (string) $pack['slug'] : '';
                if ( false !== strpos( $slug, 'business' ) && ! empty( $pack['productId'] ) ) {
                    return (int) $pack['productId'];
                }
            }

            return $fallback > 0 ? $fallback : null;
        }

        private function format_subscription_date( $value ) {
            $timestamp = strtotime( (string) $value );
            if ( ! $timestamp ) {
                return (string) $value;
            }

            return wp_date( get_option( 'date_format' ), $timestamp );
        }

        private function get_privacy_settings_payload( $user_id ) {
            $profile_tabs = $this->get_profile_tabs_controller();
            $visibility   = 'public';
            $tabs         = [];

            if ( $profile_tabs ) {
                $visibility  = $profile_tabs->get_profile_visibility_for_user( $user_id );
                $controlled  = $profile_tabs->get_controlled_tabs();
                $hidden_tabs = $profile_tabs->get_hidden_tabs_for_user( $user_id );

                foreach ( (array) $controlled as $slug => $label ) {
                    $tabs[] = [
                        'key'           => (string) $slug,
                        'label'         => (string) $label,
                        'isVisible'     => ! in_array( $slug, $hidden_tabs, true ),
                        'alwaysVisible' => false,
                    ];
                }
            }

            return [
                'profileVisibility' => $visibility,
                'tabs'              => $tabs,
            ];
        }

        private function get_notification_settings_payload() {
            $endpoint = $this->get_buddyboss_account_settings_endpoint();
            if ( ! $endpoint || ! method_exists( $endpoint, 'get_notifications_fields' ) ) {
                return [
                    'masterControls' => [],
                    'groups'         => [],
                ];
            }

            $fields = $endpoint->get_notifications_fields();
            return $this->normalize_notification_fields_payload( $fields );
        }

        private function get_story_settings_payload( $user_id ) {
            if ( $this->is_story_rest_available() ) {
                $close_friends_response = $this->call_story_rest_handler( [ 'Koopo_Stories_REST_Story', 'get_close_friends' ] );
                $hidden_users_response  = $this->call_story_rest_handler( [ 'Koopo_Stories_REST_Story', 'get_hidden_all_users' ] );

                $close_friends_data = is_wp_error( $close_friends_response ) ? [] : $this->get_rest_response_data( $close_friends_response );
                $hidden_users_data  = is_wp_error( $hidden_users_response ) ? [] : $this->get_rest_response_data( $hidden_users_response );

                $close_friends = [];
                foreach ( (array) ( $close_friends_data['friends'] ?? [] ) as $friend ) {
                    $summary = $this->build_member_summary( $friend );
                    if ( $summary ) {
                        $close_friends[] = $summary;
                    }
                }

                $hidden_users = [];
                foreach ( (array) ( $hidden_users_data['users'] ?? [] ) as $user ) {
                    $summary = $this->build_member_summary( $user );
                    if ( $summary ) {
                        $hidden_users[] = $summary;
                    }
                }

                return [
                    'closeFriends'  => $close_friends,
                    'hiddenAuthors' => $hidden_users,
                ];
            }

            return [
                'closeFriends'  => $this->build_member_list_from_ids( $this->get_user_id_meta( $user_id, self::META_CLOSE_FRIENDS ) ),
                'hiddenAuthors' => $this->build_member_list_from_ids( $this->get_user_id_meta( $user_id, self::META_HIDDEN_STORY_USERS ) ),
            ];
        }

        private function get_story_archive_payload( $user_id ) {
            if ( $this->is_story_rest_available() && class_exists( 'Koopo_Stories_REST_Feed' ) ) {
                $response = $this->call_story_rest_handler(
                    [ 'Koopo_Stories_REST_Feed', 'get_archived_stories' ],
                    [
                        'limit'  => 60,
                        'page'   => 1,
                        'mobile' => 1,
                    ]
                );

                if ( ! is_wp_error( $response ) ) {
                    $data  = $this->get_rest_response_data( $response );
                    $items = [];

                    foreach ( (array) ( $data['stories'] ?? [] ) as $story_item ) {
                        $story_id = isset( $story_item['story_id'] ) ? (int) $story_item['story_id'] : 0;
                        $item_id  = isset( $story_item['item_id'] ) ? (int) $story_item['item_id'] : 0;

                        if ( $story_id <= 0 || $item_id <= 0 ) {
                            continue;
                        }

                        $items[] = [
                            'storyId'      => $story_id,
                            'itemId'       => $item_id,
                            'author'       => $this->build_member_summary( $story_item['author'] ?? [] ),
                            'coverThumbUrl'=> isset( $story_item['cover_thumb'] ) ? (string) $story_item['cover_thumb'] : '',
                            'mediaUrl'     => isset( $story_item['item_src'] ) ? (string) $story_item['item_src'] : '',
                            'mediaType'    => ( isset( $story_item['item_type'] ) && 'video' === strtolower( (string) $story_item['item_type'] ) ) ? 'video' : 'image',
                            'createdAt'    => isset( $story_item['created_at'] ) ? (string) $story_item['created_at'] : '',
                            'updatedAt'    => isset( $story_item['last_updated'] ) ? (string) $story_item['last_updated'] : '',
                            'privacy'      => $this->normalize_story_privacy( $story_item['privacy'] ?? 'public' ),
                            'viewCount'    => isset( $story_item['view_count'] ) ? (int) $story_item['view_count'] : 0,
                            'isArchived'   => ! empty( $story_item['is_archived'] ),
                        ];
                    }

                    return [
                        'items'   => $items,
                        'page'    => isset( $data['page'] ) ? max( 1, (int) $data['page'] ) : 1,
                        'hasMore' => ! empty( $data['has_more'] ),
                    ];
                }
            }

            if ( ! post_type_exists( 'koopo_story' ) ) {
                return [
                    'items'   => [],
                    'page'    => 1,
                    'hasMore' => false,
                ];
            }

            $query = new WP_Query(
                [
                    'post_type'      => 'koopo_story',
                    'author'         => $user_id,
                    'post_status'    => [ 'publish', 'private' ],
                    'posts_per_page' => 30,
                    'orderby'        => 'modified',
                    'order'          => 'DESC',
                ]
            );

            $items = [];
            foreach ( (array) $query->posts as $story_post ) {
                $story_id     = (int) $story_post->ID;
                $is_archived  = $this->is_story_archived( $story_post );

                if ( ! $is_archived ) {
                    continue;
                }

                $items[] = [
                    'storyId'      => $story_id,
                    'itemId'       => $story_id,
                    'author'       => $this->build_member_summary( get_userdata( $user_id ) ),
                    'coverThumbUrl'=> get_the_post_thumbnail_url( $story_id, 'large' ) ?: '',
                    'mediaUrl'     => get_the_post_thumbnail_url( $story_id, 'full' ) ?: '',
                    'mediaType'    => 'image',
                    'createdAt'    => get_post_time( 'c', true, $story_post ),
                    'updatedAt'    => get_post_modified_time( 'c', true, $story_post ),
                    'privacy'      => $this->normalize_story_privacy( get_post_meta( $story_id, '_koopo_story_privacy', true ) ),
                    'viewCount'    => 0,
                    'isArchived'   => true,
                ];
            }

            wp_reset_postdata();

            return [
                'items'   => $items,
                'page'    => 1,
                'hasMore' => false,
            ];
        }

        private function get_blocked_members_payload( $user_id ) {
            if ( $this->is_moderation_blocking_available() ) {
                $moderations = bp_moderation_get(
                    [
                        'user_id'           => $user_id,
                        'page'              => 1,
                        'per_page'          => 100,
                        'sort'              => 'DESC',
                        'order_by'          => 'last_updated',
                        'in_types'          => [ BP_Moderation_Members::$moderation_type ],
                        'update_meta_cache' => false,
                        'count_total'       => false,
                        'display_reporters' => false,
                        'filter'            => [
                            'hide_sitewide' => 0,
                        ],
                    ]
                );

                $items = [];
                foreach ( (array) ( $moderations['moderations'] ?? [] ) as $moderation ) {
                    if ( empty( $moderation->item_id ) ) {
                        continue;
                    }

                    $summary = $this->build_member_summary( get_userdata( (int) $moderation->item_id ) );
                    if ( ! $summary ) {
                        continue;
                    }

                    $summary['blockId'] = (int) $moderation->id;
                    $items[]            = $summary;
                }

                return [
                    'items' => $items,
                ];
            }

            return [
                'items' => $this->build_member_list_from_ids( $this->get_user_id_meta( $user_id, self::META_BLOCKED_MEMBERS ) ),
            ];
        }

        private function get_buddyboss_account_settings_endpoint() {
            if ( class_exists( 'BP_REST_Account_Settings_Options_Endpoint' ) ) {
                return new BP_REST_Account_Settings_Options_Endpoint();
            }

            return null;
        }

        private function normalize_notification_fields_payload( $fields ) {
            $master_controls = [];
            $groups          = [];
            $current_group   = null;

            foreach ( (array) $fields as $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }

                $group_label = isset( $field['group_label'] ) ? trim( wp_strip_all_tags( (string) $field['group_label'] ) ) : '';
                if ( '' !== $group_label ) {
                    $current_group = [
                        'key'   => sanitize_key( $group_label ) ?: 'group-' . ( count( $groups ) + 1 ),
                        'label' => $group_label,
                        'items' => [],
                    ];
                    $groups[] = $current_group;
                    continue;
                }

                $channels = $this->build_notification_channel_payload( $field['subfields'] ?? [] );
                if ( empty( $channels ) ) {
                    continue;
                }

                if ( $this->is_notification_master_control( $channels, $field ) ) {
                    $master_controls = array_values( array_merge( $master_controls, $channels ) );
                    continue;
                }

                if ( empty( $groups ) ) {
                    $groups[] = [
                        'key'   => 'general',
                        'label' => __( 'General', 'koopo' ),
                        'items' => [],
                    ];
                }

                $group_index = count( $groups ) - 1;
                $label       = isset( $field['label'] ) ? trim( wp_strip_all_tags( (string) $field['label'] ) ) : __( 'Notification', 'koopo' );
                $item_key    = sanitize_key( ! empty( $field['name'] ) ? (string) $field['name'] : $label );
                if ( '' === $item_key ) {
                    $item_key = 'item-' . ( count( (array) $groups[ $group_index ]['items'] ) + 1 );
                }

                $groups[ $group_index ]['items'][] = [
                    'key'      => $item_key,
                    'label'    => $label,
                    'channels' => array_values( $channels ),
                ];
            }

            $groups = array_values(
                array_filter(
                    $groups,
                    function ( $group ) {
                        return ! empty( $group['items'] );
                    }
                )
            );

            return [
                'masterControls' => array_values( $master_controls ),
                'groups'         => $groups,
            ];
        }

        private function build_notification_channel_payload( $subfields ) {
            $channels = [];

            foreach ( (array) $subfields as $subfield ) {
                if ( ! is_array( $subfield ) || empty( $subfield['name'] ) ) {
                    continue;
                }

                $setting_key = sanitize_key( (string) $subfield['name'] );
                if ( '' === $setting_key ) {
                    continue;
                }

                $channel = $this->map_notification_channel( $setting_key, $subfield['label'] ?? '' );
                if ( '' === $channel ) {
                    continue;
                }

                $channels[] = [
                    'settingKey' => $setting_key,
                    'channel'    => $channel,
                    'label'      => trim( wp_strip_all_tags( (string) ( $subfield['label'] ?? ucfirst( $channel ) ) ) ),
                    'enabled'    => $this->is_notification_value_enabled( $subfield['value'] ?? false ),
                    'disabled'   => ! empty( $subfield['disabled'] ),
                ];
            }

            return $channels;
        }

        private function map_notification_channel( $setting_key, $label = '' ) {
            $normalized_key   = strtolower( (string) $setting_key );
            $normalized_label = strtolower( trim( (string) $label ) );

            if ( false !== strpos( $normalized_key, '_web' ) || false !== strpos( $normalized_label, 'web' ) ) {
                return 'web';
            }

            if ( false !== strpos( $normalized_key, '_app' ) || false !== strpos( $normalized_label, 'app' ) ) {
                return 'app';
            }

            return 'email';
        }

        private function is_notification_master_control( $channels, $field ) {
            if ( empty( $channels ) || ! empty( $field['name'] ) ) {
                return false;
            }

            foreach ( (array) $channels as $channel ) {
                if ( empty( $channel['settingKey'] ) || 0 !== strpos( (string) $channel['settingKey'], 'enable_notification_' ) ) {
                    return false;
                }
            }

            return true;
        }

        private function is_notification_value_enabled( $value ) {
            if ( true === $value || 1 === $value || '1' === $value ) {
                return true;
            }

            $normalized = strtolower( trim( (string) $value ) );
            return in_array( $normalized, [ 'yes', 'true', 'on' ], true );
        }

        private function is_story_rest_available() {
            return class_exists( 'Koopo_Stories_REST_Story' ) && class_exists( 'Koopo_Stories_REST_Feed' );
        }

        private function call_story_rest_handler( $callback, $params = [], $method = 'GET' ) {
            if ( ! is_callable( $callback ) ) {
                return new WP_Error( 'koopo_account_story_handler_missing', __( 'Story settings handler is unavailable.', 'koopo' ), [ 'status' => 501 ] );
            }

            $request = new WP_REST_Request( $method );
            foreach ( (array) $params as $key => $value ) {
                $request->set_param( $key, $value );
            }

            return call_user_func( $callback, $request );
        }

        private function get_rest_response_data( $response ) {
            if ( is_wp_error( $response ) ) {
                return [];
            }

            if ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) {
                $data = $response->get_data();
                return is_array( $data ) ? $data : [];
            }

            return is_array( $response ) ? $response : [];
        }

        private function is_moderation_blocking_available() {
            return function_exists( 'bp_moderation_get' )
                && function_exists( 'bp_moderation_delete' )
                && function_exists( 'bp_is_moderation_member_blocking_enable' )
                && class_exists( 'BP_Moderation_Members' )
                && bp_is_moderation_member_blocking_enable( 0 );
        }

        private function get_blocked_moderation( $block_id ) {
            if ( ! $this->is_moderation_blocking_available() ) {
                return null;
            }

            $moderations = bp_moderation_get(
                [
                    'user_id'           => get_current_user_id(),
                    'in'                => [ (int) $block_id ],
                    'page'              => 1,
                    'per_page'          => 1,
                    'sort'              => 'DESC',
                    'order_by'          => 'last_updated',
                    'in_types'          => [ BP_Moderation_Members::$moderation_type ],
                    'update_meta_cache' => false,
                    'count_total'       => false,
                    'display_reporters' => false,
                    'filter'            => [
                        'hide_sitewide' => 0,
                    ],
                ]
            );

            if ( ! empty( $moderations['moderations'][0] ) ) {
                return $moderations['moderations'][0];
            }

            return null;
        }

        private function get_profile_tabs_controller() {
            if ( $this->profile_tabs instanceof Koopo_BuddyBoss_Profile_Tabs ) {
                return $this->profile_tabs;
            }

            if ( class_exists( 'Koopo_BuddyBoss_Profile_Tabs' ) ) {
                $this->profile_tabs = new Koopo_BuddyBoss_Profile_Tabs();
                return $this->profile_tabs;
            }

            return null;
        }

        private function get_user_id_meta( $user_id, $meta_key ) {
            $values = get_user_meta( $user_id, $meta_key, true );
            if ( ! is_array( $values ) ) {
                return [];
            }

            return array_values(
                array_unique(
                    array_filter(
                        array_map( 'intval', $values ),
                        function ( $value ) use ( $user_id ) {
                            return $value > 0 && (int) $user_id !== (int) $value;
                        }
                    )
                )
            );
        }

        private function update_user_id_list( $user_id, $meta_key, $target_user_id, $operation ) {
            $target_user_id = (int) $target_user_id;
            if ( $user_id <= 0 || $target_user_id <= 0 || $user_id === $target_user_id ) {
                return new WP_Error( 'koopo_account_invalid_target', __( 'A valid target member is required.', 'koopo' ), [ 'status' => 400 ] );
            }

            if ( ! get_userdata( $target_user_id ) ) {
                return new WP_Error( 'koopo_account_target_missing', __( 'The selected member no longer exists.', 'koopo' ), [ 'status' => 404 ] );
            }

            $values = $this->get_user_id_meta( $user_id, $meta_key );

            if ( 'add' === $operation ) {
                $values[] = $target_user_id;
            } else {
                $values = array_values( array_diff( $values, [ $target_user_id ] ) );
            }

            $values = array_values( array_unique( array_map( 'intval', $values ) ) );
            update_user_meta( $user_id, $meta_key, $values );

            return true;
        }

        private function build_member_list_from_ids( $user_ids ) {
            $items = [];
            foreach ( (array) $user_ids as $user_id ) {
                $summary = $this->build_member_summary( get_userdata( (int) $user_id ) );
                if ( $summary ) {
                    $items[] = $summary;
                }
            }

            return $items;
        }

        private function build_member_summary( $user ) {
            if ( $user instanceof WP_User ) {
                $avatar_url  = get_avatar_url( $user->ID, [ 'size' => 96 ] );
                $profile_url = function_exists( 'bp_core_get_user_domain' )
                    ? bp_core_get_user_domain( $user->ID )
                    : get_author_posts_url( $user->ID );

                return [
                    'id'          => (int) $user->ID,
                    'displayName' => (string) $user->display_name,
                    'username'    => (string) $user->user_login,
                    'avatarUrl'   => $avatar_url ? (string) $avatar_url : '',
                    'profileUrl'  => $profile_url ? (string) $profile_url : '',
                ];
            }

            if ( is_object( $user ) ) {
                $user = (array) $user;
            }

            if ( ! is_array( $user ) ) {
                return null;
            }

            $user_id = isset( $user['id'] ) ? (int) $user['id'] : ( isset( $user['ID'] ) ? (int) $user['ID'] : 0 );
            if ( $user_id <= 0 ) {
                return null;
            }

            $display_name = '';
            if ( ! empty( $user['displayName'] ) ) {
                $display_name = (string) $user['displayName'];
            } elseif ( ! empty( $user['display_name'] ) ) {
                $display_name = (string) $user['display_name'];
            } elseif ( ! empty( $user['name'] ) ) {
                $display_name = (string) $user['name'];
            } elseif ( ! empty( $user['user_login'] ) ) {
                $display_name = (string) $user['user_login'];
            }

            $username = '';
            if ( ! empty( $user['username'] ) ) {
                $username = (string) $user['username'];
            } elseif ( ! empty( $user['user_login'] ) ) {
                $username = (string) $user['user_login'];
            }

            $avatar_url = '';
            if ( ! empty( $user['avatarUrl'] ) ) {
                $avatar_url = (string) $user['avatarUrl'];
            } elseif ( ! empty( $user['avatar_url'] ) ) {
                $avatar_url = (string) $user['avatar_url'];
            } elseif ( ! empty( $user['avatar'] ) ) {
                $avatar_url = (string) $user['avatar'];
            } else {
                $avatar_url = (string) get_avatar_url( $user_id, [ 'size' => 96 ] );
            }

            $profile_url = '';
            if ( ! empty( $user['profileUrl'] ) ) {
                $profile_url = (string) $user['profileUrl'];
            } elseif ( ! empty( $user['profile_url'] ) ) {
                $profile_url = (string) $user['profile_url'];
            } elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
                $profile_url = (string) bp_core_get_user_domain( $user_id );
            }

            return [
                'id'          => $user_id,
                'displayName' => '' !== $display_name ? $display_name : sprintf( __( 'User #%d', 'koopo' ), $user_id ),
                'username'    => $username,
                'avatarUrl'   => $avatar_url ? (string) $avatar_url : '',
                'profileUrl'  => $profile_url ? (string) $profile_url : '',
            ];
        }

        private function get_story_items_count( $story_id ) {
            if ( post_type_exists( 'koopo_story_item' ) ) {
                $children = get_posts(
                    [
                        'post_type'      => 'koopo_story_item',
                        'post_parent'    => $story_id,
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                    ]
                );

                if ( is_array( $children ) && ! empty( $children ) ) {
                    return count( $children );
                }
            }

            $stored = (int) get_post_meta( $story_id, '_koopo_story_items_count', true );
            return $stored > 0 ? $stored : 1;
        }

        private function is_story_archived( $story_post ) {
            $explicit = get_post_meta( $story_post->ID, '_koopo_story_is_archived', true );
            if ( '' !== (string) $explicit ) {
                return in_array( strtolower( (string) $explicit ), [ '1', 'true', 'yes' ], true );
            }

            $modified_gmt = strtotime( (string) $story_post->post_modified_gmt );
            if ( ! $modified_gmt ) {
                return false;
            }

            return $modified_gmt <= ( time() - DAY_IN_SECONDS );
        }

        private function normalize_story_privacy( $privacy ) {
            $privacy = sanitize_key( (string) $privacy );
            if ( 'close-friends' === $privacy ) {
                return 'close_friends';
            }

            if ( in_array( $privacy, [ 'public', 'friends', 'close_friends' ], true ) ) {
                return $privacy;
            }

            return 'public';
        }

        private function get_reassign_user_id( $deleted_user_id ) {
            $admin_users = get_users(
                [
                    'role'    => 'administrator',
                    'exclude' => [ (int) $deleted_user_id ],
                    'number'  => 1,
                    'fields'  => 'ids',
                ]
            );

            if ( ! empty( $admin_users ) ) {
                return (int) $admin_users[0];
            }

            return null;
        }
    }
}
