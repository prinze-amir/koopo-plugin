<?php
/**
 * Creator onboarding for the Influencer Square.
 *
 * New accounts stay inside BuddyBoss' registration and activation pipeline.
 * Existing accounts complete the same creator profile through an authenticated
 * REST request. Creator access is never granted until onboarding is complete.
 */

defined( 'ABSPATH' ) || exit;

final class Koopo_Creator_Onboarding {
    const QUERY_ARG      = 'creator_signup';
    const SIGNUP_META    = 'koopo_creator_onboarding_v1';
    const COMPLETE_META  = '_koopo_creator_onboarding_completed';
    const PROFILE_META   = '_koopo_creator_profile_v1';
    const DOB_META       = '_koopo_date_of_birth';
    const AGE_META       = '_koopo_age_status';
    const MARKER         = 'koopo_creator_onboarding';

    private static $rendered = false;
    private static $activation_redirect = '';

    public static function boot() {
        add_action( 'bp_after_account_details_fields', array( __CLASS__, 'render_without_xprofile' ) );
        add_action( 'bp_after_signup_profile_fields', array( __CLASS__, 'render_signup_fields' ) );
        add_action( 'bp_signup_validate', array( __CLASS__, 'validate_signup' ) );
        add_filter( 'bp_signup_usermeta', array( __CLASS__, 'add_signup_meta' ) );
        add_action( 'bp_core_activated_user', array( __CLASS__, 'activate_creator' ), 45, 3 );
        add_filter( 'bp_core_activate_account', array( __CLASS__, 'redirect_after_activation' ), 60 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_signup_assets' ), 35 );
        add_action( 'wp_footer', array( __CLASS__, 'render_square_mount' ), 90 );
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
    }

    public static function signup_url() {
        $base = function_exists( 'bp_get_signup_page' ) ? bp_get_signup_page() : wp_registration_url();
        return add_query_arg( self::QUERY_ARG, '1', $base );
    }

    public static function is_signup_request() {
        if ( ! empty( $_POST[ self::MARKER ] ) ) {
            return '1' === sanitize_text_field( wp_unslash( $_POST[ self::MARKER ] ) );
        }
        return isset( $_GET[ self::QUERY_ARG ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) );
    }

    private static function is_signup_form_step() {
        return self::is_signup_request() && ( ! function_exists( 'bp_get_current_signup_step' ) || 'request-details' === bp_get_current_signup_step() );
    }

    public static function enqueue_signup_assets() {
        global $post;
        $has_shortcode = $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'joinTheSquare' );
        if ( self::is_signup_form_step() || $has_shortcode || self::is_square_surface() ) {
            self::enqueue_assets();
        }
    }

    private static function is_square_surface() {
        if ( is_admin() ) {
            return false;
        }
        $page_id = is_home() ? (int) get_option( 'page_for_posts' ) : (int) get_queried_object_id();
        return $page_id > 0 && 'influencer-square' === get_post_field( 'post_name', $page_id );
    }

    public static function render_square_mount() {
        if ( ! self::is_square_surface() ) {
            return;
        }
        echo '<div class="koopo-creator-square-mount" data-koopo-creator-square-mount>' . self::render_shortcode() . '</div>';
    }

