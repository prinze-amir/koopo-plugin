<?php
/**
 * Elementor -> BuddyBoss Influencer Signup
 *
 * Creates a BuddyBoss/BuddyPress signup (wp_signups) on Elementor form submission,
 * sends activation email, then on activation sets role=influencer, auto logs in,
 * and redirects to a chosen page.
 */

if ( ! defined('ABSPATH') ) exit;

class Koopo_Elementor_Influencer_Registration {

    const FORM_NAME = 'Influencer Registration'; // <-- set this to your Elementor Form Name
    const TRANSIENT_PREFIX = 'koopo_influencer_activation_';
    const DEFAULT_ROLE = 'influencer';

    public function init() {
        // Elementor Pro forms hook
        add_action('elementor_pro/forms/new_record', [ $this, 'handle_form' ], 10, 2);

        // BuddyBoss/BuddyPress activation hook
        add_action('bp_core_activated_user', [ $this, 'on_activated' ], 20, 3);

        // Perform redirect after activation page is processed
        add_action('template_redirect', [ $this, 'maybe_redirect_after_activation' ], 0);
    }

    public function handle_form( $record, $handler ) {
        if ( ! function_exists('bp_is_active') || ! class_exists('BP_Signup') ) {
            return;
        }

        $form_name = $record->get_form_settings('form_name');
        if ( $form_name !== self::FORM_NAME ) {
            return;
        }

        $raw_fields = $record->get('fields');
        $fields = [];
        foreach ( $raw_fields as $id => $field ) {
            $fields[$id] = is_array($field) && isset($field['value']) ? $field['value'] : '';
        }

        // Map these to your Elementor field IDs
        $email     = sanitize_email( $fields['email'] ?? '' );
        $username  = sanitize_user( $fields['username'] ?? '' );
        $password  = (string) ( $fields['password'] ?? '' );
        $first     = sanitize_text_field( $fields['first_name'] ?? '' );
        $last      = sanitize_text_field( $fields['last_name'] ?? '' );

        // Where to send them after activation (must be absolute URL)
        $redirect_after_activation = home_url('/influencer-square/'); // <-- change

        // Basic validation
        if ( empty($email) || ! is_email($email) ) {
            $handler->add_error_message('Please enter a valid email.');
            return;
        }
        if ( empty($username) ) {
            // derive username from email if not provided
            $username = sanitize_user( current(explode('@', $email)) );
        }

        // If user/email already exists, block
        if ( email_exists($email) || username_exists($username) ) {
            $handler->add_error_message('An account already exists for that email or username.');
            return;
        }

        // Prevent duplicate pending signups
        $existing = BP_Signup::get([
            'user_email' => $email,
            'number'     => 1,
        ]);
        if ( ! empty($existing['signups']) ) {
            $handler->add_error_message('A pending signup already exists for this email. Check your inbox.');
            return;
        }

        $activation_key = wp_generate_password( 32, false );

        $meta = [
            'koopo_source'   => 'elementor_influencer',
            'desired_role'   => self::DEFAULT_ROLE,
            'first_name'     => $first,
            'last_name'      => $last,
            'redirect_to'    => $redirect_after_activation,
        ];

        // If you want BuddyBoss to set the password on activation, store it like your bridge does
        if ( ! empty($password) ) {
            $meta['password'] = $password;
        }

        // Create signup row (wp_signups)
        $result = BP_Signup::add( [
            'user_login'     => $username,
            'user_email'     => $email,
            'activation_key' => $activation_key,
            'meta'           => $meta,
        ] );

        if ( is_wp_error($result) ) {
            $handler->add_error_message('Could not create signup. Please try again.');
            return;
        }

        // Store activation context so we can auto-login + redirect even if wp_signups row is removed during activation
        set_transient(
            self::TRANSIENT_PREFIX . $activation_key,
            [
                'role'        => $meta['desired_role'],
                'redirect_to' => $redirect_after_activation,
            ],
            DAY_IN_SECONDS * 2
        );

        // Send BuddyBoss activation email using their template/link (/activate/{key}/)
        if ( function_exists('bp_core_signup_send_validation_email') ) {
            bp_core_signup_send_validation_email( 0, $email, $activation_key, $username );
        }

        // You can let Elementor handle redirect to “Confirm your email” page using its Redirect action.
        // Or force it here:
        // $handler->add_response_data('redirect_url', home_url('/confirm-your-email/'));
    }

    public function on_activated( $user_id, $key, $user ) {
        // Persist “just activated” key for this request so template_redirect can redirect cleanly
        if ( $user_id && $key ) {
            update_user_meta( $user_id, '_koopo_just_activated_key', sanitize_text_field($key) );
        }

        // Pull transient (set at registration time)
        $ctx = get_transient( self::TRANSIENT_PREFIX . $key );
        if ( empty($ctx) || empty($ctx['role']) ) {
            return;
        }

        // Set role to influencer
        $u = new WP_User( $user_id );
        $u->set_role( sanitize_text_field($ctx['role']) );

        // Auto login
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        // Save redirect for the redirect step
        if ( ! empty($ctx['redirect_to']) ) {
            update_user_meta( $user_id, '_koopo_post_activation_redirect', esc_url_raw($ctx['redirect_to']) );
        }

        delete_transient( self::TRANSIENT_PREFIX . $key );
    }

    public function maybe_redirect_after_activation() {
        if ( ! function_exists('bp_is_activation_page') || ! bp_is_activation_page() ) {
            return;
        }

        // If user is now logged in (we auto-logged them in), redirect them.
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            $redirect = get_user_meta( $user_id, '_koopo_post_activation_redirect', true );
            if ( $redirect ) {
                delete_user_meta( $user_id, '_koopo_post_activation_redirect' );
                delete_user_meta( $user_id, '_koopo_just_activated_key' );
                wp_safe_redirect( $redirect );
                exit;
            }
        }
    }
}

add_action('plugins_loaded', function(){
    (new Koopo_Elementor_Influencer_Registration())->init();
}, 25);