<?php
defined( 'ABSPATH' ) || exit;

final class Koopo_Login {
    const VERSION = '1.0.4';
    const PAGE_OPTION = 'koopo_login_page_id';
    const GUEST_COOKIE = 'koopo_login_guest';
    private static $instance;
    private $error;
    private $username = '';

    public static function boot() {
        if ( ! self::$instance ) {
            self::$instance = new self();
            add_action( 'admin_menu', array( self::$instance, 'admin_menu' ) );
            add_action( 'admin_init', array( self::$instance, 'admin_settings' ) );
            add_action( 'wp_enqueue_scripts', array( self::$instance, 'assets' ) );
            add_action( 'template_redirect', array( self::$instance, 'handle_page' ), -20 );
            add_filter( 'login_url', array( self::$instance, 'login_url' ), 20, 3 );
            add_filter( 'bp_enable_private_network_public_content', array( self::$instance, 'public_content' ) );
            add_action( 'elementor/widgets/register', array( self::$instance, 'register_widget' ) );
            add_filter( 'nonce_user_logged_out', array( self::$instance, 'guest_nonce_user' ), 10, 2 );
            add_action( 'wp_ajax_nopriv_koopo_login_token', array( self::$instance, 'refresh_token' ) );
            add_action( 'wp_ajax_koopo_login_token', array( self::$instance, 'refresh_token' ) );
            add_shortcode( 'koopo_login_form', array( self::$instance, 'form' ) );
        }
        return self::$instance;
    }

    public function admin_menu() {
        add_options_page( 'Koopo Login', 'Koopo Login', 'manage_options', 'koopo-login', array( $this, 'settings_page' ) );
    }

