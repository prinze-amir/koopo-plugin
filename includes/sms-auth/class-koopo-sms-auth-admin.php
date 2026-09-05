<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_SMS_Auth_Admin {
    private $service;

    public function __construct( Koopo_SMS_Auth_Service $service ) {
        $this->service = $service;
    }

    public function hooks() {
        add_action( 'koopo_admin_register_submenus', array( $this, 'register_submenu' ), 25, 2 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_submenu( $parent_slug, $capability ) {
        add_submenu_page(
            $parent_slug,
            __( 'SMS Auth', 'koopo' ),
            __( 'SMS Auth', 'koopo' ),
            $capability,
            'koopo-sms-auth',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        $toggles = array(
            Koopo_SMS_Auth_Service::OPTION_ENABLED,
            Koopo_SMS_Auth_Service::OPTION_AUTO_CREATE,
            Koopo_SMS_Auth_Service::OPTION_REQUIRE_2FA,
        );

        foreach ( $toggles as $option ) {
            register_setting(
                'koopo_sms_auth_settings_group',
                $option,
                array(
                    'sanitize_callback' => array( $this, 'sanitize_toggle' ),
                    'default'           => 0,
                )
            );
        }

        register_setting(
            'koopo_sms_auth_settings_group',
            Koopo_SMS_Auth_Service::OPTION_PROVIDER,
            array(
                'sanitize_callback' => array( $this, 'sanitize_provider' ),
                'default'           => 'twilio',
            )
        );

        foreach ( array( Koopo_SMS_Auth_Service::OPTION_TWILIO_SID, Koopo_SMS_Auth_Service::OPTION_TWILIO_TOKEN, Koopo_SMS_Auth_Service::OPTION_TWILIO_FROM, Koopo_SMS_Auth_Service::OPTION_TWILIO_SERVICE, Koopo_SMS_Auth_Service::OPTION_COUNTRY_CODE ) as $option ) {
            register_setting(
                'koopo_sms_auth_settings_group',
                $option,
                array(
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                )
            );
        }

        $ints = array(
            Koopo_SMS_Auth_Service::OPTION_CODE_TTL   => 10,
            Koopo_SMS_Auth_Service::OPTION_MAX_ATTEMPTS => 5,
            Koopo_SMS_Auth_Service::OPTION_RATE_LIMIT => 5,
            Koopo_SMS_Auth_Service::OPTION_TOKEN_TTL  => 30,
        );

        foreach ( $ints as $option => $default ) {
            register_setting(
                'koopo_sms_auth_settings_group',
                $option,
                array(
                    'sanitize_callback' => 'absint',
                    'default'           => $default,
                )
            );
        }
    }

    public function sanitize_toggle( $value ) {
        return ! empty( $value ) ? 1 : 0;
    }

    public function sanitize_provider( $value ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'twilio', 'log' ), true ) ? $value : 'twilio';
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="wrap koopo-sms-auth-admin">
            <h1><?php esc_html_e( 'SMS Auth Settings', 'koopo' ); ?></h1>
            <p><?php esc_html_e( 'Enable phone-number login, SMS verification codes, mobile bearer tokens, and optional SMS 2FA for the WordPress login screen.', 'koopo' ); ?></p>

            <style>
                .koopo-sms-auth-admin .koopo-sms-grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr); max-width: 980px; }
                .koopo-sms-auth-admin .koopo-sms-card { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 18px; }
                .koopo-sms-auth-admin .koopo-sms-card h2 { margin-top: 0; }
                .koopo-sms-auth-admin .regular-text { width: min(460px, 100%); }
                .koopo-sms-auth-admin code { background: #f0f0f1; padding: 2px 6px; border-radius: 4px; }
                .koopo-sms-auth-admin .koopo-sms-warning { border-left: 4px solid #d63638; padding: 8px 12px; background: #fff7f7; max-width: 860px; }
            </style>

            <form method="post" action="options.php">
                <?php settings_fields( 'koopo_sms_auth_settings_group' ); ?>
                <input type="hidden" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_ENABLED ); ?>" value="0" />
                <input type="hidden" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_AUTO_CREATE ); ?>" value="0" />
                <input type="hidden" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_REQUIRE_2FA ); ?>" value="0" />

                <div class="koopo-sms-grid">
                    <section class="koopo-sms-card">
                        <h2><?php esc_html_e( 'Core Behavior', 'koopo' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Enable SMS Auth', 'koopo' ); ?></th>
                                <td><label><input type="checkbox" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_ENABLED ); ?>" value="1" <?php checked( get_option( Koopo_SMS_Auth_Service::OPTION_ENABLED, 0 ) ); ?> /> <?php esc_html_e( 'Allow SMS login and phone verification.', 'koopo' ); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Auto-create users', 'koopo' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_AUTO_CREATE ); ?>" value="1" <?php checked( get_option( Koopo_SMS_Auth_Service::OPTION_AUTO_CREATE, 0 ) ); ?> /> <?php esc_html_e( 'Create a new account when a verified phone number is not attached to an existing user.', 'koopo' ); ?></label>
                                    <p class="description"><?php esc_html_e( 'Recommended off unless your mobile onboarding is ready for phone-only accounts.', 'koopo' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'WordPress login 2FA', 'koopo' ); ?></th>
                                <td>
                                    <label><input type="checkbox" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_REQUIRE_2FA ); ?>" value="1" <?php checked( get_option( Koopo_SMS_Auth_Service::OPTION_REQUIRE_2FA, 0 ) ); ?> /> <?php esc_html_e( 'Require SMS code after password login for non-admin users with a verified phone.', 'koopo' ); ?></label>
                                    <p class="description"><?php esc_html_e( 'Admins are intentionally exempt to avoid lockouts. Test this on staging before enabling in production.', 'koopo' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_default_country_code"><?php esc_html_e( 'Default country code', 'koopo' ); ?></label></th>
                                <td><input type="text" id="koopo_sms_auth_default_country_code" class="regular-text" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_COUNTRY_CODE ); ?>" value="<?php echo esc_attr( get_option( Koopo_SMS_Auth_Service::OPTION_COUNTRY_CODE, '+1' ) ); ?>" placeholder="+1" /></td>
                            </tr>
                        </table>
                    </section>

                    <section class="koopo-sms-card">
                        <h2><?php esc_html_e( 'Provider', 'koopo' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_provider"><?php esc_html_e( 'SMS provider', 'koopo' ); ?></label></th>
                                <td>
                                    <select id="koopo_sms_auth_provider" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_PROVIDER ); ?>">
                                        <option value="twilio" <?php selected( get_option( Koopo_SMS_Auth_Service::OPTION_PROVIDER, 'twilio' ), 'twilio' ); ?>><?php esc_html_e( 'Twilio', 'koopo' ); ?></option>
                                        <option value="log" <?php selected( get_option( Koopo_SMS_Auth_Service::OPTION_PROVIDER, 'twilio' ), 'log' ); ?>><?php esc_html_e( 'Log only / development', 'koopo' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_twilio_sid"><?php esc_html_e( 'Twilio Account SID', 'koopo' ); ?></label></th>
                                <td><input type="text" id="koopo_sms_auth_twilio_sid" class="regular-text" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_TWILIO_SID ); ?>" value="<?php echo esc_attr( get_option( Koopo_SMS_Auth_Service::OPTION_TWILIO_SID, '' ) ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_twilio_token"><?php esc_html_e( 'Twilio Auth Token', 'koopo' ); ?></label></th>
                                <td><input type="password" id="koopo_sms_auth_twilio_token" class="regular-text" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_TWILIO_TOKEN ); ?>" value="<?php echo esc_attr( get_option( Koopo_SMS_Auth_Service::OPTION_TWILIO_TOKEN, '' ) ); ?>" autocomplete="off" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_twilio_from"><?php esc_html_e( 'Twilio From Number', 'koopo' ); ?></label></th>
                                <td><input type="text" id="koopo_sms_auth_twilio_from" class="regular-text" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_TWILIO_FROM ); ?>" value="<?php echo esc_attr( get_option( Koopo_SMS_Auth_Service::OPTION_TWILIO_FROM, '' ) ); ?>" placeholder="+15551234567" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_twilio_service_sid"><?php esc_html_e( 'Messaging Service SID', 'koopo' ); ?></label></th>
                                <td>
                                    <input type="text" id="koopo_sms_auth_twilio_service_sid" class="regular-text" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_TWILIO_SERVICE ); ?>" value="<?php echo esc_attr( get_option( Koopo_SMS_Auth_Service::OPTION_TWILIO_SERVICE, '' ) ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Optional. If set, this is used instead of the From Number.', 'koopo' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </section>

                    <section class="koopo-sms-card">
                        <h2><?php esc_html_e( 'Security Limits', 'koopo' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_code_ttl_minutes"><?php esc_html_e( 'Code lifetime', 'koopo' ); ?></label></th>
                                <td><input type="number" min="2" max="30" id="koopo_sms_auth_code_ttl_minutes" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_CODE_TTL ); ?>" value="<?php echo esc_attr( absint( get_option( Koopo_SMS_Auth_Service::OPTION_CODE_TTL, 10 ) ) ); ?>" /> <?php esc_html_e( 'minutes', 'koopo' ); ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_max_attempts"><?php esc_html_e( 'Max code attempts', 'koopo' ); ?></label></th>
                                <td><input type="number" min="1" max="10" id="koopo_sms_auth_max_attempts" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_MAX_ATTEMPTS ); ?>" value="<?php echo esc_attr( absint( get_option( Koopo_SMS_Auth_Service::OPTION_MAX_ATTEMPTS, 5 ) ) ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_rate_limit_per_hour"><?php esc_html_e( 'SMS requests per phone/hour', 'koopo' ); ?></label></th>
                                <td><input type="number" min="1" max="20" id="koopo_sms_auth_rate_limit_per_hour" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_RATE_LIMIT ); ?>" value="<?php echo esc_attr( absint( get_option( Koopo_SMS_Auth_Service::OPTION_RATE_LIMIT, 5 ) ) ); ?>" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="koopo_sms_auth_token_ttl_days"><?php esc_html_e( 'Mobile token lifetime', 'koopo' ); ?></label></th>
                                <td><input type="number" min="1" max="365" id="koopo_sms_auth_token_ttl_days" name="<?php echo esc_attr( Koopo_SMS_Auth_Service::OPTION_TOKEN_TTL ); ?>" value="<?php echo esc_attr( absint( get_option( Koopo_SMS_Auth_Service::OPTION_TOKEN_TTL, 30 ) ) ); ?>" /> <?php esc_html_e( 'days', 'koopo' ); ?></td>
                            </tr>
                        </table>
                    </section>

                    <section class="koopo-sms-card">
                        <h2><?php esc_html_e( 'Shortcodes and API', 'koopo' ); ?></h2>
                        <p><?php esc_html_e( 'Phone login form:', 'koopo' ); ?> <code>[koopo_sms_login]</code></p>
                        <p><?php esc_html_e( 'Phone management form for logged-in users:', 'koopo' ); ?> <code>[koopo_phone_settings]</code></p>
                        <p><?php esc_html_e( 'Mobile apps should use these REST endpoints:', 'koopo' ); ?></p>
                        <ul>
                            <li><code>POST /wp-json/koopo/v1/sms-auth/login/start</code></li>
                            <li><code>POST /wp-json/koopo/v1/sms-auth/login/verify</code></li>
                            <li><code>GET /wp-json/koopo/v1/sms-auth/me</code> with <code>Authorization: Bearer TOKEN</code></li>
                            <li><code>POST /wp-json/koopo/v1/sms-auth/phone/start</code></li>
                            <li><code>POST /wp-json/koopo/v1/sms-auth/phone/verify</code></li>
                        </ul>
                        <p class="koopo-sms-warning"><?php esc_html_e( 'Production note: use a real SMS provider, enable rate limits, and avoid log-only provider outside development.', 'koopo' ); ?></p>
                    </section>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
