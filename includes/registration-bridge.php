<?php
/**
 * Koopo BuddyBoss Registration Bridge
 *
 * - Routes WooCommerce + Dokan registrations through BuddyBoss/BuddyPress signup flow
 *   so they show as "Pending" and require email activation.
 * - Uses existing BuddyBoss activation emails (/activate/{key}/).
 * - Optionally auto-deletes old pending signups after N days.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Koopo_BuddyBoss_Registration_Bridge' ) ) {

    class Koopo_BuddyBoss_Registration_Bridge {

        const META_ORIGINAL_ROLES = '_koopo_original_roles';

        /**
         * Initialize all hooks once BuddyBoss / BuddyPress and Woo/Dokan are loaded.
         */
        public function init() {

            if ( ! $this->is_buddyboss_available() ) {
                return;
            }

            // Intercept new front-end users (Woo + Dokan) and route through BuddyBoss signup.
            add_action( 'user_register', [ $this, 'handle_new_user' ], 20, 1 );

            // Disable WooCommerce auto-login after registration.
            if ( function_exists( 'wc_create_new_customer' ) ) {
                add_filter( 'woocommerce_registration_auth_new_customer', '__return_false' );
            }

            // After BuddyBoss activates the user, restore original roles (customer, vendor, etc.).
            add_action( 'bp_core_activated_user', [ $this, 'restore_original_roles_after_activation' ], 20, 3 );

            // Cleanup: delete stale pending signups on a daily cron.
            add_action( 'koopo_bb_cleanup_pending_signups', [ $this, 'cleanup_old_pending_signups' ] );

            // Ensure our daily cleanup event is scheduled.
            if ( ! wp_next_scheduled( 'koopo_bb_cleanup_pending_signups' ) ) {
                wp_schedule_event(
                    time() + HOUR_IN_SECONDS,
                    'daily',
                    'koopo_bb_cleanup_pending_signups'
                );
            }
        }

        /**
         * Check if BuddyBoss/BuddyPress signup stack is available.
         *
         * @return bool
         */
        protected function is_buddyboss_available() {

            return class_exists( 'BP_Signup' )
                && function_exists( 'bp_core_signup_send_validation_email' )
                && function_exists( 'bp_get_activation_page' );
        }

        /**
         * user_register handler: bridge WooCommerce / Dokan users into BuddyBoss signup.
         *
         * @param int $user_id
         */
        public function handle_new_user( $user_id ) {

            // Do not touch admin-created users.
            if ( is_admin() && ! wp_doing_ajax() ) {
                return;
            }

            // Only WooCommerce / Dokan registrations (leave native BuddyBoss alone).
            if ( ! $this->is_woocommerce_registration() && ! $this->is_dokan_registration() ) {
                return;
            }

            if ( ! $this->is_buddyboss_available() ) {
                return;
            }

            $this->bridge_user_to_buddyboss_signup( $user_id );
        }

        /**
         * Detect WooCommerce registration forms.
         *
         * @return bool
         */
        protected function is_woocommerce_registration() {

            if ( ! function_exists( 'wc_create_new_customer' ) ) {
                return false;
            }

            // Woo My Account / Checkout registration.
            if ( isset( $_POST['woocommerce-register-nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return true;
            }

            return false;
        }

        /**
         * Detect Dokan vendor/customer registration forms.
         *
         * @return bool
         */
        protected function is_dokan_registration() {

            // Common Dokan fields.
            if ( isset( $_POST['dokan_migration'], $_POST['role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return true;
            }

            if ( isset( $_POST['dokan_register'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return true;
            }

            return false;
        }

        /**
         * Convert a Woo/Dokan-created user into a BuddyBoss-style pending signup.
         *
         * - Saves original roles in usermeta.
         * - Sets user_status = 2 (not activated).
         * - Removes capabilities so they don't count as active users.
         * - Creates wp_signups entry via BP_Signup::add().
         * - Sends BuddyBoss activation email.
         *
         * @param int $user_id
         */
        protected function bridge_user_to_buddyboss_signup( $user_id ) {
            global $wpdb;

            $user = get_userdata( $user_id );

            if ( ! $user || ! $user->user_email ) {
                return;
            }

            // Already pending? bail.
            if ( isset( $user->user_status ) && (int) $user->user_status === 2 ) {
                return;
            }

            // Avoid duplicate signup rows.
            $existing_signup = BP_Signup::get(
                [
                    'user_login' => sanitize_user( $user->user_login ),
                ]
            );

            if ( ! empty( $existing_signup['signups'] ) && (int) $existing_signup['total'] > 0 ) {
                return;
            }

            // Save original roles so we can restore after activation.
            $original_roles = is_array( $user->roles ) ? $user->roles : [];
            if ( ! empty( $original_roles ) ) {
                update_user_meta( $user_id, self::META_ORIGINAL_ROLES, $original_roles );
            }

            // Mark user as "not activated" in wp_users.
            $wpdb->update(
                $wpdb->users,
                [ 'user_status' => 2 ],
                [ 'ID' => $user_id ],
                [ '%d' ],
                [ '%d' ]
            );

            // Remove capabilities & level so they don't count as active users.
            delete_user_option( $user_id, 'capabilities' );
            delete_user_option( $user_id, 'user_level' );

            // Activation key and meta.
            $activation_key = wp_generate_password( 32, false );

            // Try to grab plain password from form (optional).
            $password = '';
            if ( isset( $_POST['password'] ) && is_string( $_POST['password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $password = wp_unslash( $_POST['password'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }

            $signup_meta = [
                'koopo_source'         => $this->is_dokan_registration() ? 'dokan' : 'woocommerce',
                'koopo_original_roles' => $original_roles,
            ];

            if ( ! empty( $password ) ) {
                $signup_meta['password'] = $password;
            }

            // Create BuddyBoss/BuddyPress signup record in wp_signups.
            BP_Signup::add(
                [
                    'domain'         => '',
                    'path'           => '',
                    'title'          => '',
                    'user_login'     => $user->user_login,
                    'user_email'     => $user->user_email,
                    'registered'     => current_time( 'mysql', true ),
                    'activation_key' => $activation_key,
                    'meta'           => $signup_meta,
                ]
            );

            // Store activation key in user meta (BuddyBoss does this as well).
            if ( function_exists( 'bp_update_user_meta' ) ) {
                bp_update_user_meta( $user_id, 'activation_key', $activation_key );
            } else {
                update_user_meta( $user_id, 'activation_key', $activation_key );
            }

            // Send standard BuddyBoss activation email using existing templates.
            bp_core_signup_send_validation_email(
                $user_id,
                $user->user_email,
                $activation_key,
                $user->user_login
            );

            // If Woo/Dokan logged them in before this ran, force logout.
            if ( get_current_user_id() === $user_id ) {
                wp_logout();
            }
        }

        /**
         * After BuddyBoss activates the account (user clicks /activate/{key}/),
         * restore the original roles we saved at signup.
         *
         * @param int   $user_id
         * @param mixed $key
         * @param mixed $user_data
         */
        public function restore_original_roles_after_activation( $user_id, $key = null, $user_data = null ) {

            $stored_roles = get_user_meta( $user_id, self::META_ORIGINAL_ROLES, true );

            if ( empty( $stored_roles ) || ! is_array( $stored_roles ) ) {
                return;
            }

            $user = new WP_User( $user_id );

            // Remove whatever default role BuddyBoss gave.
            foreach ( (array) $user->roles as $role ) {
                $user->remove_role( $role );
            }

            // Reapply original roles (customer, vendor, etc.).
            foreach ( $stored_roles as $role ) {
                $user->add_role( $role );
            }

            delete_user_meta( $user_id, self::META_ORIGINAL_ROLES );
        }

        /**
         * Delete pending signups that never activated after X days.
         *
         * - Uses BuddyBoss/BuddyPress wp_signups table.
         * - Also deletes the associated WP user accounts.
         *
         * You can override the age threshold via:
         *   add_filter( 'koopo_bb_pending_max_age_days', fn() => 3 );
         */
        public function cleanup_old_pending_signups() {

            if ( ! $this->is_buddyboss_available() ) {
                return;
            }

            if ( ! function_exists( 'buddypress' ) ) {
                return;
            }

            $bp = buddypress();

            if ( empty( $bp->members ) || empty( $bp->members->table_name_signups ) ) {
                return;
            }

            // Days before we auto-delete pending signups.
            $max_age_days = (int) apply_filters( 'koopo_bb_pending_max_age_days', 7 );

            // Disable cleanup if <= 0.
            if ( $max_age_days <= 0 ) {
                return;
            }

            global $wpdb;

            $signups_table = $bp->members->table_name_signups;

            // registered column is GMT datetime.
            $cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );

            $signup_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT signup_id
                     FROM {$signups_table}
                     WHERE active = 0
                     AND registered < %s",
                    $cutoff_gmt
                )
            );

            if ( empty( $signup_ids ) ) {
                return;
            }

            // BP_Signup::delete() also handles deleting associated WP users.
            BP_Signup::delete( (array) $signup_ids );
        }
    }
}

/**
 * Bootstrap the bridge using the existing Koopo plugin.
 * We wait for plugins_loaded so BuddyBoss + Woo/Dokan are available.
 */
add_action(
    'plugins_loaded',
    function () {
        if ( class_exists( 'Koopo_BuddyBoss_Registration_Bridge' ) ) {
            $bridge = new Koopo_BuddyBoss_Registration_Bridge();
            $bridge->init();
        }
    },
    20
);