    public static function enqueue_assets() {
        wp_enqueue_style( 'koopo-creator-onboarding', plugins_url( 'assets/creator-onboarding.css', __FILE__ ), array(), '1.0.1' );
        wp_enqueue_script( 'koopo-creator-onboarding', plugins_url( 'assets/creator-onboarding.js', __FILE__ ), array(), '1.0.1', true );
        wp_localize_script(
            'koopo-creator-onboarding',
            'KOOPO_CREATOR_ONBOARDING',
            array(
                'restUrl' => esc_url_raw( rest_url( 'koopo/v1/creator-onboarding' ) ),
                'ageConfirmationUrl' => esc_url_raw( rest_url( 'koopo/v1/auth/age-confirmation' ) ),
                'agePolicyActive' => class_exists( 'Koopo_Accounts_Age_Policy' ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'loginUrl'=> wp_login_url( home_url( '/influencer-square/' ) ),
            )
        );
    }

    public static function body_class( $classes ) {
        if ( self::is_signup_form_step() ) {
            $classes[] = 'koopo-creator-signup';
        }
        return $classes;
    }

    public static function register_routes() {
        register_rest_route(
            'koopo/v1',
            '/creator-onboarding',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_status' ),
                    'permission_callback' => static function() { return is_user_logged_in(); },
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'rest_complete' ),
                    'permission_callback' => static function() { return is_user_logged_in(); },
                ),
            )
        );
    }

    public static function rest_status() {
        return rest_ensure_response( self::status_payload( get_current_user_id() ) );
    }

    public static function rest_complete( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $payload = self::sanitize_payload( $request->get_json_params() ?: $request->get_params(), true );
        $error   = self::validate_payload( $payload, true, $user_id );
        if ( is_wp_error( $error ) ) {
            return $error;
        }

        $result = self::complete( $user_id, $payload );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( self::status_payload( $user_id ) );
    }

    public static function render_without_xprofile() {
        if ( function_exists( 'bp_is_active' ) && bp_is_active( 'xprofile' ) && function_exists( 'bp_nouveau_base_account_has_xprofile' ) && bp_nouveau_base_account_has_xprofile() ) {
            return;
        }
        self::render_signup_fields();
    }

    public static function render_signup_fields() {
        if ( self::$rendered || ! self::is_signup_request() ) {
            return;
        }
        self::$rendered = true;
        $values = self::posted_payload();
        ?>
        <input type="hidden" name="<?php echo esc_attr( self::MARKER ); ?>" value="1">
        <section class="register-section koopo-creator-signup-step" id="koopo-creator-goals" data-koopo-creator-step="goals">
            <span class="koopo-creator-kicker"><?php esc_html_e( 'Your creator path', 'koopo' ); ?></span>
            <h2><?php esc_html_e( 'What do you want to create?', 'koopo' ); ?></h2>
            <p><?php esc_html_e( 'Choose everything that fits. Your primary choice decides where Koopo takes you first after activation.', 'koopo' ); ?></p>
            <?php do_action( 'bp_koopo_creator_content_types_errors' ); ?>
            <?php self::render_goal_choices( $values['content_types'], $values['primary_goal'] ); ?>
        </section>
        <section class="register-section koopo-creator-signup-step" id="koopo-creator-opportunity" data-koopo-creator-step="opportunity">
            <span class="koopo-creator-kicker"><?php esc_html_e( 'Grow with Koopo', 'koopo' ); ?></span>
            <h2><?php esc_html_e( 'Turn an audience into a community.', 'koopo' ); ?></h2>
            <p><?php esc_html_e( 'Creator support, subscriber relationships, and eligible ad-revenue sharing give your work more than one way to grow.', 'koopo' ); ?></p>
            <label class="koopo-creator-affiliate-choice">
                <input type="checkbox" name="koopo_creator_affiliate_interest" value="1" <?php checked( $values['affiliate_interest'] ); ?>>
                <span><strong><?php esc_html_e( 'I want to learn about affiliate influencing', 'koopo' ); ?></strong><small><?php esc_html_e( 'Discover eligible products and services, create honest recommendations, and apply to earn from attributed sales.', 'koopo' ); ?></small></span>
            </label>
            <label class="koopo-creator-field" for="koopo-creator-experience"><span><?php esc_html_e( 'Tell us what you hope to make', 'koopo' ); ?> <small><?php esc_html_e( 'Optional', 'koopo' ); ?></small></span><textarea id="koopo-creator-experience" name="koopo_creator_experience" rows="4" maxlength="500" placeholder="<?php esc_attr_e( 'Tutorials, local stories, product reviews, a weekly podcast…', 'koopo' ); ?>"><?php echo esc_textarea( $values['experience'] ); ?></textarea></label>
            <aside class="koopo-creator-trust"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'Your creator tools unlock after email verification.', 'koopo' ); ?></strong><p><?php esc_html_e( 'Koopo uses the normal BuddyBoss activation email. Your public creator access is not granted before the account is verified.', 'koopo' ); ?></p></div></aside>
        </section>
        <?php
    }

    private static function render_goal_choices( $selected, $primary ) {
        foreach ( self::goal_options() as $key => $option ) {
            $checked = in_array( $key, $selected, true );
            ?>
            <article class="koopo-creator-choice koopo-creator-choice--<?php echo esc_attr( $key ); ?><?php echo $checked ? ' is-selected' : ''; ?>">
                <label class="koopo-creator-choice__main">
                    <input type="checkbox" name="koopo_creator_content_types[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
                    <span class="koopo-creator-choice__art" aria-hidden="true"><?php echo wp_kses( $option['icon'], self::svg_kses() ); ?></span>
                    <span class="koopo-creator-choice__copy"><strong><?php echo esc_html( $option['title'] ); ?></strong><small><?php echo esc_html( $option['description'] ); ?></small><em><?php echo esc_html( $option['benefit'] ); ?></em></span>
                </label>
                <label class="koopo-creator-primary"><input type="radio" name="koopo_creator_primary_goal" value="<?php echo esc_attr( $key ); ?>" <?php checked( $primary, $key ); ?> <?php echo $checked ? '' : 'disabled'; ?>><span><?php esc_html_e( 'Make this my first stop', 'koopo' ); ?></span></label>
            </article>
            <?php
        }
    }

    public static function validate_signup() {
        if ( ! self::is_signup_request() || ! function_exists( 'buddypress' ) ) {
            return;
        }
        $payload = self::posted_payload();
        $error   = self::validate_payload( $payload, false, 0 );
        if ( is_wp_error( $error ) ) {
            buddypress()->signup->errors['koopo_creator_content_types'] = $error->get_error_message();
        }
    }

    public static function add_signup_meta( $usermeta ) {
        if ( self::is_signup_request() ) {
            $usermeta[ self::SIGNUP_META ] = self::posted_payload();
        }
        return $usermeta;
    }

    public static function activate_creator( $user_id, $key, $user ) {
        unset( $key );
        $payload = isset( $user['meta'][ self::SIGNUP_META ] ) ? $user['meta'][ self::SIGNUP_META ] : null;
        if ( ! is_array( $payload ) || ! $user_id ) {
            return;
        }
        $result = self::complete( (int) $user_id, self::sanitize_payload( $payload, false ) );
        if ( ! is_wp_error( $result ) ) {
            self::$activation_redirect = self::destination_url( (int) $user_id, $payload['primary_goal'] ?? 'articles' );
        }
    }

    public static function redirect_after_activation( $result ) {
        if ( is_numeric( $result ) && (int) $result > 0 ) {
            $profile = get_user_meta( (int) $result, self::PROFILE_META, true );
            $target  = self::$activation_redirect;
            if ( ! $target && is_array( $profile ) ) {
                $target = self::destination_url( (int) $result, $profile['primary_goal'] ?? 'articles' );
            }
            if ( $target ) {
                if ( function_exists( 'bp_core_add_message' ) ) {
                    bp_core_add_message( __( 'Your creator account is active. Welcome to the Square.', 'koopo' ) );
                }
                if ( function_exists( 'bp_core_redirect' ) ) {
                    bp_core_redirect( add_query_arg( 'creator_welcome', '1', $target ) );
                }
            }
        }
        return $result;
    }

    private static function complete( $user_id, $payload ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return new WP_Error( 'creator_user_missing', __( 'Your account could not be loaded.', 'koopo' ), array( 'status' => 404 ) );
        }
        if ( ! get_role( 'influencer' ) ) {
            return new WP_Error( 'influencer_role_missing', __( 'Creator access is temporarily unavailable because the influencer role is not configured.', 'koopo' ), array( 'status' => 503 ) );
        }

        if ( ! empty( $payload['first_name'] ) || ! empty( $payload['last_name'] ) ) {
            $updated = wp_update_user(
                array(
                    'ID'         => $user_id,
                    'first_name' => $payload['first_name'] ?: $user->first_name,
                    'last_name'  => $payload['last_name'] ?: $user->last_name,
                )
            );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        }

        if ( ! empty( $payload['account_type'] ) ) {
            update_user_meta( $user_id, '_koopo_account_type', $payload['account_type'] );
            if ( 'business' === $payload['account_type'] ) {
                update_user_meta( $user_id, '_koopo_company_name', $payload['company_name'] );
            }
            if ( function_exists( 'bp_set_member_type' ) ) {
                bp_set_member_type( $user_id, $payload['account_type'] );
            }
        }

        if ( ! class_exists( 'Koopo_Accounts_Age_Policy' ) && ! empty( $payload['date_of_birth'] ) && ! get_user_meta( $user_id, self::DOB_META, true ) ) {
            update_user_meta( $user_id, self::DOB_META, $payload['date_of_birth'] );
            update_user_meta( $user_id, self::AGE_META, 'adult' );
            update_user_meta( $user_id, '_koopo_age_confirmed_at', gmdate( 'c' ) );
            self::set_xprofile_value( $user_id, array( 'Birthday', 'Birth Date', 'Date of Birth' ), $payload['date_of_birth'] );
        }

        $user->add_role( 'influencer' );
        $profile = array(
            'version'            => 1,
            'content_types'      => $payload['content_types'],
            'primary_goal'       => $payload['primary_goal'],
            'affiliate_interest' => ! empty( $payload['affiliate_interest'] ),
            'experience'         => $payload['experience'],
            'completed_at'       => gmdate( 'c' ),
        );
        update_user_meta( $user_id, self::PROFILE_META, $profile );
        update_user_meta( $user_id, self::COMPLETE_META, '1' );
        do_action( 'koopo_creator_onboarding_completed', $user_id, $profile );

        return $profile;
    }

    private static function validate_payload( $payload, $require_identity, $user_id ) {
        if ( empty( $payload['content_types'] ) ) {
            return new WP_Error( 'creator_content_required', __( 'Choose at least one type of content you want to create.', 'koopo' ), array( 'status' => 422 ) );
        }
        if ( ! in_array( $payload['primary_goal'], $payload['content_types'], true ) ) {
            return new WP_Error( 'creator_primary_required', __( 'Choose which creator path should be your first stop.', 'koopo' ), array( 'status' => 422 ) );
        }
        if ( ! $require_identity ) {
            return true;
        }
        if ( '' === $payload['first_name'] || '' === $payload['last_name'] ) {
            return new WP_Error( 'creator_name_required', __( 'First and last name are required.', 'koopo' ), array( 'status' => 422 ) );
        }
        if ( ! in_array( $payload['account_type'], array( 'personal', 'business' ), true ) ) {
            return new WP_Error( 'creator_account_type_required', __( 'Choose a personal or business account.', 'koopo' ), array( 'status' => 422 ) );
        }
        if ( 'business' === $payload['account_type'] && '' === $payload['company_name'] ) {
            return new WP_Error( 'creator_company_required', __( 'Enter the business name for this account.', 'koopo' ), array( 'status' => 422 ) );
        }
        if ( ! get_user_meta( $user_id, self::DOB_META, true ) ) {
            $dob = self::validate_adult_date( $payload['date_of_birth'] );
            if ( is_wp_error( $dob ) ) {
                return $dob;
            }
        }
        return true;
    }

    private static function validate_adult_date( $value ) {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
            return new WP_Error( 'invalid_date_of_birth', __( 'Enter a valid date of birth.', 'koopo' ), array( 'status' => 422 ) );
        }
        $today = new DateTimeImmutable( 'today', wp_timezone() );
        if ( $date > $today->modify( '-18 years' ) ) {
            return new WP_Error( 'age_restricted', __( 'You must be at least 18 years old to create on Koopo.', 'koopo' ), array( 'status' => 422 ) );
        }
        return $date->format( 'Y-m-d' );
    }

    private static function sanitize_payload( $input, $include_identity ) {
        $input = is_array( $input ) ? $input : array();
        $content = isset( $input['content_types'] ) && is_array( $input['content_types'] ) ? array_map( 'sanitize_key', $input['content_types'] ) : array();
        $content = array_values( array_intersect( array_keys( self::goal_options() ), $content ) );
        return array(
            'first_name'         => $include_identity ? sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ) : '',
            'last_name'          => $include_identity ? sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ) : '',
            'date_of_birth'      => $include_identity ? sanitize_text_field( (string) ( $input['date_of_birth'] ?? '' ) ) : '',
            'account_type'       => $include_identity ? sanitize_key( (string) ( $input['account_type'] ?? '' ) ) : '',
            'company_name'       => $include_identity ? sanitize_text_field( (string) ( $input['company_name'] ?? '' ) ) : '',
            'content_types'      => $content,
            'primary_goal'       => sanitize_key( (string) ( $input['primary_goal'] ?? '' ) ),
            'affiliate_interest' => ! empty( $input['affiliate_interest'] ),
            'experience'         => sanitize_textarea_field( (string) ( $input['experience'] ?? '' ) ),
        );
    }

    private static function posted_payload() {
        return self::sanitize_payload(
            array(
                'content_types'      => isset( $_POST['koopo_creator_content_types'] ) ? wp_unslash( $_POST['koopo_creator_content_types'] ) : array(),
                'primary_goal'       => isset( $_POST['koopo_creator_primary_goal'] ) ? wp_unslash( $_POST['koopo_creator_primary_goal'] ) : '',
                'affiliate_interest' => isset( $_POST['koopo_creator_affiliate_interest'] ),
                'experience'         => isset( $_POST['koopo_creator_experience'] ) ? wp_unslash( $_POST['koopo_creator_experience'] ) : '',
            ),
            false
        );
    }

    public static function status_payload( $user_id ) {
        $user    = get_userdata( $user_id );
        $profile = get_user_meta( $user_id, self::PROFILE_META, true );
        $profile = is_array( $profile ) ? $profile : array();
        $primary = $profile['primary_goal'] ?? 'articles';
        return array(
            'completed'       => '1' === get_user_meta( $user_id, self::COMPLETE_META, true ),
            'has_access'      => $user ? koopo_user_has_influencer_access( $user ) : false,
            'profile'         => $profile,
            'destination_url' => self::destination_url( $user_id, $primary ),
        );
    }

    public static function destination_url( $user_id, $primary ) {
        if ( 'videos' === $primary && class_exists( 'Koopo_Video_Frontend' ) ) {
            return Koopo_Video_Frontend::video_profile_url( $user_id );
        }
        if ( 'products' === $primary ) {
            if ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( $user_id ) ) {
                return home_url( '/seller-dashboard/products/' );
            }
            return home_url( '/new-seller/' );
        }
        return home_url( '/add-new-post/' );
    }

    public static function affiliate_url() {
        $page_id = (int) get_option( 'koopo_affiliates_page' );
        return $page_id && get_post( $page_id ) ? get_permalink( $page_id ) : home_url( '/affiliates/' );
    }

    public static function render_shortcode() {
        self::enqueue_assets();
        $logged_in = is_user_logged_in();
        $user      = $logged_in ? wp_get_current_user() : null;
        $complete  = $logged_in && '1' === get_user_meta( $user->ID, self::COMPLETE_META, true );
        $has_access= $logged_in && koopo_user_has_influencer_access( $user );
        $profile   = $logged_in ? get_user_meta( $user->ID, self::PROFILE_META, true ) : array();
        $profile   = is_array( $profile ) ? $profile : array();
        $primary   = $profile['primary_goal'] ?? 'articles';
        $dob       = $logged_in ? (string) get_user_meta( $user->ID, self::DOB_META, true ) : '';
        $type      = $logged_in ? (string) get_user_meta( $user->ID, '_koopo_account_type', true ) : '';
        if ( ! in_array( $type, array( 'personal', 'business' ), true ) && function_exists( 'bp_get_member_type' ) ) {
            $type = (string) bp_get_member_type( $user->ID, true );
        }
        if ( ! in_array( $type, array( 'personal', 'business' ), true ) ) {
            $type = 'personal';
        }
        $company = $logged_in ? (string) get_user_meta( $user->ID, '_koopo_company_name', true ) : '';
        $dialog_id = wp_unique_id( 'koopo-creator-dialog-' );
        ob_start();
        ?>
        <div class="koopo-creator-entry" data-koopo-creator-entry>
            <?php if ( $complete && $has_access ) : ?>
                <div class="koopo-creator-welcome"><span><?php esc_html_e( 'Welcome to the Square', 'koopo' ); ?></span><a class="button" href="<?php echo esc_url( self::destination_url( $user->ID, $primary ) ); ?>"><?php esc_html_e( 'Open creator tools', 'koopo' ); ?></a></div>
            <?php else : ?>
                <button type="button" class="button koopo-creator-entry__button" data-koopo-creator-open aria-haspopup="dialog" aria-controls="<?php echo esc_attr( $dialog_id ); ?>"><span aria-hidden="true">✦</span><?php echo $has_access ? esc_html__( 'Finish creator setup', 'koopo' ) : esc_html__( 'Join the Square', 'koopo' ); ?></button>
            <?php endif; ?>
            <div class="koopo-creator-modal" id="<?php echo esc_attr( $dialog_id ); ?>" data-koopo-creator-modal hidden>
                <div class="koopo-creator-modal__backdrop" data-koopo-creator-close></div>
                <section class="koopo-creator-modal__panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $dialog_id . '-title' ); ?>" tabindex="-1">
                    <button type="button" class="koopo-creator-modal__close" data-koopo-creator-close aria-label="<?php esc_attr_e( 'Close creator setup', 'koopo' ); ?>">&times;</button>
                    <aside class="koopo-creator-modal__rail"><a class="koopo-creator-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">Koopo <small><?php esc_html_e( 'Creator Studio', 'koopo' ); ?></small></a><div><span><?php esc_html_e( 'Make something worth following', 'koopo' ); ?></span><h2><?php esc_html_e( 'Your ideas. Your audience. Your next chapter.', 'koopo' ); ?></h2><p><?php esc_html_e( 'Publish, connect with subscribers, receive support, and build new revenue paths in one community.', 'koopo' ); ?></p></div></aside>
                    <div class="koopo-creator-modal__stage">
                    <?php if ( ! $logged_in ) : ?>
                        <div class="koopo-creator-anon">
                            <span class="koopo-creator-kicker"><?php esc_html_e( 'Creator onboarding', 'koopo' ); ?></span><h2 id="<?php echo esc_attr( $dialog_id . '-title' ); ?>"><?php esc_html_e( 'Start with your Koopo account.', 'koopo' ); ?></h2>
                            <p><?php esc_html_e( 'The creator signup includes your required account details, date of birth, personal or business profile, creator goals, and email verification.', 'koopo' ); ?></p>
                            <ul><li><?php esc_html_e( 'Choose articles, video, products—or all three', 'koopo' ); ?></li><li><?php esc_html_e( 'Learn how subscriber support and revenue sharing work', 'koopo' ); ?></li><li><?php esc_html_e( 'Land in the right creator workspace after activation', 'koopo' ); ?></li></ul>
                            <a class="koopo-creator-button" href="<?php echo esc_url( self::signup_url() ); ?>"><?php esc_html_e( 'Create my creator account', 'koopo' ); ?></a><p class="koopo-creator-login"><?php esc_html_e( 'Already have a Koopo account?', 'koopo' ); ?> <a href="<?php echo esc_url( wp_login_url( home_url( '/influencer-square/' ) ) ); ?>"><?php esc_html_e( 'Log in', 'koopo' ); ?></a></p>
                        </div>
                    <?php else : ?>
                        <form class="koopo-creator-form" data-koopo-creator-form novalidate>
                            <header class="koopo-creator-form__header"><span class="koopo-creator-kicker"><?php esc_html_e( 'Creator onboarding', 'koopo' ); ?></span><span data-koopo-creator-count><?php esc_html_e( 'Step 1 of 3', 'koopo' ); ?></span><i aria-hidden="true"><b data-koopo-creator-progress></b></i></header>
                            <section data-koopo-creator-panel>
                                <h2 id="<?php echo esc_attr( $dialog_id . '-title' ); ?>"><?php esc_html_e( 'First, confirm who you are.', 'koopo' ); ?></h2><p><?php esc_html_e( 'These details keep your creator profile connected to your verified Koopo account.', 'koopo' ); ?></p>
                                <label class="koopo-creator-field"><span><?php esc_html_e( 'Email', 'koopo' ); ?></span><input type="email" value="<?php echo esc_attr( $user->user_email ); ?>" readonly></label>
                                <div class="koopo-creator-fields"><label class="koopo-creator-field"><span><?php esc_html_e( 'First name', 'koopo' ); ?></span><input name="first_name" required autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>"></label><label class="koopo-creator-field"><span><?php esc_html_e( 'Last name', 'koopo' ); ?></span><input name="last_name" required autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>"></label></div>
                                <label class="koopo-creator-field"><span><?php esc_html_e( 'Date of birth', 'koopo' ); ?></span><input name="date_of_birth" type="date" <?php echo $dob ? 'disabled' : 'required'; ?> value="<?php echo esc_attr( $dob ); ?>"><small><?php echo $dob ? esc_html__( 'Your verified birth date is already saved and cannot be changed here.', 'koopo' ) : esc_html__( 'You must be at least 18 years old to create on Koopo.', 'koopo' ); ?></small></label>
                                <fieldset class="koopo-creator-account-type"><legend><?php esc_html_e( 'Account type', 'koopo' ); ?></legend><label><input type="radio" name="account_type" value="personal" <?php checked( $type, 'personal' ); ?>><span><?php esc_html_e( 'Personal', 'koopo' ); ?></span></label><label><input type="radio" name="account_type" value="business" <?php checked( $type, 'business' ); ?>><span><?php esc_html_e( 'Business', 'koopo' ); ?></span></label></fieldset>
                                <label class="koopo-creator-field" data-koopo-company-field <?php echo 'business' === $type ? '' : 'hidden'; ?>><span><?php esc_html_e( 'Business name', 'koopo' ); ?></span><input name="company_name" value="<?php echo esc_attr( $company ); ?>" autocomplete="organization"></label>
                            </section>
                            <section data-koopo-creator-panel hidden><h2><?php esc_html_e( 'Choose your creator paths.', 'koopo' ); ?></h2><p><?php esc_html_e( 'Select everything you want to make, then choose where you want to begin.', 'koopo' ); ?></p><?php self::render_goal_choices( array(), '' ); ?></section>
                            <section data-koopo-creator-panel hidden><h2><?php esc_html_e( 'Build more than a following.', 'koopo' ); ?></h2><p><?php esc_html_e( 'Subscribers can support your work, eligible creators can share in ad revenue, and every format gives you a new way to bring people back.', 'koopo' ); ?></p><label class="koopo-creator-affiliate-choice"><input type="checkbox" name="affiliate_interest" value="1"><span><strong><?php esc_html_e( 'Show me affiliate opportunities', 'koopo' ); ?></strong><small><?php esc_html_e( 'Learn how to recommend eligible products and services with clear disclosures and tracked attribution.', 'koopo' ); ?></small></span></label><a class="koopo-creator-learn" href="<?php echo esc_url( self::affiliate_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn about Koopo Affiliates', 'koopo' ); ?> ↗</a><label class="koopo-creator-field"><span><?php esc_html_e( 'What do you hope to create?', 'koopo' ); ?> <small><?php esc_html_e( 'Optional', 'koopo' ); ?></small></span><textarea name="experience" maxlength="500" rows="4" placeholder="<?php esc_attr_e( 'Tutorials, interviews, local stories, product reviews…', 'koopo' ); ?>"></textarea></label><div class="koopo-creator-status" data-koopo-creator-status role="status" aria-live="polite"></div></section>
                            <footer class="koopo-creator-form__actions"><button type="button" class="koopo-creator-back" data-koopo-creator-back hidden><?php esc_html_e( 'Back', 'koopo' ); ?></button><button type="button" class="koopo-creator-next" data-koopo-creator-next><?php esc_html_e( 'Continue', 'koopo' ); ?></button><button type="submit" class="koopo-creator-submit" data-koopo-creator-submit hidden><?php esc_html_e( 'Open my creator studio', 'koopo' ); ?></button></footer>
                        </form>
                    <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function set_xprofile_value( $user_id, $names, $value ) {
        if ( ! function_exists( 'xprofile_get_field_id_from_name' ) || ! function_exists( 'xprofile_set_field_data' ) ) {
            return;
        }
        foreach ( $names as $name ) {
            $field_id = (int) xprofile_get_field_id_from_name( $name );
            if ( $field_id ) {
                xprofile_set_field_data( $field_id, $user_id, $value );
                return;
            }
        }
    }

    private static function goal_options() {
        return array(
            'articles' => array(
                'title'       => __( 'Write articles', 'koopo' ),
                'description' => __( 'Publish ideas, guides, reporting, reviews, and stories in your own voice.', 'koopo' ),
                'benefit'     => __( 'Subscriber support · eligible ad-revenue sharing', 'koopo' ),
                'icon'        => '<svg viewBox="0 0 72 72" role="img"><path d="M18 12h29l9 9v39H18z"/><path d="M47 12v10h9M27 33h20M27 42h20M27 51h13"/></svg>',
            ),
            'videos' => array(
                'title'       => __( 'Create videos', 'koopo' ),
                'description' => __( 'Share shorts, how-tos, interviews, video podcasts, and original series.', 'koopo' ),
                'benefit'     => __( 'Your channel · memberships · creator support', 'koopo' ),
                'icon'        => '<svg viewBox="0 0 72 72" role="img"><rect x="11" y="17" width="50" height="38" rx="4"/><path d="m31 28 15 8-15 8z"/></svg>',
            ),
            'products' => array(
                'title'       => __( 'Sell products', 'koopo' ),
                'description' => __( 'Build a storefront for products that belong beside your content and community.', 'koopo' ),
                'benefit'     => __( 'Koopo storefront · creator-led commerce', 'koopo' ),
                'icon'        => '<svg viewBox="0 0 72 72" role="img"><path d="M15 26h42l-3 32H18zM25 28v-7a11 11 0 0 1 22 0v7"/></svg>',
            ),
        );
    }

    private static function svg_kses() {
        return array(
            'svg'  => array( 'viewbox' => true, 'role' => true ),
            'path' => array( 'd' => true ),
            'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ),
        );
    }
}
