<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-koopo-creator-support-service.php';
require_once __DIR__ . '/class-koopo-creator-support-rest.php';

class Koopo_Creator_Support {
    private static $instance = null;

    private $service;
    private $rest;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->service = new Koopo_Creator_Support_Service();
        $this->rest    = new Koopo_Creator_Support_REST( $this->service );

        $this->service->hooks();
        $this->rest->hooks();

        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
        add_action( 'bp_member_blog_before_posts', array( $this, 'render_buddyblog_donate_cta' ), 20, 1 );
    }

    public function service() {
        return $this->service;
    }

    public function register_shortcodes() {
        add_shortcode( 'koopo_creator_donate', array( $this, 'shortcode' ) );
        add_shortcode( 'creatorDonate', array( $this, 'shortcode' ) );
    }

    public function register_assets() {
        wp_register_style(
            'koopo-creator-support',
            plugins_url( 'assets/koopo-creator-support.css', __FILE__ ),
            array(),
            $this->asset_version( 'assets/koopo-creator-support.css' )
        );

        wp_register_script(
            'koopo-creator-support',
            plugins_url( 'assets/koopo-creator-support.js', __FILE__ ),
            array(),
            $this->asset_version( 'assets/koopo-creator-support.js' ),
            true
        );
    }

    public function shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'creator_id'        => 0,
                'context_post_id'   => 0,
                'context_post_type' => '',
                'module'            => 'general',
                'source'            => '',
                'surface'           => 'default',
                'label'             => __( 'Donate', 'koopo' ),
                'enable_label'      => __( 'Enable Donations', 'koopo' ),
                'title'             => '',
                'description'       => '',
                'preset_amounts'    => '5,10,25,50',
                'setup_url'         => '',
                'show_setup'        => '1',
                'variant'           => 'compact',
                'class_name'        => '',
                'show_copy'         => '',
            ),
            (array) $atts,
            'koopo_creator_donate'
        );

        return $this->render_support_block( $atts );
    }

    public function render_support_block( $args = array() ) {
        $args = $this->normalize_render_args( is_array( $args ) ? $args : array() );
        if ( empty( $args['creator_id'] ) ) {
            return '';
        }

        $creator = get_userdata( $args['creator_id'] );
        if ( ! $creator ) {
            return '';
        }

        $access_state = $this->service->creator_support_access_state( $args['creator_id'] );
        $viewer_id    = get_current_user_id();
        $is_owner     = $viewer_id && (int) $viewer_id === (int) $args['creator_id'];
        $can_donate   = ! $is_owner && ! empty( $access_state['can_accept'] ) && function_exists( 'wc_get_checkout_url' );
        $can_enable   = $is_owner && ! empty( $access_state['has_public_store'] ) && empty( $access_state['has_product'] ) && ! empty( $access_state['can_create_product'] );
        $show_setup   = $is_owner && ! $can_enable && empty( $access_state['can_accept'] ) && ! empty( $args['show_setup'] );

        if ( ! $can_donate && ! $can_enable && ! $show_setup ) {
            return '';
        }

        $this->enqueue_assets();

        $creator_name  = $this->service->creator_display_name( $args['creator_id'] );
        $modal_id      = wp_unique_id( 'koopo-creator-support-' );
        $setup_url     = '' !== $args['setup_url'] ? $args['setup_url'] : $this->default_setup_url( $args['creator_id'], $args['module'] );
        $status_text   = '';
        $support_note  = '';
        $classes       = trim( 'koopo-creator-support koopo-creator-support--' . $args['variant'] . ' ' . $args['class_name'] );
        $show_copy     = 'panel' === $args['variant'] || ! empty( $args['show_copy'] );
        $heading       = '' !== $args['title'] ? $args['title'] : sprintf( __( 'Support %s', 'koopo' ), $creator_name );
        $description   = '' !== $args['description'] ? $args['description'] : __( 'Send a one-time donation directly through Koopo checkout.', 'koopo' );
        $modal_heading = sprintf( __( 'Support %s', 'koopo' ), $creator_name );

        if ( $can_enable ) {
            $support_note = ! empty( $access_state['setup_message'] ) ? (string) $access_state['setup_message'] : __( 'Create a support product to start accepting donations.', 'koopo' );
        } elseif ( $show_setup ) {
            $support_note = ! empty( $access_state['message'] ) ? (string) $access_state['message'] : '';
        }

        ob_start();
        ?>
        <section
            class="<?php echo esc_attr( $classes ); ?>"
            data-koopo-creator-support
            data-creator-id="<?php echo esc_attr( $args['creator_id'] ); ?>"
            data-module="<?php echo esc_attr( $args['module'] ); ?>"
            data-surface="<?php echo esc_attr( $args['surface'] ); ?>"
            data-context-id="<?php echo esc_attr( $args['context_post_id'] ); ?>"
            data-context-type="<?php echo esc_attr( $args['context_post_type'] ); ?>"
        >
            <?php if ( $show_copy ) : ?>
                <div class="koopo-creator-support__copy">
                    <p class="koopo-creator-support__eyebrow"><?php esc_html_e( 'Creator Support', 'koopo' ); ?></p>
                    <h3><?php echo esc_html( $heading ); ?></h3>
                    <p><?php echo esc_html( $description ); ?></p>
                </div>
            <?php endif; ?>

            <div class="koopo-creator-support__actions">
                <?php if ( $can_donate ) : ?>
                    <button type="button" class="koopo-creator-support__button" data-kcs-open>
                        <span class="koopo-creator-support__button-icon" aria-hidden="true">$</span>
                        <span><?php echo esc_html( $args['label'] ); ?></span>
                    </button>
                <?php elseif ( $can_enable ) : ?>
                    <button type="button" class="koopo-creator-support__button koopo-creator-support__button--secondary" data-kcs-enable>
                        <span><?php echo esc_html( $args['enable_label'] ); ?></span>
                    </button>
                <?php endif; ?>

                <?php if ( '' !== $support_note ) : ?>
                    <p class="koopo-creator-support__note">
                        <?php echo esc_html( $support_note ); ?>
                        <?php if ( '' !== $setup_url ) : ?>
                            <a href="<?php echo esc_url( $setup_url ); ?>"><?php echo esc_html( $this->setup_link_label( $access_state ) ); ?></a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <p class="koopo-creator-support__status" data-kcs-status aria-live="polite"><?php echo esc_html( $status_text ); ?></p>
            </div>

            <?php if ( $can_donate ) : ?>
                <div class="koopo-creator-support__modal" data-kcs-modal hidden>
                    <div class="koopo-creator-support__modal-backdrop" data-kcs-close></div>
                    <div class="koopo-creator-support__modal-panel" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id ); ?>">
                        <button type="button" class="koopo-creator-support__modal-close" data-kcs-close aria-label="<?php esc_attr_e( 'Close', 'koopo' ); ?>">&times;</button>
                        <p class="koopo-creator-support__eyebrow"><?php esc_html_e( 'Support creator', 'koopo' ); ?></p>
                        <h3 id="<?php echo esc_attr( $modal_id ); ?>"><?php echo esc_html( $modal_heading ); ?></h3>
                        <p class="koopo-creator-support__modal-text"><?php esc_html_e( 'Choose any amount, then continue to checkout.', 'koopo' ); ?></p>

                        <form class="koopo-creator-support__form" data-kcs-form>
                            <div class="koopo-creator-support__preset-grid">
                                <?php foreach ( $args['preset_amounts'] as $preset_amount ) : ?>
                                    <button type="button" class="koopo-creator-support__preset" data-kcs-quick="<?php echo esc_attr( $preset_amount ); ?>">
                                        <?php echo esc_html( '$' . number_format_i18n( $preset_amount, 0 ) ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <label class="koopo-creator-support__field">
                                <span><?php esc_html_e( 'Donation amount', 'koopo' ); ?></span>
                                <input type="number" min="1" step="0.01" inputmode="decimal" placeholder="25.00" data-kcs-amount required />
                            </label>

                            <p class="koopo-creator-support__status" data-kcs-modal-status aria-live="polite"><?php esc_html_e( 'Choose any amount, then continue to checkout.', 'koopo' ); ?></p>

                            <div class="koopo-creator-support__modal-actions">
                                <button type="submit" class="koopo-creator-support__button" data-kcs-submit><?php esc_html_e( 'Continue to Checkout', 'koopo' ); ?></button>
                                <button type="button" class="koopo-creator-support__button koopo-creator-support__button--ghost" data-kcs-close><?php esc_html_e( 'Cancel', 'koopo' ); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function render_buddyblog_donate_cta( $status ) {
        if ( is_admin() || 'publish' !== (string) $status ) {
            return;
        }

        $creator_id = $this->resolve_member_blog_creator_id();
        if ( ! $creator_id ) {
            return;
        }

        $html = $this->render_support_block(
            array(
                'creator_id'        => $creator_id,
                'module'            => 'influencer_square',
                'surface'           => 'buddyblog_tab',
                'context_post_type' => 'buddyblog',
                'variant'           => 'panel',
                'title'             => __( 'Support This Writer', 'koopo' ),
                'description'       => __( 'Send a one-time donation directly from the author blog tab.', 'koopo' ),
                'class_name'        => 'koopo-creator-support--buddyblog',
            )
        );

        if ( '' === $html ) {
            return;
        }

        echo '<div class="koopo-creator-support__mount koopo-creator-support__mount--buddyblog">' . $html . '</div>';
    }

    public function enqueue_assets() {
        if ( is_admin() ) {
            return;
        }

        $this->register_assets();

        wp_enqueue_style( 'koopo-creator-support' );
        wp_enqueue_script( 'koopo-creator-support' );
        wp_localize_script(
            'koopo-creator-support',
            'KoopoCreatorSupport',
            array(
                'restBase'  => esc_url_raw( rest_url( 'koopo/v1/creator-support' ) ),
                'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
                'messages'  => array(
                    'invalidAmount'    => __( 'Please enter a valid donation amount.', 'koopo' ),
                    'preparing'        => __( 'Preparing checkout...', 'koopo' ),
                    'redirecting'      => __( 'Redirecting to checkout...', 'koopo' ),
                    'requestFailed'    => __( 'Something went wrong. Please try again.', 'koopo' ),
                    'creatingProduct'  => __( 'Enabling donations...', 'koopo' ),
                    'productEnabled'   => __( 'Donations are enabled. Supporters can now donate.', 'koopo' ),
                ),
            )
        );
    }

    private function asset_version( $asset_path ) {
        $file = __DIR__ . '/' . ltrim( $asset_path, '/' );
        return file_exists( $file ) ? (string) filemtime( $file ) : '1.0.0';
    }

    private function resolve_member_blog_creator_id() {
        if ( class_exists( 'Member_Blog_Compat' ) && method_exists( 'Member_Blog_Compat', 'get_displayed_user_id' ) ) {
            $creator_id = absint( (int) Member_Blog_Compat::get_displayed_user_id() );
            if ( $creator_id ) {
                return $creator_id;
            }
        }

        if ( function_exists( 'bp_displayed_user_id' ) ) {
            $creator_id = absint( (int) bp_displayed_user_id() );
            if ( $creator_id ) {
                return $creator_id;
            }
        }

        foreach ( array( 'member_blog_user', 'user_id', 'author' ) as $query_var ) {
            $creator_id = absint( (int) get_query_var( $query_var ) );
            if ( $creator_id ) {
                return $creator_id;
            }
        }

        return 0;
    }

    private function normalize_render_args( array $args ) {
        $module = ! empty( $args['source'] ) && empty( $args['module'] ) ? (string) $args['source'] : (string) $args['module'];

        $context_post_id   = $this->resolve_context_post_id( isset( $args['context_post_id'] ) ? $args['context_post_id'] : 0 );
        $context_post_type = sanitize_key( (string) ( isset( $args['context_post_type'] ) ? $args['context_post_type'] : '' ) );
        if ( ! $context_post_type && $context_post_id ) {
            $context_post_type = sanitize_key( (string) get_post_type( $context_post_id ) );
        }

        $creator_id = $this->resolve_creator_id( isset( $args['creator_id'] ) ? $args['creator_id'] : 0, $context_post_id );

        $variant = sanitize_key( (string) ( isset( $args['variant'] ) ? $args['variant'] : 'compact' ) );
        if ( ! in_array( $variant, array( 'compact', 'panel' ), true ) ) {
            $variant = 'compact';
        }

        $show_copy = isset( $args['show_copy'] ) && '' !== (string) $args['show_copy']
            ? $this->normalize_bool( $args['show_copy'] )
            : ( 'panel' === $variant );

        return array(
            'creator_id'        => $creator_id,
            'context_post_id'   => $context_post_id,
            'context_post_type' => $context_post_type,
            'module'            => '' !== $module ? sanitize_key( $module ) : 'general',
            'surface'           => ! empty( $args['surface'] ) ? sanitize_key( (string) $args['surface'] ) : 'default',
            'label'             => ! empty( $args['label'] ) ? (string) $args['label'] : __( 'Donate', 'koopo' ),
            'enable_label'      => ! empty( $args['enable_label'] ) ? (string) $args['enable_label'] : __( 'Enable Donations', 'koopo' ),
            'title'             => ! empty( $args['title'] ) ? (string) $args['title'] : '',
            'description'       => ! empty( $args['description'] ) ? (string) $args['description'] : '',
            'preset_amounts'    => $this->parse_preset_amounts( isset( $args['preset_amounts'] ) ? $args['preset_amounts'] : '5,10,25,50' ),
            'setup_url'         => ! empty( $args['setup_url'] ) ? esc_url_raw( (string) $args['setup_url'] ) : '',
            'show_setup'        => $this->normalize_bool( isset( $args['show_setup'] ) ? $args['show_setup'] : true ),
            'variant'           => $variant,
            'class_name'        => $this->sanitize_class_names( isset( $args['class_name'] ) ? (string) $args['class_name'] : '' ),
            'show_copy'         => $show_copy,
        );
    }

    private function parse_preset_amounts( $raw ) {
        $values = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
        $parsed = array();

        foreach ( $values as $value ) {
            $amount = round( max( 0, (float) trim( (string) $value ) ), 2 );
            if ( $amount <= 0 ) {
                continue;
            }

            $parsed[] = $amount;
            if ( count( $parsed ) >= 6 ) {
                break;
            }
        }

        if ( empty( $parsed ) ) {
            $parsed = array( 5, 10, 25, 50 );
        }

        return array_values( array_unique( $parsed ) );
    }

    private function resolve_context_post_id( $value ) {
        $post_id = absint( (int) $value );
        if ( $post_id ) {
            return $post_id;
        }

        if ( is_singular() ) {
            return absint( (int) get_queried_object_id() );
        }

        return 0;
    }

    private function resolve_creator_id( $value, $context_post_id ) {
        $creator_id = absint( (int) $value );
        if ( $creator_id ) {
            return $creator_id;
        }

        if ( $context_post_id ) {
            return absint( (int) get_post_field( 'post_author', $context_post_id ) );
        }

        if ( function_exists( 'bp_displayed_user_id' ) ) {
            $displayed_user_id = absint( (int) bp_displayed_user_id() );
            if ( $displayed_user_id ) {
                return $displayed_user_id;
            }
        }

        if ( is_author() ) {
            $author = get_queried_object();
            if ( $author && ! empty( $author->ID ) ) {
                return absint( (int) $author->ID );
            }
        }

        return 0;
    }

    private function default_setup_url( $creator_id, $module ) {
        unset( $module );

        $creator_id = absint( (int) $creator_id );
        if ( ! $creator_id || get_current_user_id() !== $creator_id ) {
            return '';
        }

        if ( class_exists( 'Koopo_Video_Frontend_Profile' ) && method_exists( 'Koopo_Video_Frontend_Profile', 'video_profile_tab_url' ) ) {
            return (string) Koopo_Video_Frontend_Profile::video_profile_tab_url( $creator_id, 'memberships' );
        }

        if ( function_exists( 'bp_loggedin_user_domain' ) && function_exists( 'bp_get_settings_slug' ) ) {
            return trailingslashit( bp_loggedin_user_domain() . bp_get_settings_slug() . '/koopo-memberships' );
        }

        return '';
    }

    private function normalize_bool( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
    }

    private function sanitize_class_names( $classes ) {
        $class_names = preg_split( '/\s+/', trim( (string) $classes ) );
        if ( ! is_array( $class_names ) ) {
            return '';
        }

        $class_names = array_filter( array_map( 'sanitize_html_class', $class_names ) );

        return implode( ' ', array_unique( $class_names ) );
    }

    private function setup_link_label( array $access_state ) {
        if ( ! empty( $access_state['has_store_permissions'] ) ) {
            return __( 'Open memberships', 'koopo' );
        }

        return __( 'Activate selling', 'koopo' );
    }
}