    public function admin_settings() {
        register_setting( 'koopo_login', self::PAGE_OPTION, array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ) );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap"><h1>Koopo Login</h1><form method="post" action="options.php">';
        settings_fields( 'koopo_login' );
        echo '<table class="form-table"><tr><th scope="row"><label for="koopo-login-page">Login page</label></th><td>';
        wp_dropdown_pages( array( 'name' => self::PAGE_OPTION, 'id' => 'koopo-login-page', 'selected' => absint( get_option( self::PAGE_OPTION ) ), 'show_option_none' => 'Use the WordPress login page', 'option_none_value' => 0, 'post_status' => 'publish' ) );
        echo '<p class="description">Choose the published Elementor page containing the Koopo Login widget. Existing registration, password recovery and administrator login remain available.</p></td></tr></table>';
        submit_button();
        echo '</form></div>';
    }

    public static function page_url() {
        global $wp_rewrite;
        // BuddyBoss reads privacy settings during plugins_loaded in wp-admin,
        // before WordPress creates WP_Rewrite. Leave early filters unchanged.
        if ( ! $wp_rewrite instanceof WP_Rewrite ) { return ''; }
        $id = absint( get_option( self::PAGE_OPTION ) );
        return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '';
    }

    public function register_widget( $manager ) {
        require_once __DIR__ . '/class-koopo-login-widget.php';
        $manager->register( new Koopo_Login_Widget() );
    }

    public function assets() {
        $base = plugin_dir_url( __FILE__ );
        wp_register_style( 'koopo-login', $base . 'assets/login.css', array(), self::VERSION );
        wp_register_script( 'koopo-login', $base . 'assets/login.js', array( 'jquery' ), self::VERSION, true );
        if ( absint( get_option( self::PAGE_OPTION ) ) && is_page( absint( get_option( self::PAGE_OPTION ) ) ) ) {
            wp_enqueue_style( 'koopo-login' );
            wp_enqueue_script( 'koopo-login' );
        }
    }

    public function public_content( $content ) {
        $url = self::page_url();
        return $url ? trim( (string) $content ) . "\n" . $url : $content;
    }

    public function login_url( $url, $redirect, $reauth ) {
        $page = self::page_url();
        // Keep administrator/interim login and the established Ads form action intact.
        $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
        if ( ! $page || $reauth || is_admin() || did_action( 'login_init' ) || '/koopo-ads-login' === untrailingslashit( (string) $path ) ) {
            return $url;
        }
        return $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : $page;
    }

    public static function safe_destination( $url, $fallback = '' ) {
        $fallback = $fallback ?: home_url( '/' );
        $safe = wp_validate_redirect( $url, $fallback );
        if ( untrailingslashit( (string) wp_parse_url( $safe, PHP_URL_PATH ) ) === untrailingslashit( (string) wp_parse_url( self::page_url(), PHP_URL_PATH ) ) ) {
            return $fallback;
        }
        return $safe;
    }

    private function requested_destination() {
        $value = isset( $_POST['redirect_to'] ) ? $_POST['redirect_to'] : ( isset( $_GET['redirect_to'] ) ? $_GET['redirect_to'] : '' );
        return is_string( $value ) && '' !== $value ? self::safe_destination( wp_unslash( $value ) ) : '';
    }

    private function buckets( $username ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return array(
            'koopo_login_identity_' . hash_hmac( 'sha256', strtolower( trim( $username ) ), wp_salt( 'auth' ) ) => 10,
            'koopo_login_ip_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) => 30,
        );
    }

    public function guest_nonce_user( $user_id, $action ) {
        if ( 'koopo_login' !== $action ) { return $user_id; }
        $cookie = isset( $_COOKIE[ self::GUEST_COOKIE ] ) && is_string( $_COOKIE[ self::GUEST_COOKIE ] ) ? $_COOKIE[ self::GUEST_COOKIE ] : '';
        return preg_match( '/^[a-zA-Z0-9]{32}$/', $cookie ) ? hexdec( substr( hash_hmac( 'sha256', $cookie, wp_salt( 'nonce' ) ), 0, 12 ) ) : $user_id;
    }

    private function guest_cookie( $value, $expires = 0 ) {
        setcookie( self::GUEST_COOKIE, $value, array( 'expires' => $expires, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
        $_COOKIE[ self::GUEST_COOKIE ] = $value;
    }

    private function ensure_guest_cookie() {
        if ( ! is_user_logged_in() && ( ! isset( $_COOKIE[ self::GUEST_COOKIE ] ) || ! is_string( $_COOKIE[ self::GUEST_COOKIE ] ) || ! preg_match( '/^[a-zA-Z0-9]{32}$/', $_COOKIE[ self::GUEST_COOKIE ] ) ) ) {
            $this->guest_cookie( wp_generate_password( 32, false, false ) );
        }
    }

    // Fetch immediately before submitting: cached/open tabs may outlive their guest cookie.
    // The response stays same-origin and the nonce remains bound to an HttpOnly cookie.
    public function refresh_token() {
        nocache_headers();
        $this->ensure_guest_cookie();
        wp_send_json_success( array( 'nonce' => wp_create_nonce( 'koopo_login' ) ) );
    }

    public function handle_page() {
        if ( ! self::page_url() || ! is_page( absint( get_option( self::PAGE_OPTION ) ) ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        do_action( 'litespeed_control_set_nocache', 'Koopo authentication page' );
        $posting = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && ! empty( $_POST['koopo_login_submit'] );
        if ( ! $posting ) {
            $this->ensure_guest_cookie();
            return;
        }
        // Bind guest nonces to this browser, rather than WordPress's shared anonymous user ID.
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
        $site = wp_parse_url( home_url( '/' ) );
        $source = wp_parse_url( $origin );
        $same_origin = ! $origin || ( is_array( $source ) && strtolower( $source['host'] ?? '' ) === strtolower( $site['host'] ?? '' ) && ( $source['scheme'] ?? '' ) === ( $site['scheme'] ?? '' ) && ( $source['port'] ?? null ) === ( $site['port'] ?? null ) );
        if ( ! $same_origin || ( ! is_user_logged_in() && ( ! isset( $_COOKIE[ self::GUEST_COOKIE ] ) || ! is_string( $_COOKIE[ self::GUEST_COOKIE ] ) || ! preg_match( '/^[a-zA-Z0-9]{32}$/', $_COOKIE[ self::GUEST_COOKIE ] ) ) ) ) {
            $this->ensure_guest_cookie();
            $this->error = new WP_Error( 'expired_form', __( 'This sign-in form expired. Please reload the page and try again.', 'koopo' ) );
            status_header( 403 );
            return;
        }
        $nonce = isset( $_POST['_koopo_login_nonce'] ) && is_string( $_POST['_koopo_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_koopo_login_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'koopo_login' ) ) {
            $this->ensure_guest_cookie();
            $this->error = new WP_Error( 'expired_form', __( 'This sign-in form expired. Please reload the page and try again.', 'koopo' ) );
            status_header( 403 );
            return;
        }
        $this->username = isset( $_POST['log'] ) && is_string( $_POST['log'] ) ? trim( wp_unslash( $_POST['log'] ) ) : '';
        $password = isset( $_POST['pwd'] ) && is_string( $_POST['pwd'] ) ? wp_unslash( $_POST['pwd'] ) : '';
        if ( '' === $this->username || '' === $password ) {
            $this->error = new WP_Error( 'missing_fields', __( 'Enter your email or username and password.', 'koopo' ) );
            status_header( 400 );
            return;
        }
        $buckets = $this->buckets( $this->username );
        foreach ( $buckets as $key => $limit ) {
            if ( (int) get_transient( $key ) >= $limit ) {
                $this->error = new WP_Error( 'rate_limited', __( 'Too many sign-in attempts. Please wait 15 minutes and try again.', 'koopo' ) );
                status_header( 429 );
                header( 'Retry-After: 900' );
                return;
            }
        }
        // Native authentication applies inactive/suspended, age, managed profile and SMS policies.
        $user = wp_signon( array( 'user_login' => $this->username, 'user_password' => $password, 'remember' => ! empty( $_POST['rememberme'] ) ) );
        if ( is_wp_error( $user ) ) {
            $this->error = $user;
            if ( ! in_array( $user->get_error_code(), array( 'koopo_sms_2fa_required', 'koopo_sms_2fa_unavailable' ), true ) ) {
                foreach ( $buckets as $key => $limit ) {
                    set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
                }
            }
            status_header( 'koopo_sms_2fa_required' === $user->get_error_code() ? 200 : 401 );
            return;
        }
        foreach ( array_keys( $buckets ) as $key ) {
            delete_transient( $key );
        }
        $this->guest_cookie( '', time() - HOUR_IN_SECONDS );
        wp_set_current_user( $user->ID );
        $requested = $this->requested_destination();
        $redirect = apply_filters( 'login_redirect', $requested ?: home_url( '/' ), $requested, $user );
        wp_safe_redirect( self::safe_destination( $redirect ), 303, 'Koopo Login' );
        exit;
    }

    public function form( $settings = array() ) {
        wp_enqueue_style( 'koopo-login' );
        wp_enqueue_script( 'koopo-login' );
        $settings = wp_parse_args( (array) $settings, array(
            'user_label' => __( 'Email or username', 'koopo' ),
            'user_placeholder' => __( 'Enter your email or username', 'koopo' ),
            'password_label' => __( 'Password', 'koopo' ),
            'password_placeholder' => __( 'Enter your password', 'koopo' ),
            'button_text' => __( 'Sign in', 'koopo' ),
            'show_social' => 'yes',
        ) );
        $editing = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
        $destination = $this->requested_destination();
        ob_start();
        if ( is_user_logged_in() && ! $editing ) {
            echo '<div class="kl-signed-in"><p>' . esc_html__( 'You are already signed in.', 'koopo' ) . '</p><a class="kl-submit" href="' . esc_url( $destination ?: home_url( '/' ) ) . '">' . esc_html__( 'Continue to Koopo', 'koopo' ) . '</a><p><a href="' . esc_url( wp_logout_url( self::page_url() ) ) . '">' . esc_html__( 'Sign out', 'koopo' ) . '</a></p></div>';
            return ob_get_clean();
        }
        if ( 'yes' === $settings['show_social'] && function_exists( 'the_champ_login_button' ) ) {
            $social = the_champ_login_button( true );
            if ( $social ) {
                echo '<div class="kl-social">' . $social . '</div><div class="kl-divider"><span>' . esc_html__( 'or sign in with email', 'koopo' ) . '</span></div>'; // Existing provider plugin owns its markup/callbacks.
            }
        }
        $uid = wp_unique_id( 'kl-' );
        $generic_codes = array( 'invalid_username', 'invalid_email', 'incorrect_password' );
        $message = $this->error ? ( in_array( $this->error->get_error_code(), $generic_codes, true ) ? __( 'The email, username, or password is incorrect. Please try again.', 'koopo' ) : $this->error->get_error_message() ) : '';
        ?>
        <form class="kl-form" data-token-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post" action="<?php echo esc_url( self::page_url() ?: get_permalink() ); ?>" aria-label="<?php esc_attr_e( 'Koopo sign-in', 'koopo' ); ?>">
            <?php wp_nonce_field( 'koopo_login', '_koopo_login_nonce', false ); ?>
            <input type="hidden" name="koopo_login_submit" value="1">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $destination ); ?>">
            <?php if ( $message ) : ?><div class="kl-notice" role="alert" tabindex="-1"><?php echo wp_kses_post( $message ); ?></div><?php endif; ?>
            <div class="kl-field"><label for="<?php echo esc_attr( $uid ); ?>user"><?php echo esc_html( $settings['user_label'] ); ?></label><input id="<?php echo esc_attr( $uid ); ?>user" name="log" type="text" autocomplete="username" required value="<?php echo esc_attr( $this->username ); ?>" placeholder="<?php echo esc_attr( $settings['user_placeholder'] ); ?>"></div>
            <div class="kl-field"><label for="<?php echo esc_attr( $uid ); ?>password"><?php echo esc_html( $settings['password_label'] ); ?></label><div class="kl-password"><input id="<?php echo esc_attr( $uid ); ?>password" name="pwd" type="password" autocomplete="current-password" required placeholder="<?php echo esc_attr( $settings['password_placeholder'] ); ?>"><button class="kl-show" type="button" aria-label="<?php esc_attr_e( 'Show password', 'koopo' ); ?>" aria-controls="<?php echo esc_attr( $uid ); ?>password" aria-pressed="false"><?php esc_html_e( 'Show', 'koopo' ); ?></button></div></div>
            <?php
            // Include extensions registered for WordPress login (SMS etc.), without duplicating social/theme chrome.
            $social_priority = has_action( 'login_form', 'the_champ_login_button' );
            $theme_priority = has_action( 'login_form', 'login_custom_form' );
            if ( false !== $social_priority ) { remove_action( 'login_form', 'the_champ_login_button', $social_priority ); }
            if ( false !== $theme_priority ) { remove_action( 'login_form', 'login_custom_form', $theme_priority ); }
            echo '<div class="kl-extensions">';
            try { do_action( 'login_form' ); } finally {
                if ( false !== $social_priority ) { add_action( 'login_form', 'the_champ_login_button', $social_priority ); }
                if ( false !== $theme_priority ) { add_action( 'login_form', 'login_custom_form', $theme_priority ); }
            }
            echo '</div>';
            ?>
            <div class="kl-options"><label class="kl-remember"><input type="checkbox" name="rememberme" value="forever" <?php checked( ! empty( $_POST['rememberme'] ) ); ?>><?php esc_html_e( 'Remember me', 'koopo' ); ?></label><a href="<?php echo esc_url( wp_lostpassword_url( $destination ) ); ?>"><?php esc_html_e( 'Forgot password?', 'koopo' ); ?></a></div>
            <button class="kl-submit" type="submit"><span><?php echo esc_html( $settings['button_text'] ); ?></span><span aria-hidden="true">→</span></button>
            <p class="kl-register"><?php esc_html_e( 'New to Koopo?', 'koopo' ); ?> <a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create an account', 'koopo' ); ?></a></p>
        </form>
        <?php
        return ob_get_clean();
    }
}
