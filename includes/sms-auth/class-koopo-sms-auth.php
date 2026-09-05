<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-koopo-sms-auth-service.php';
require_once __DIR__ . '/class-koopo-sms-auth-rest.php';
require_once __DIR__ . '/class-koopo-sms-auth-admin.php';

class Koopo_SMS_Auth {
    private static $instance = null;

    private $service;
    private $rest;
    private $admin;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate() {
        Koopo_SMS_Auth_Service::activate();
    }

    private function __construct() {
        $this->service = new Koopo_SMS_Auth_Service();
        $this->rest    = new Koopo_SMS_Auth_REST( $this->service );
        $this->admin   = new Koopo_SMS_Auth_Admin( $this->service );

        $this->service->hooks();
        $this->rest->hooks();
        $this->admin->hooks();

        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 15 );
    }

    public function service() {
        return $this->service;
    }

    public function register_shortcodes() {
        add_shortcode( 'koopo_sms_login', array( $this, 'login_shortcode' ) );
        add_shortcode( 'smsLogin', array( $this, 'login_shortcode' ) );
        add_shortcode( 'koopo_phone_settings', array( $this, 'phone_settings_shortcode' ) );
    }

    public function register_assets() {
        wp_register_style(
            'koopo-sms-auth',
            plugins_url( 'assets/koopo-sms-auth.css', __FILE__ ),
            array(),
            $this->asset_version( 'assets/koopo-sms-auth.css' )
        );

        wp_register_script(
            'koopo-sms-auth',
            plugins_url( 'assets/koopo-sms-auth.js', __FILE__ ),
            array(),
            $this->asset_version( 'assets/koopo-sms-auth.js' ),
            true
        );
    }

    public function login_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'redirect'    => '',
                'title'       => __( 'Log in with your phone', 'koopo' ),
                'description' => __( 'Enter your phone number and we will text you a verification code.', 'koopo' ),
                'button'      => __( 'Send Code', 'koopo' ),
                'verify'      => __( 'Verify and Log In', 'koopo' ),
                'class_name'  => '',
            ),
            (array) $atts,
            'koopo_sms_login'
        );

        if ( is_user_logged_in() ) {
            return '<div class="koopo-sms-auth koopo-sms-auth--notice"><p>' . esc_html__( 'You are already logged in.', 'koopo' ) . '</p></div>';
        }

        if ( ! $this->service->is_enabled() ) {
            return '<div class="koopo-sms-auth koopo-sms-auth--notice"><p>' . esc_html__( 'SMS login is not enabled yet.', 'koopo' ) . '</p></div>';
        }

        $this->enqueue_assets();

        $redirect = '' !== $atts['redirect'] ? esc_url_raw( (string) $atts['redirect'] ) : $this->current_url();
        $classes  = trim( 'koopo-sms-auth koopo-sms-auth--login ' . $this->sanitize_class_names( (string) $atts['class_name'] ) );

        ob_start();
        ?>
        <section class="<?php echo esc_attr( $classes ); ?>" data-koopo-sms-login data-redirect-url="<?php echo esc_url( $redirect ); ?>">
            <div class="koopo-sms-auth__panel">
                <p class="koopo-sms-auth__eyebrow"><?php esc_html_e( 'Secure login', 'koopo' ); ?></p>
                <h3><?php echo esc_html( (string) $atts['title'] ); ?></h3>
                <p><?php echo esc_html( (string) $atts['description'] ); ?></p>

                <form class="koopo-sms-auth__form" data-sms-step="phone">
                    <label>
                        <span><?php esc_html_e( 'Phone number', 'koopo' ); ?></span>
                        <input type="tel" name="phone" autocomplete="tel" inputmode="tel" placeholder="+1 555 123 4567" required />
                    </label>
                    <button type="submit" class="koopo-sms-auth__button"><?php echo esc_html( (string) $atts['button'] ); ?></button>
                </form>

                <form class="koopo-sms-auth__form" data-sms-step="code" hidden>
                    <label>
                        <span><?php esc_html_e( 'Verification code', 'koopo' ); ?></span>
                        <input type="text" name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*" placeholder="123456" required />
                    </label>
                    <label class="koopo-sms-auth__check">
                        <input type="checkbox" name="remember" value="1" />
                        <span><?php esc_html_e( 'Remember me', 'koopo' ); ?></span>
                    </label>
                    <button type="submit" class="koopo-sms-auth__button"><?php echo esc_html( (string) $atts['verify'] ); ?></button>
                    <button type="button" class="koopo-sms-auth__link" data-sms-back><?php esc_html_e( 'Use a different number', 'koopo' ); ?></button>
                </form>

                <p class="koopo-sms-auth__status" data-sms-status aria-live="polite"></p>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function phone_settings_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<div class="koopo-sms-auth koopo-sms-auth--notice"><p>' . esc_html__( 'Please log in to manage your phone number.', 'koopo' ) . '</p></div>';
        }

        if ( ! $this->service->is_enabled() ) {
            return '<div class="koopo-sms-auth koopo-sms-auth--notice"><p>' . esc_html__( 'SMS phone verification is not enabled yet.', 'koopo' ) . '</p></div>';
        }

        $this->enqueue_assets();
        $state = $this->service->user_phone_state( get_current_user_id() );

        ob_start();
        ?>
        <section class="koopo-sms-auth koopo-sms-auth--settings" data-koopo-sms-phone-settings>
            <div class="koopo-sms-auth__panel">
                <p class="koopo-sms-auth__eyebrow"><?php esc_html_e( 'Account security', 'koopo' ); ?></p>
                <h3><?php esc_html_e( 'Phone verification', 'koopo' ); ?></h3>
                <p data-sms-current-phone>
                    <?php
                    echo ! empty( $state['has_phone'] )
                        ? esc_html( sprintf( __( 'Verified phone: %s', 'koopo' ), $state['masked'] ) )
                        : esc_html__( 'No verified phone number is connected yet.', 'koopo' );
                    ?>
                </p>

                <form class="koopo-sms-auth__form" data-sms-phone-step="phone">
                    <label>
                        <span><?php esc_html_e( 'Phone number', 'koopo' ); ?></span>
                        <input type="tel" name="phone" autocomplete="tel" inputmode="tel" placeholder="+1 555 123 4567" required />
                    </label>
                    <button type="submit" class="koopo-sms-auth__button"><?php esc_html_e( 'Send Verification Code', 'koopo' ); ?></button>
                </form>

                <form class="koopo-sms-auth__form" data-sms-phone-step="code" hidden>
                    <label>
                        <span><?php esc_html_e( 'Verification code', 'koopo' ); ?></span>
                        <input type="text" name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*" required />
                    </label>
                    <button type="submit" class="koopo-sms-auth__button"><?php esc_html_e( 'Verify Phone', 'koopo' ); ?></button>
                    <button type="button" class="koopo-sms-auth__link" data-sms-phone-back><?php esc_html_e( 'Use a different number', 'koopo' ); ?></button>
                </form>

                <?php if ( ! empty( $state['has_phone'] ) ) : ?>
                    <button type="button" class="koopo-sms-auth__link koopo-sms-auth__link--danger" data-sms-remove-phone><?php esc_html_e( 'Remove phone number', 'koopo' ); ?></button>
                <?php endif; ?>

                <p class="koopo-sms-auth__status" data-sms-status aria-live="polite"></p>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private function enqueue_assets() {
        $this->register_assets();
        wp_enqueue_style( 'koopo-sms-auth' );
        wp_enqueue_script( 'koopo-sms-auth' );
        wp_localize_script(
            'koopo-sms-auth',
            'KoopoSmsAuth',
            array(
                'restBase' => esc_url_raw( rest_url( 'koopo/v1/sms-auth' ) ),
                'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
                'messages' => array(
                    'sending'       => __( 'Sending code...', 'koopo' ),
                    'sent'          => __( 'Code sent. Check your phone.', 'koopo' ),
                    'verifying'     => __( 'Verifying code...', 'koopo' ),
                    'verified'      => __( 'Verified.', 'koopo' ),
                    'failed'        => __( 'Something went wrong. Please try again.', 'koopo' ),
                    'removing'      => __( 'Removing phone number...', 'koopo' ),
                    'removed'       => __( 'Phone number removed.', 'koopo' ),
                ),
            )
        );
    }

    private function asset_version( $asset_path ) {
        $file = __DIR__ . '/' . ltrim( $asset_path, '/' );
        return file_exists( $file ) ? (string) filemtime( $file ) : '1.0.0';
    }

    private function sanitize_class_names( $class_names ) {
        $classes = preg_split( '/\s+/', trim( (string) $class_names ) );
        $classes = array_filter( array_map( 'sanitize_html_class', (array) $classes ) );
        return implode( ' ', $classes );
    }

    private function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        return $scheme . $host . $uri;
    }
}
