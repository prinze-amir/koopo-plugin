<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Koopo_BuddyBoss_Profile_Tabs' ) ) {

    class Koopo_BuddyBoss_Profile_Tabs {

        const META_HIDDEN_TABS = '_koopo_bb_hidden_profile_tabs';
        const SETTINGS_ACTION  = 'profile-tabs';
        const SETTINGS_NONCE   = 'koopo_bb_profile_tabs_nonce';

        /**
         * Tabs that can never be hidden.
         *
         * @var string[]
         */
        private $always_visible_tabs = [ 'profile', 'activity', 'timeline' ];

        public function init() {
            if ( ! function_exists( 'buddypress' ) ) {
                return;
            }

            add_action( 'bp_setup_nav', [ $this, 'register_settings_subnav' ], 200 );
            add_action( 'bp_actions', [ $this, 'maybe_save_settings' ], 5 );
            add_action( 'bp_actions', [ $this, 'filter_profile_tabs_for_viewers' ], 20 );
        }

        public function register_settings_subnav() {
            if ( ! function_exists( 'bp_get_settings_slug' ) ) {
                return;
            }

            // Determine user to use.
            if ( bp_displayed_user_domain() ) {
                $user_domain = bp_displayed_user_domain();
            } elseif ( bp_loggedin_user_domain() ) {
                $user_domain = bp_loggedin_user_domain();
            } else {
                return;
            }

            $settings_slug = bp_get_settings_slug();
            $settings_link = trailingslashit( $user_domain . $settings_slug );
            $access        = function_exists( 'bp_core_can_edit_settings' ) ? bp_core_can_edit_settings() : bp_is_my_profile();

            bp_core_new_subnav_item(
                [
                    'name'            => __( 'Profile Tabs', 'koopo' ),
                    'slug'            => self::SETTINGS_ACTION,
                    'parent_url'      => $settings_link,
                    'parent_slug'     => $settings_slug,
                    'screen_function' => [ $this, 'settings_screen' ],
                    'position'        => 55,
                    'user_has_access' => $access,
                ]
            );
        }

        public function settings_screen() {
            add_action( 'bp_template_title', [ $this, 'render_settings_title' ] );
            add_action( 'bp_template_content', [ $this, 'render_settings_content' ] );

            bp_core_load_template( apply_filters( 'koopo_bb_profile_tabs_settings_template', 'members/single/plugins' ) );
        }

        public function render_settings_title() {
            esc_html_e( 'Profile Tabs', 'koopo' );
        }

        public function render_settings_content() {
            $user_id     = bp_displayed_user_id();
            $hidden_tabs = $this->get_user_hidden_tabs( $user_id );
            $tabs        = $this->get_available_controlled_tabs();
            ?>
            <form action="" method="post" class="standard-form" id="koopo-profile-tabs-form">
                <p><?php esc_html_e( 'Choose which profile tabs should be hidden from other users. Profile and Timeline are always visible.', 'koopo' ); ?></p>

                <?php if ( empty( $tabs ) ) : ?>
                    <p><?php esc_html_e( 'No configurable profile tabs were found.', 'koopo' ); ?></p>
                <?php else : ?>
                    <fieldset>
                        <?php foreach ( $tabs as $slug => $label ) : ?>
                            <div class="bb-field-wrap">
                                <label>
                                    <input
                                        type="checkbox"
                                        name="koopo_hidden_profile_tabs[]"
                                        value="<?php echo esc_attr( $slug ); ?>"
                                        <?php checked( in_array( $slug, $hidden_tabs, true ) ); ?>
                                    />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php wp_nonce_field( self::SETTINGS_NONCE, self::SETTINGS_NONCE ); ?>
                <input type="hidden" name="koopo_profile_tabs_submit" value="1" />
                <?php bp_nouveau_submit_button( 'members-general-settings' ); ?>
            </form>
            <?php
        }

        public function maybe_save_settings() {
            if ( ! bp_is_settings_component() || ! bp_is_current_action( self::SETTINGS_ACTION ) ) {
                return;
            }

            if ( ! bp_is_post_request() || empty( $_POST['koopo_profile_tabs_submit'] ) ) {
                return;
            }

            if ( ! isset( $_POST[ self::SETTINGS_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::SETTINGS_NONCE ] ) ), self::SETTINGS_NONCE ) ) {
                bp_core_add_message( __( 'Security check failed. Please try again.', 'koopo' ), 'error' );
                bp_core_redirect( $this->get_settings_url() );
            }

            if ( ! $this->can_edit_displayed_user_settings() ) {
                bp_core_add_message( __( 'You are not allowed to edit these settings.', 'koopo' ), 'error' );
                bp_core_redirect( $this->get_settings_url() );
            }

            $tabs        = $this->get_available_controlled_tabs();
            $valid_slugs = array_keys( $tabs );

            $requested_hidden = [];
            if ( ! empty( $_POST['koopo_hidden_profile_tabs'] ) && is_array( $_POST['koopo_hidden_profile_tabs'] ) ) {
                $requested_hidden = array_map(
                    'sanitize_key',
                    wp_unslash( $_POST['koopo_hidden_profile_tabs'] )
                );
            }

            $requested_hidden = array_values( array_unique( array_intersect( $requested_hidden, $valid_slugs ) ) );

            update_user_meta( bp_displayed_user_id(), self::META_HIDDEN_TABS, $requested_hidden );

            bp_core_add_message( __( 'Profile tab visibility updated.', 'koopo' ), 'success' );
            bp_core_redirect( $this->get_settings_url() );
        }

        public function filter_profile_tabs_for_viewers() {
            if ( ! bp_is_user() ) {
                return;
            }

            $displayed_user_id = bp_displayed_user_id();
            if ( ! $displayed_user_id ) {
                return;
            }

            // Profile owner and admins/moderators can always see all tabs.
            if ( bp_is_my_profile() || $this->is_admin_or_moderator() ) {
                return;
            }

            $bp = buddypress();
            if ( empty( $bp->members ) || empty( $bp->members->nav ) ) {
                return;
            }

            $nav_items = $bp->members->nav->get_primary( [], false );
            if ( empty( $nav_items ) ) {
                return;
            }

            $hidden_tabs = $this->get_user_hidden_tabs( $displayed_user_id );
            $current     = bp_current_component();

            $current_hidden = false;
            foreach ( $nav_items as $nav_item ) {
                if ( empty( $nav_item->slug ) ) {
                    continue;
                }

                $slug = sanitize_key( $nav_item->slug );

                if ( $this->is_always_visible_tab( $slug ) || ! $this->is_public_tab( $nav_item ) ) {
                    continue;
                }

                $hide_tab = in_array( $slug, $hidden_tabs, true ) || $this->tab_has_no_content( $slug, $displayed_user_id, $nav_item );

                if ( ! $hide_tab ) {
                    continue;
                }

                bp_core_remove_nav_item( $slug, 'members' );

                if ( $current === $slug ) {
                    $current_hidden = true;
                }
            }

            if ( $current_hidden && ! wp_doing_ajax() ) {
                bp_core_redirect( trailingslashit( bp_displayed_user_domain() ) );
            }
        }

        private function can_edit_displayed_user_settings() {
            if ( bp_is_my_profile() ) {
                return true;
            }

            return $this->is_admin_or_moderator();
        }

        private function is_admin_or_moderator() {
            return current_user_can( 'manage_options' ) || ( function_exists( 'bp_current_user_can' ) && bp_current_user_can( 'bp_moderate' ) );
        }

        private function get_settings_url() {
            return trailingslashit( bp_displayed_user_domain() . bp_get_settings_slug() . '/' . self::SETTINGS_ACTION );
        }

        private function get_user_hidden_tabs( $user_id ) {
            $tabs = get_user_meta( $user_id, self::META_HIDDEN_TABS, true );
            if ( ! is_array( $tabs ) ) {
                return [];
            }

            return array_values( array_unique( array_map( 'sanitize_key', $tabs ) ) );
        }

        private function get_available_controlled_tabs() {
            $tabs = [];
            $bp   = buddypress();

            if ( empty( $bp->members ) || empty( $bp->members->nav ) ) {
                return $tabs;
            }

            $nav_items = $bp->members->nav->get_primary( [], false );
            if ( empty( $nav_items ) ) {
                return $tabs;
            }

            foreach ( $nav_items as $nav_item ) {
                if ( empty( $nav_item->slug ) ) {
                    continue;
                }

                $slug = sanitize_key( $nav_item->slug );
                if ( $this->is_always_visible_tab( $slug ) ) {
                    continue;
                }

                if ( ! $this->is_public_tab( $nav_item ) ) {
                    continue;
                }

                $label = wp_strip_all_tags( html_entity_decode( (string) $nav_item->name ) );
                $label = trim( preg_replace( '/\s+/', ' ', $label ) );

                if ( '' === $label ) {
                    $label = ucfirst( str_replace( '-', ' ', $slug ) );
                }

                $tabs[ $slug ] = $label;
            }

            asort( $tabs );

            return $tabs;
        }

        private function is_public_tab( $nav_item ) {
            // Explicitly hidden tabs are not relevant for this setting.
            if ( isset( $nav_item->show_for_displayed_user ) && false === (bool) $nav_item->show_for_displayed_user ) {
                return false;
            }

            // Skip account/admin-only sections.
            $excluded = [ 'settings', 'messages', 'notifications', 'invitations', 'moderation' ];
            if ( in_array( sanitize_key( $nav_item->slug ), $excluded, true ) ) {
                return false;
            }

            return true;
        }

        private function is_always_visible_tab( $slug ) {
            return in_array( sanitize_key( $slug ), $this->always_visible_tabs, true );
        }

        private function tab_has_no_content( $slug, $user_id, $nav_item ) {
            $slug = sanitize_key( $slug );

            switch ( $slug ) {
                case 'photos':
                    $count = 0;
                    if ( function_exists( 'bp_get_total_media_count' ) ) {
                        $count = (int) bp_get_total_media_count();
                    } elseif ( function_exists( 'bp_media_get_total_media_count' ) ) {
                        $count = (int) bp_media_get_total_media_count();
                    }
                    return $count <= 0;

                case 'videos':
                    $count = 0;
                    if ( function_exists( 'bp_get_total_video_count' ) ) {
                        $count = (int) bp_get_total_video_count();
                    } elseif ( function_exists( 'bp_video_get_total_video_count' ) ) {
                        $count = (int) bp_video_get_total_video_count();
                    }
                    return $count <= 0;

                case 'blog':
                    if ( class_exists( 'BP_Member_Blog_Helpers' ) && method_exists( 'BP_Member_Blog_Helpers', 'get_user_post_counts' ) ) {
                        $counts = BP_Member_Blog_Helpers::get_user_post_counts( $user_id );
                        $published = is_object( $counts ) && isset( $counts->published ) ? (int) $counts->published : 0;
                        return $published <= 0;
                    }

                    return count_user_posts( $user_id, 'post', true ) <= 0;

                case 'friends':
                case 'connections':
                    if ( function_exists( 'friends_get_total_friend_count' ) ) {
                        return (int) friends_get_total_friend_count( $user_id ) <= 0;
                    }
                    break;

                case 'groups':
                    if ( function_exists( 'groups_total_groups_for_user' ) ) {
                        return (int) groups_total_groups_for_user( $user_id ) <= 0;
                    }
                    break;

                case 'listings':
                    if ( function_exists( 'geodir_get_option' ) && function_exists( 'geodir_buddypress_count_total' ) ) {
                        $types = (array) geodir_get_option( 'geodir_buddypress_tab_listing' );
                        $total = 0;
                        foreach ( $types as $post_type ) {
                            $total += (int) geodir_buddypress_count_total( $post_type, $user_id );
                        }
                        return $total <= 0;
                    }
                    break;

                case 'favorites':
                    if ( function_exists( 'geodir_get_option' ) && function_exists( 'geodir_buddypress_count_favorite' ) ) {
                        $types = (array) geodir_get_option( 'geodir_buddypress_tab_listing' );
                        $total = 0;
                        foreach ( $types as $post_type ) {
                            $total += (int) geodir_buddypress_count_favorite( $post_type, $user_id );
                        }
                        return $total <= 0;
                    }
                    break;

                case 'reviews':
                    if ( function_exists( 'geodir_get_option' ) && function_exists( 'geodir_buddypress_count_reviews' ) ) {
                        $types = (array) geodir_get_option( 'geodir_buddypress_tab_review' );
                        $total = 0;
                        foreach ( $types as $post_type ) {
                            $total += (int) geodir_buddypress_count_reviews( $post_type, $user_id );
                        }
                        return $total <= 0;
                    }
                    break;
            }

            $label_count = $this->extract_count_from_nav_label( (string) $nav_item->name );
            if ( null !== $label_count ) {
                return $label_count <= 0;
            }

            return false;
        }

        private function extract_count_from_nav_label( $label ) {
            if ( '' === $label ) {
                return null;
            }

            if ( preg_match( '/class=["\'][^"\']*count[^"\']*["\'][^>]*>([\d,]+)/i', $label, $matches ) ) {
                return (int) str_replace( ',', '', $matches[1] );
            }

            $text = wp_strip_all_tags( $label );
            if ( preg_match( '/\(([\d,]+)\)\s*$/', $text, $matches ) ) {
                return (int) str_replace( ',', '', $matches[1] );
            }

            return null;
        }
    }
}

add_action(
    'plugins_loaded',
    function () {
        if ( ! function_exists( 'buddypress' ) ) {
            return;
        }

        $controller = new Koopo_BuddyBoss_Profile_Tabs();
        $controller->init();
    },
    30
);
