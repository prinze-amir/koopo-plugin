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

        /**
         * @var Koopo_BuddyBoss_Profile_Tabs|null
         */
        private $profile_tabs;

        public function __construct( $profile_tabs = null ) {
            $this->profile_tabs = $profile_tabs instanceof Koopo_BuddyBoss_Profile_Tabs ? $profile_tabs : null;
        }

        public function init() {
            add_action( 'rest_api_init', [ $this, 'register_routes' ] );
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
                '/account/blocked-members/(?P<user_id>\d+)',
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

        public function get_story_settings() {
            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function search_story_users( WP_REST_Request $request ) {
            $query = trim( (string) $request->get_param( 'query' ) );
            if ( strlen( $query ) < 2 ) {
                return rest_ensure_response( [ 'items' => [] ] );
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
            $result         = $this->update_user_id_list( get_current_user_id(), self::META_CLOSE_FRIENDS, $target_user_id, 'add' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function remove_close_friend( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'user_id' );
            $result         = $this->update_user_id_list( get_current_user_id(), self::META_CLOSE_FRIENDS, $target_user_id, 'remove' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function add_hidden_author( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'userId' );
            $result         = $this->update_user_id_list( get_current_user_id(), self::META_HIDDEN_STORY_USERS, $target_user_id, 'add' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return rest_ensure_response( $this->get_story_settings_payload( get_current_user_id() ) );
        }

        public function remove_hidden_author( WP_REST_Request $request ) {
            $target_user_id = (int) $request->get_param( 'user_id' );
            $result         = $this->update_user_id_list( get_current_user_id(), self::META_HIDDEN_STORY_USERS, $target_user_id, 'remove' );
            if ( is_wp_error( $result ) ) {
                return $result;
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
            $target_user_id = (int) $request->get_param( 'user_id' );
            $result         = $this->update_user_id_list( get_current_user_id(), self::META_BLOCKED_MEMBERS, $target_user_id, 'remove' );
            if ( is_wp_error( $result ) ) {
                return $result;
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

            $json  = wp_json_encode( $payload, JSON_PRETTY_PRINT );
            $file  = wp_upload_bits( sprintf( 'koopo-account-export-%d-%s.json', $user_id, wp_generate_password( 12, false, false ) ), null, $json );
            $error = isset( $file['error'] ) ? trim( (string) $file['error'] ) : '';

            if ( '' !== $error ) {
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
                    'fileName'    => basename( (string) $file['file'] ),
                    'downloadUrl' => (string) $file['url'],
                    'byteSize'    => file_exists( $file['file'] ) ? (int) filesize( $file['file'] ) : 0,
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

        private function get_story_settings_payload( $user_id ) {
            return [
                'closeFriends'  => $this->build_member_list_from_ids( $this->get_user_id_meta( $user_id, self::META_CLOSE_FRIENDS ) ),
                'hiddenAuthors' => $this->build_member_list_from_ids( $this->get_user_id_meta( $user_id, self::META_HIDDEN_STORY_USERS ) ),
            ];
        }

        private function get_story_archive_payload( $user_id ) {
            if ( ! post_type_exists( 'koopo_story' ) ) {
                return [ 'items' => [] ];
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
                    'title'        => get_the_title( $story_id ) ?: sprintf( __( 'Story #%d', 'koopo' ), $story_id ),
                    'coverThumbUrl'=> get_the_post_thumbnail_url( $story_id, 'large' ) ?: '',
                    'updatedAt'    => get_post_modified_time( 'c', true, $story_post ),
                    'privacy'      => $this->normalize_story_privacy( get_post_meta( $story_id, '_koopo_story_privacy', true ) ),
                    'itemsCount'   => $this->get_story_items_count( $story_id ),
                    'isArchived'   => true,
                ];
            }

            wp_reset_postdata();

            return [ 'items' => $items ];
        }

        private function get_blocked_members_payload( $user_id ) {
            return [
                'items' => $this->build_member_list_from_ids( $this->get_user_id_meta( $user_id, self::META_BLOCKED_MEMBERS ) ),
            ];
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
            if ( ! ( $user instanceof WP_User ) ) {
                return null;
            }

            $avatar_url = get_avatar_url( $user->ID, [ 'size' => 96 ] );
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
