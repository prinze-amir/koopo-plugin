<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_SMS_Auth_Service {
    const SCHEMA_VERSION = '1.0.0';

    const OPTION_SCHEMA_VERSION = 'koopo_sms_auth_schema_version';
    const OPTION_ENABLED        = 'koopo_sms_auth_enabled';
    const OPTION_PROVIDER       = 'koopo_sms_auth_provider';
    const OPTION_TWILIO_SID     = 'koopo_sms_auth_twilio_sid';
    const OPTION_TWILIO_TOKEN   = 'koopo_sms_auth_twilio_token';
    const OPTION_TWILIO_FROM    = 'koopo_sms_auth_twilio_from';
    const OPTION_TWILIO_SERVICE = 'koopo_sms_auth_twilio_service_sid';
    const OPTION_COUNTRY_CODE   = 'koopo_sms_auth_default_country_code';
    const OPTION_CODE_TTL       = 'koopo_sms_auth_code_ttl_minutes';
    const OPTION_MAX_ATTEMPTS   = 'koopo_sms_auth_max_attempts';
    const OPTION_RATE_LIMIT     = 'koopo_sms_auth_rate_limit_per_hour';
    const OPTION_TOKEN_TTL      = 'koopo_sms_auth_token_ttl_days';
    const OPTION_AUTO_CREATE    = 'koopo_sms_auth_auto_create_users';
    const OPTION_REQUIRE_2FA    = 'koopo_sms_auth_require_wp_login_2fa';

    const META_PHONE            = '_koopo_sms_auth_phone';
    const META_PHONE_HASH       = '_koopo_sms_auth_phone_hash';
    const META_PHONE_VERIFIED   = '_koopo_sms_auth_phone_verified_at';

    const COOKIE_2FA_REQUEST    = 'koopo_sms_2fa_request';

    private $last_plain_code = '';

    public function hooks() {
        add_action( 'init', array( $this, 'maybe_install_tables' ), 5 );
        add_filter( 'determine_current_user', array( $this, 'authenticate_bearer_token' ), 20 );
        add_action( 'login_form', array( $this, 'render_wp_login_code_field' ) );
        add_filter( 'authenticate', array( $this, 'enforce_wp_login_2fa' ), 35, 3 );
    }

    public static function activate() {
        $service = new self();
        $service->install_tables();
    }

    public function maybe_install_tables() {
        if ( get_option( self::OPTION_SCHEMA_VERSION ) !== self::SCHEMA_VERSION ) {
            $this->install_tables();
        }
    }

    public function install_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $codes_table     = $this->codes_table();
        $tokens_table    = $this->tokens_table();

        $sql_codes = "CREATE TABLE {$codes_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            phone_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            purpose varchar(40) NOT NULL DEFAULT 'login',
            request_hash char(64) NOT NULL,
            code_hash char(64) NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
            ip_hash char(64) NOT NULL DEFAULT '',
            user_agent_hash char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_hash (request_hash),
            KEY phone_purpose (phone_hash, purpose, created_at),
            KEY user_purpose (user_id, purpose, created_at),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        $sql_tokens = "CREATE TABLE {$tokens_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            label varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            last_used_at datetime NULL DEFAULT NULL,
            revoked_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_active (user_id, revoked_at, expires_at)
        ) {$charset_collate};";

        dbDelta( $sql_codes );
        dbDelta( $sql_tokens );

        update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
    }

    public function is_enabled() {
        return (bool) get_option( self::OPTION_ENABLED, 0 );
    }

    public function default_country_code() {
        $code = trim( (string) get_option( self::OPTION_COUNTRY_CODE, '+1' ) );
        if ( '' === $code ) {
            $code = '+1';
        }

        return '+' . preg_replace( '/\D+/', '', $code );
    }

    public function normalize_phone( $phone ) {
        $phone = trim( (string) $phone );
        if ( '' === $phone ) {
            return new WP_Error( 'koopo_sms_phone_required', __( 'A phone number is required.', 'koopo' ), array( 'status' => 400 ) );
        }

        $phone = preg_replace( '/[^0-9+]/', '', $phone );
        if ( 0 !== strpos( $phone, '+' ) ) {
            $phone = $this->default_country_code() . ltrim( $phone, '0' );
        }

        if ( ! preg_match( '/^\+[1-9][0-9]{9,14}$/', $phone ) ) {
            return new WP_Error( 'koopo_sms_invalid_phone', __( 'Please enter a valid phone number.', 'koopo' ), array( 'status' => 400 ) );
        }

        return $phone;
    }

    public function phone_hash( $phone ) {
        return hash_hmac( 'sha256', (string) $phone, wp_salt( 'auth' ) );
    }

    public function mask_phone( $phone ) {
        $phone  = (string) $phone;
        $digits = preg_replace( '/\D+/', '', $phone );
        if ( strlen( $digits ) <= 4 ) {
            return $phone;
        }

        return '+' . str_repeat( '*', max( 0, strlen( $digits ) - 4 ) ) . substr( $digits, -4 );
    }

    public function user_phone_state( $user_id ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return array(
                'has_phone'   => false,
                'phone'       => '',
                'masked'      => '',
                'verified_at' => '',
            );
        }

        $phone       = (string) get_user_meta( $user_id, self::META_PHONE, true );
        $verified_at = (string) get_user_meta( $user_id, self::META_PHONE_VERIFIED, true );

        return array(
            'has_phone'   => '' !== $phone,
            'phone'       => $phone,
            'masked'      => '' !== $phone ? $this->mask_phone( $phone ) : '',
            'verified_at' => $verified_at,
        );
    }

    public function find_user_by_phone( $phone ) {
        $hash  = $this->phone_hash( $phone );
        $users = get_users(
            array(
                'number'     => 1,
                'fields'     => 'ID',
                'meta_key'   => self::META_PHONE_HASH,
                'meta_value' => $hash,
            )
        );

        return ! empty( $users ) ? absint( (int) $users[0] ) : 0;
    }

    public function start_login( $phone ) {
        $phone = $this->normalize_phone( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }

        $user_id     = $this->find_user_by_phone( $phone );
        $auto_create = (bool) get_option( self::OPTION_AUTO_CREATE, 0 );

        if ( ! $user_id && ! $auto_create ) {
            return $this->generic_start_response();
        }

        $sent = $this->create_and_send_code( $phone, 'login', $user_id );
        if ( is_wp_error( $sent ) ) {
            return $sent;
        }

        return $sent;
    }

    public function verify_login( $phone, $request_id, $code, $remember = false ) {
        $phone = $this->normalize_phone( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }

        $record = $this->verify_code_record( $phone, 'login', $request_id, $code );
        if ( is_wp_error( $record ) ) {
            return $record;
        }

        $user_id = absint( (int) $record['user_id'] );
        if ( ! $user_id ) {
            if ( ! get_option( self::OPTION_AUTO_CREATE, 0 ) ) {
                return new WP_Error( 'koopo_sms_user_missing', __( 'No account is connected to that phone number.', 'koopo' ), array( 'status' => 404 ) );
            }

            $user_id = $this->create_user_for_phone( $phone );
            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }
        }

        $this->attach_phone_to_user( $user_id, $phone );
        $this->consume_code_record( absint( (int) $record['id'] ) );

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, (bool) $remember, is_ssl() );

        $token = $this->issue_access_token( $user_id, 'sms-login' );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        return array(
            'authenticated' => true,
            'user'          => $this->user_payload( $user_id ),
            'access_token'  => $token['token'],
            'expires_at'    => $token['expires_at'],
        );
    }

    public function start_phone_link( $user_id, $phone ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return new WP_Error( 'koopo_sms_auth_required', __( 'Authentication is required.', 'koopo' ), array( 'status' => 401 ) );
        }

        $phone = $this->normalize_phone( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }

        $owner_id = $this->find_user_by_phone( $phone );
        if ( $owner_id && $owner_id !== $user_id ) {
            return new WP_Error( 'koopo_sms_phone_taken', __( 'That phone number is already connected to another account.', 'koopo' ), array( 'status' => 409 ) );
        }

        return $this->create_and_send_code( $phone, 'link_phone', $user_id );
    }

    public function verify_phone_link( $user_id, $phone, $request_id, $code ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return new WP_Error( 'koopo_sms_auth_required', __( 'Authentication is required.', 'koopo' ), array( 'status' => 401 ) );
        }

        $phone = $this->normalize_phone( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }

        $record = $this->verify_code_record( $phone, 'link_phone', $request_id, $code );
        if ( is_wp_error( $record ) ) {
            return $record;
        }

        if ( absint( (int) $record['user_id'] ) !== $user_id ) {
            return new WP_Error( 'koopo_sms_forbidden', __( 'This verification code is not valid for your account.', 'koopo' ), array( 'status' => 403 ) );
        }

        $this->attach_phone_to_user( $user_id, $phone );
        $this->consume_code_record( absint( (int) $record['id'] ) );

        return $this->user_phone_state( $user_id );
    }

    public function remove_phone( $user_id ) {
        $user_id = absint( (int) $user_id );
        if ( ! $user_id ) {
            return new WP_Error( 'koopo_sms_auth_required', __( 'Authentication is required.', 'koopo' ), array( 'status' => 401 ) );
        }

        delete_user_meta( $user_id, self::META_PHONE );
        delete_user_meta( $user_id, self::META_PHONE_HASH );
        delete_user_meta( $user_id, self::META_PHONE_VERIFIED );

        return $this->user_phone_state( $user_id );
    }

    public function create_and_send_code( $phone, $purpose, $user_id = 0 ) {
        if ( ! $this->is_enabled() ) {
            return new WP_Error( 'koopo_sms_disabled', __( 'SMS authentication is not enabled.', 'koopo' ), array( 'status' => 503 ) );
        }

        $purpose = $this->sanitize_purpose( $purpose );
        $phone   = $this->normalize_phone( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }

        $rate_limit = $this->check_rate_limit( $phone, $purpose );
        if ( is_wp_error( $rate_limit ) ) {
            return $rate_limit;
        }

        $code          = (string) random_int( 100000, 999999 );
        $request_token = bin2hex( random_bytes( 24 ) );
        $ttl_minutes   = max( 2, min( 30, absint( (int) get_option( self::OPTION_CODE_TTL, 10 ) ) ) );
        $max_attempts  = max( 1, min( 10, absint( (int) get_option( self::OPTION_MAX_ATTEMPTS, 5 ) ) ) );
        $now           = current_time( 'mysql', true );
        $expires_at    = gmdate( 'Y-m-d H:i:s', time() + ( $ttl_minutes * MINUTE_IN_SECONDS ) );

        global $wpdb;
        $inserted = $wpdb->insert(
            $this->codes_table(),
            array(
                'phone_hash'      => $this->phone_hash( $phone ),
                'user_id'         => absint( (int) $user_id ),
                'purpose'         => $purpose,
                'request_hash'    => $this->request_hash( $request_token ),
                'code_hash'       => $this->code_hash( $request_token, $code ),
                'attempts'        => 0,
                'max_attempts'    => $max_attempts,
                'ip_hash'         => $this->request_ip_hash(),
                'user_agent_hash' => $this->request_user_agent_hash(),
                'created_at'      => $now,
                'expires_at'      => $expires_at,
                'consumed_at'     => null,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            return new WP_Error( 'koopo_sms_code_failed', __( 'Could not create a verification code. Please try again.', 'koopo' ), array( 'status' => 500 ) );
        }

        $message = sprintf(
            /* translators: %s is the SMS verification code. */
            __( 'Your Koopo verification code is %s. It expires soon.', 'koopo' ),
            $code
        );

        $sent = $this->send_sms( $phone, $message, $code );
        if ( is_wp_error( $sent ) ) {
            return $sent;
        }

        $response = array(
            'sent'       => true,
            'request_id' => $request_token,
            'expires_at' => $expires_at,
            'masked'     => $this->mask_phone( $phone ),
            'message'    => __( 'Verification code sent.', 'koopo' ),
        );

        if ( 'log' === $this->provider() && current_user_can( 'manage_options' ) ) {
            $response['debug_code'] = $code;
        }

        return $response;
    }

    public function verify_code_record( $phone, $purpose, $request_id, $code ) {
        global $wpdb;

        $phone      = $this->normalize_phone( $phone );
        $purpose    = $this->sanitize_purpose( $purpose );
        $request_id = trim( (string) $request_id );
        $code       = preg_replace( '/\D+/', '', (string) $code );

        if ( is_wp_error( $phone ) ) {
            return $phone;
        }

        if ( '' === $request_id || ! preg_match( '/^[a-f0-9]{48}$/', $request_id ) ) {
            return new WP_Error( 'koopo_sms_invalid_request', __( 'Verification session is invalid or expired.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^[0-9]{6}$/', $code ) ) {
            return new WP_Error( 'koopo_sms_invalid_code', __( 'Please enter the 6-digit verification code.', 'koopo' ), array( 'status' => 400 ) );
        }

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->codes_table()} WHERE request_hash = %s AND phone_hash = %s AND purpose = %s LIMIT 1",
                $this->request_hash( $request_id ),
                $this->phone_hash( $phone ),
                $purpose
            ),
            ARRAY_A
        );

        if ( ! $record ) {
            return new WP_Error( 'koopo_sms_invalid_request', __( 'Verification session is invalid or expired.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( ! empty( $record['consumed_at'] ) ) {
            return new WP_Error( 'koopo_sms_code_used', __( 'That verification code was already used.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( strtotime( (string) $record['expires_at'] ) < time() ) {
            return new WP_Error( 'koopo_sms_code_expired', __( 'That verification code expired. Request a new one.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( (int) $record['attempts'] >= (int) $record['max_attempts'] ) {
            return new WP_Error( 'koopo_sms_too_many_attempts', __( 'Too many attempts. Request a new code.', 'koopo' ), array( 'status' => 429 ) );
        }

        if ( ! hash_equals( (string) $record['code_hash'], $this->code_hash( $request_id, $code ) ) ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->codes_table()} SET attempts = attempts + 1 WHERE id = %d",
                    absint( (int) $record['id'] )
                )
            );

            return new WP_Error( 'koopo_sms_invalid_code', __( 'That code is not correct.', 'koopo' ), array( 'status' => 400 ) );
        }

        return $record;
    }

    public function consume_code_record( $record_id ) {
        global $wpdb;

        return (bool) $wpdb->update(
            $this->codes_table(),
            array( 'consumed_at' => current_time( 'mysql', true ) ),
            array( 'id' => absint( (int) $record_id ) ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public function issue_access_token( $user_id, $label = 'sms-auth' ) {
        global $wpdb;

        $user_id = absint( (int) $user_id );
        if ( ! $user_id || ! get_userdata( $user_id ) ) {
            return new WP_Error( 'koopo_sms_invalid_user', __( 'Authenticated user could not be resolved.', 'koopo' ), array( 'status' => 401 ) );
        }

        $token       = bin2hex( random_bytes( 32 ) );
        $days        = max( 1, min( 365, absint( (int) get_option( self::OPTION_TOKEN_TTL, 30 ) ) ) );
        $created_at  = current_time( 'mysql', true );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
        $token_hash  = $this->token_hash( $token );

        $inserted = $wpdb->insert(
            $this->tokens_table(),
            array(
                'user_id'      => $user_id,
                'token_hash'   => $token_hash,
                'label'        => sanitize_text_field( (string) $label ),
                'created_at'   => $created_at,
                'expires_at'   => $expires_at,
                'last_used_at' => null,
                'revoked_at'   => null,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            return new WP_Error( 'koopo_sms_token_failed', __( 'Could not create an access token.', 'koopo' ), array( 'status' => 500 ) );
        }

        return array(
            'token'      => $token,
            'expires_at' => $expires_at,
        );
    }

    public function revoke_current_token() {
        $token = $this->bearer_token();
        if ( '' === $token ) {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->update(
            $this->tokens_table(),
            array( 'revoked_at' => current_time( 'mysql', true ) ),
            array( 'token_hash' => $this->token_hash( $token ) ),
            array( '%s' ),
            array( '%s' )
        );
    }

    public function authenticate_bearer_token( $user_id ) {
        if ( $user_id ) {
            return $user_id;
        }

        $token = $this->bearer_token();
        if ( '' === $token ) {
            return $user_id;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, expires_at, revoked_at FROM {$this->tokens_table()} WHERE token_hash = %s LIMIT 1",
                $this->token_hash( $token )
            ),
            ARRAY_A
        );

        if ( ! $row || ! empty( $row['revoked_at'] ) || strtotime( (string) $row['expires_at'] ) < time() ) {
            return $user_id;
        }

        $wpdb->update(
            $this->tokens_table(),
            array( 'last_used_at' => current_time( 'mysql', true ) ),
            array( 'id' => absint( (int) $row['id'] ) ),
            array( '%s' ),
            array( '%d' )
        );

        return absint( (int) $row['user_id'] );
    }

    public function render_wp_login_code_field() {
        if ( ! get_option( self::OPTION_REQUIRE_2FA, 0 ) ) {
            return;
        }
        ?>
        <p>
            <label for="koopo_sms_code"><?php esc_html_e( 'SMS verification code', 'koopo' ); ?></label>
            <input type="text" name="koopo_sms_code" id="koopo_sms_code" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" />
            <span class="description"><?php esc_html_e( 'If your account uses SMS 2FA, enter the code sent after your first login attempt.', 'koopo' ); ?></span>
        </p>
        <?php
    }

    public function enforce_wp_login_2fa( $user, $username, $password ) {
        unset( $password );

        if ( ! $this->is_enabled() || ! get_option( self::OPTION_REQUIRE_2FA, 0 ) || is_wp_error( $user ) || ! $user instanceof WP_User ) {
            return $user;
        }

        if ( user_can( $user, 'manage_options' ) ) {
            return $user;
        }

        $state = $this->user_phone_state( $user->ID );
        if ( empty( $state['has_phone'] ) || empty( $state['verified_at'] ) ) {
            return $user;
        }

        $request_id = isset( $_COOKIE[ self::COOKIE_2FA_REQUEST ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_2FA_REQUEST ] ) ) : '';
        $code       = isset( $_POST['koopo_sms_code'] ) ? sanitize_text_field( wp_unslash( $_POST['koopo_sms_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( '' !== $request_id && '' !== $code ) {
            $record = $this->verify_code_record( $state['phone'], 'wp_login_2fa', $request_id, $code );
            if ( ! is_wp_error( $record ) && absint( (int) $record['user_id'] ) === absint( (int) $user->ID ) ) {
                $this->consume_code_record( absint( (int) $record['id'] ) );
                $this->clear_2fa_cookie();
                return $user;
            }

            return new WP_Error( 'koopo_sms_2fa_failed', __( 'The SMS verification code is not valid.', 'koopo' ) );
        }

        $started = $this->create_and_send_code( $state['phone'], 'wp_login_2fa', $user->ID );
        if ( is_wp_error( $started ) ) {
            return new WP_Error( 'koopo_sms_2fa_unavailable', __( 'SMS verification is required, but a code could not be sent. Please contact support.', 'koopo' ) );
        }

        $this->set_2fa_cookie( (string) $started['request_id'], (string) $started['expires_at'] );

        return new WP_Error(
            'koopo_sms_2fa_required',
            sprintf(
                /* translators: %s is a masked phone number. */
                __( 'We sent a verification code to %s. Enter the code and submit your username and password again.', 'koopo' ),
                esc_html( $state['masked'] )
            )
        );
    }

    public function user_payload( $user_id ) {
        $user = get_userdata( absint( (int) $user_id ) );
        if ( ! $user ) {
            return null;
        }

        $phone = $this->user_phone_state( $user->ID );

        return array(
            'id'           => (int) $user->ID,
            'username'     => (string) $user->user_login,
            'display_name' => function_exists( 'koopo_get_user_display_name' ) ? koopo_get_user_display_name( $user->ID, $user->display_name ) : (string) $user->display_name,
            'email'        => (string) $user->user_email,
            'roles'        => array_values( (array) $user->roles ),
            'phone'        => array(
                'has_phone'   => (bool) $phone['has_phone'],
                'masked'      => (string) $phone['masked'],
                'verified_at' => (string) $phone['verified_at'],
            ),
        );
    }

    public function provider() {
        $provider = sanitize_key( (string) get_option( self::OPTION_PROVIDER, 'twilio' ) );
        return in_array( $provider, array( 'twilio', 'log' ), true ) ? $provider : 'twilio';
    }

    public function send_sms( $phone, $message, $plain_code = '' ) {
        $this->last_plain_code = (string) $plain_code;

        if ( 'log' === $this->provider() ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( 'Koopo SMS auth code for %s: %s', $this->mask_phone( $phone ), $plain_code ) );
            }
            return true;
        }

        return $this->send_twilio_sms( $phone, $message );
    }

    public function last_plain_code() {
        return $this->last_plain_code;
    }

    private function send_twilio_sms( $phone, $message ) {
        $sid             = trim( (string) get_option( self::OPTION_TWILIO_SID, '' ) );
        $token           = trim( (string) get_option( self::OPTION_TWILIO_TOKEN, '' ) );
        $from            = trim( (string) get_option( self::OPTION_TWILIO_FROM, '' ) );
        $messaging_sid   = trim( (string) get_option( self::OPTION_TWILIO_SERVICE, '' ) );

        if ( '' === $sid || '' === $token || ( '' === $from && '' === $messaging_sid ) ) {
            return new WP_Error( 'koopo_sms_provider_missing', __( 'SMS provider settings are incomplete.', 'koopo' ), array( 'status' => 503 ) );
        }

        $body = array(
            'To'   => $phone,
            'Body' => $message,
        );

        if ( '' !== $messaging_sid ) {
            $body['MessagingServiceSid'] = $messaging_sid;
        } else {
            $body['From'] = $from;
        }

        $response = wp_remote_post(
            sprintf( 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode( $sid ) ),
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ),
                ),
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'koopo_sms_send_failed', __( 'Could not send the SMS code. Please try again.', 'koopo' ), array( 'status' => 503 ) );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error( 'koopo_sms_send_failed', __( 'SMS provider rejected the message. Please check provider settings.', 'koopo' ), array( 'status' => 503 ) );
        }

        return true;
    }

    private function generic_start_response() {
        return array(
            'sent'       => true,
            'request_id' => '',
            'expires_at' => '',
            'masked'     => '',
            'message'    => __( 'If that phone number is connected to an account, a verification code has been sent.', 'koopo' ),
        );
    }

    private function create_user_for_phone( $phone ) {
        $digits = preg_replace( '/\D+/', '', $phone );
        $base   = 'phone_' . substr( $digits, -10 );
        $login  = $base;
        $i      = 1;

        while ( username_exists( $login ) ) {
            $login = $base . '_' . $i;
            $i++;
        }

        $email = $login . '@sms.local';
        $user_id = wp_insert_user(
            array(
                'user_login'   => sanitize_user( $login, true ),
                'user_pass'    => wp_generate_password( 32, true, true ),
                'user_email'   => $email,
                'display_name' => $this->mask_phone( $phone ),
                'role'         => get_option( 'default_role', 'subscriber' ),
            )
        );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        return absint( (int) $user_id );
    }

    private function attach_phone_to_user( $user_id, $phone ) {
        update_user_meta( $user_id, self::META_PHONE, $phone );
        update_user_meta( $user_id, self::META_PHONE_HASH, $this->phone_hash( $phone ) );
        update_user_meta( $user_id, self::META_PHONE_VERIFIED, current_time( 'mysql', true ) );
    }

    private function check_rate_limit( $phone, $purpose ) {
        global $wpdb;

        $limit = max( 1, min( 20, absint( (int) get_option( self::OPTION_RATE_LIMIT, 5 ) ) ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->codes_table()} WHERE phone_hash = %s AND purpose = %s AND created_at >= %s",
                $this->phone_hash( $phone ),
                $this->sanitize_purpose( $purpose ),
                $since
            )
        );

        if ( $count >= $limit ) {
            return new WP_Error( 'koopo_sms_rate_limited', __( 'Too many SMS requests. Please wait and try again.', 'koopo' ), array( 'status' => 429 ) );
        }

        $ip_key  = 'koopo_sms_auth_ip_' . md5( $this->request_ip_hash() . '|' . $purpose );
        $ip_hits = (int) get_transient( $ip_key );
        if ( $ip_hits >= $limit * 3 ) {
            return new WP_Error( 'koopo_sms_rate_limited', __( 'Too many SMS requests. Please wait and try again.', 'koopo' ), array( 'status' => 429 ) );
        }

        set_transient( $ip_key, $ip_hits + 1, HOUR_IN_SECONDS + MINUTE_IN_SECONDS );

        return true;
    }

    private function sanitize_purpose( $purpose ) {
        $purpose = sanitize_key( (string) $purpose );
        $allowed = array( 'login', 'link_phone', 'wp_login_2fa' );
        return in_array( $purpose, $allowed, true ) ? $purpose : 'login';
    }

    private function request_hash( $request_token ) {
        return hash_hmac( 'sha256', (string) $request_token, wp_salt( 'nonce' ) );
    }

    private function code_hash( $request_token, $code ) {
        return hash_hmac( 'sha256', (string) $request_token . '|' . (string) $code, wp_salt( 'secure_auth' ) );
    }

    private function token_hash( $token ) {
        return hash_hmac( 'sha256', (string) $token, wp_salt( 'logged_in' ) );
    }

    private function bearer_token() {
        $header = '';
        if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
        } elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
        }

        if ( preg_match( '/Bearer\s+([A-Za-z0-9]+)/', $header, $matches ) ) {
            return (string) $matches[1];
        }

        return '';
    }

    private function set_2fa_cookie( $request_id, $expires_at ) {
        $expires = strtotime( $expires_at );
        if ( ! $expires ) {
            $expires = time() + 10 * MINUTE_IN_SECONDS;
        }

        setcookie(
            self::COOKIE_2FA_REQUEST,
            $request_id,
            array(
                'expires'  => $expires,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
        $_COOKIE[ self::COOKIE_2FA_REQUEST ] = $request_id;
    }

    private function clear_2fa_cookie() {
        setcookie(
            self::COOKIE_2FA_REQUEST,
            '',
            array(
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
        unset( $_COOKIE[ self::COOKIE_2FA_REQUEST ] );
    }

    private function request_ip_hash() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
    }

    private function request_user_agent_hash() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        return hash_hmac( 'sha256', $ua, wp_salt( 'auth' ) );
    }

    public function codes_table() {
        global $wpdb;
        return $wpdb->prefix . 'koopo_sms_auth_codes';
    }

    public function tokens_table() {
        global $wpdb;
        return $wpdb->prefix . 'koopo_sms_auth_tokens';
    }
}
