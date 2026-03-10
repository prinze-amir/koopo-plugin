<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-koopo-favorites-service.php';
require_once __DIR__ . '/class-koopo-favorites-rest.php';
require_once __DIR__ . '/class-koopo-favorites-admin.php';

class Koopo_Favorites {
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

    private function __construct() {
        $this->service = new Koopo_Favorites_Service();
        $this->rest    = new Koopo_Favorites_REST( $this->service );
        $this->admin   = new Koopo_Favorites_Admin( $this->service );

        $this->rest->hooks();
        $this->admin->hooks();

        add_shortcode( 'koopo_favorites', array( $this, 'render_dashboard_shortcode' ) );
        add_shortcode( 'koopo_favorite_button', array( $this, 'render_favorite_button_shortcode' ) );
        add_shortcode( 'koopo_favorites_shared', array( $this, 'render_shared_shortcode' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'the_content', array( $this, 'inject_heart_button' ), 18 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_heart_above_title' ), 4 );
    }

    public function enqueue_assets() {
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }

        wp_enqueue_style(
            'koopo-favorites',
            plugins_url( 'assets/koopo-favorites.css', __FILE__ ),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'koopo-favorites',
            plugins_url( 'assets/koopo-favorites.js', __FILE__ ),
            array(),
            '1.0.0',
            true
        );

        wp_localize_script(
            'koopo-favorites',
            'KoopoFavoritesData',
            array(
                'restBase'     => esc_url_raw( rest_url( 'koopo/v1/favorites' ) ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'isLoggedIn'   => is_user_logged_in(),
                'loginUrl'     => wp_login_url( $this->get_current_url() ),
                'currentPostId'=> is_singular() ? (int) get_queried_object_id() : 0,
                'i18n'         => array(
                    'createListPlaceholder' => __( 'New list name', 'koopo' ),
                    'createListButton'      => __( 'Create List', 'koopo' ),
                    'deleteListConfirm'     => __( 'Delete this list?', 'koopo' ),
                    'removeItemConfirm'     => __( 'Remove this item from the list?', 'koopo' ),
                    'renamePrompt'          => __( 'Enter new list name', 'koopo' ),
                    'error'                 => __( 'Something went wrong. Please try again.', 'koopo' ),
                    'copySuccess'           => __( 'Share link copied.', 'koopo' ),
                    'loginRequired'         => __( 'Please log in to manage favorites.', 'koopo' ),
                    'noLists'               => __( 'No lists yet. Create your first list below.', 'koopo' ),
                    'noItems'               => __( 'No items in this list yet.', 'koopo' ),
                    'pickerTitle'           => __( 'Save To Favorites', 'koopo' ),
                    'saveButton'            => __( 'Save', 'koopo' ),
                    'cancelButton'          => __( 'Cancel', 'koopo' ),
                    'favoritesLabel'        => __( 'Favorites', 'koopo' ),
                    'publishSuccess'        => __( 'List has been posted.', 'koopo' ),
                ),
            )
        );
    }

    public function render_dashboard_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<div class="koopo-favorites-login"><p>' . esc_html__( 'Please log in to manage favorites.', 'koopo' ) . '</p></div>';
        }

        ob_start();
        ?>
        <section class="koopo-favorites-app" data-view="dashboard">
            <header class="koopo-favorites-header">
                <h2><?php esc_html_e( 'My Favorites', 'koopo' ); ?></h2>
                <p><?php esc_html_e( 'Create custom lists, save content, and share your collections.', 'koopo' ); ?></p>
            </header>

            <form class="koopo-favorites-create-list" data-role="create-list-form">
                <input type="text" name="name" maxlength="100" placeholder="<?php echo esc_attr__( 'Create a new list...', 'koopo' ); ?>" required />
                <button type="submit" class="button"><?php esc_html_e( 'Create List', 'koopo' ); ?></button>
            </form>

            <div class="koopo-favorites-lists" data-role="lists"></div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function render_shared_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'slug' => '',
            ),
            (array) $atts,
            'koopo_favorites_shared'
        );

        $slug = $atts['slug'] ? sanitize_title( $atts['slug'] ) : '';
        if ( '' === $slug && isset( $_GET['koopo_favorites_share'] ) ) {
            $slug = sanitize_title( wp_unslash( $_GET['koopo_favorites_share'] ) );
        }

        if ( '' === $slug ) {
            return '<div class="koopo-favorites-empty"><p>' . esc_html__( 'Shared list not found.', 'koopo' ) . '</p></div>';
        }

        ob_start();
        ?>
        <section class="koopo-favorites-app" data-view="shared" data-share-slug="<?php echo esc_attr( $slug ); ?>">
            <header class="koopo-favorites-header">
                <h2><?php esc_html_e( 'Shared Favorites', 'koopo' ); ?></h2>
            </header>
            <div class="koopo-favorites-shared" data-role="shared-list"></div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function render_favorite_button_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'post_id' => 0,
                'label'   => '',
                'class'   => '',
            ),
            (array) $atts,
            'koopo_favorite_button'
        );

        $post_id = absint( $atts['post_id'] );
        if ( ! $post_id ) {
            $post_id = get_the_ID() ? absint( get_the_ID() ) : 0;
        }

        if ( ! $post_id || ! $this->service->is_post_favoritable( $post_id ) ) {
            return '';
        }

        $label = $atts['label'] ? sanitize_text_field( $atts['label'] ) : __( 'Favorites', 'koopo' );
        $extra_class = $atts['class'] ? sanitize_html_class( $atts['class'] ) : '';

        ob_start();
        ?>
        <button type="button" class="koopo-favorite-heart <?php echo esc_attr( $extra_class ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>" aria-pressed="false" aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>">
            <span class="koopo-favorite-heart__icon" aria-hidden="true">❤</span>
            <span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
        </button>
        <?php
        return ob_get_clean();
    }

    public function inject_heart_button( $content ) {
        if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        if ( ! is_singular( $this->service->get_enabled_post_types() ) ) {
            return $content;
        }

        if ( function_exists( 'is_product' ) && is_product() ) {
            return $content;
        }

        $post_id = get_the_ID();
        if ( ! $post_id || ! $this->service->is_post_favoritable( $post_id ) ) {
            return $content;
        }

        if ( false !== strpos( $content, 'koopo-favorite-heart' ) ) {
            return $content;
        }

        $button = $this->render_favorite_button_shortcode( array( 'post_id' => $post_id ) );

        return '<div class="koopo-favorite-heart-wrap">' . $button . '</div>' . $content;
    }

    public function render_product_heart_above_title() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id || ! $this->service->is_post_favoritable( $post_id ) ) {
            return;
        }

        echo '<div class="koopo-favorite-heart-wrap koopo-favorite-heart-wrap--product">';
        echo $this->render_favorite_button_shortcode(
            array(
                'post_id' => $post_id,
                'class'   => 'koopo-favorite-heart--product',
            )
        );
        echo '</div>';
    }

    private function should_enqueue_assets() {
        if ( is_admin() ) {
            return false;
        }

        if ( is_singular( $this->service->get_enabled_post_types() ) ) {
            return true;
        }

        global $post;
        if ( $post instanceof WP_Post ) {
            if ( has_shortcode( $post->post_content, 'koopo_favorites' ) ) {
                return true;
            }
            if ( has_shortcode( $post->post_content, 'koopo_favorites_shared' ) ) {
                return true;
            }
            if ( has_shortcode( $post->post_content, 'koopo_favorite_button' ) ) {
                return true;
            }
        }

        return isset( $_GET['koopo_favorites_share'] );
    }

    private function get_current_url() {
        if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
            return home_url( '/' );
        }

        $scheme = is_ssl() ? 'https://' : 'http://';
        return esc_url_raw( $scheme . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] ) );
    }
}
