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
        add_filter( 'template_include', array( $this, 'maybe_use_shared_template' ), 99 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_heart_above_title' ), 4 );
    }

    public function enqueue_assets() {
        if ( ! $this->should_enqueue_assets() ) {
            return;
        }

        $style_path    = __DIR__ . '/assets/koopo-favorites.css';
        $script_path   = __DIR__ . '/assets/koopo-favorites.js';
        $style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : '1.0.0';
        $script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0';

        wp_enqueue_style(
            'koopo-favorites',
            plugins_url( 'assets/koopo-favorites.css', __FILE__ ),
            array(),
            $style_version
        );

        wp_enqueue_script(
            'koopo-favorites',
            plugins_url( 'assets/koopo-favorites.js', __FILE__ ),
            array(),
            $script_version,
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
                    'copyPrompt'            => __( 'Copy this item to which list?', 'koopo' ),
                    'movePrompt'            => __( 'Move this item to which list?', 'koopo' ),
                    'error'                 => __( 'Something went wrong. Please try again.', 'koopo' ),
                    'copySuccess'           => __( 'Share link copied.', 'koopo' ),
                    'loginRequired'         => __( 'Please log in to manage favorites.', 'koopo' ),
                    'noLists'               => __( 'No lists yet. Create your first list below.', 'koopo' ),
                    'noItems'               => __( 'No items in this list yet.', 'koopo' ),
                    'selectedLabel'         => __( 'selected', 'koopo' ),
                    'copyItemLabel'         => __( 'Copy', 'koopo' ),
                    'moveItemLabel'         => __( 'Move', 'koopo' ),
                    'removeItemLabel'       => __( 'Remove', 'koopo' ),
                    'bulkCopyLabel'         => __( 'Copy Selected', 'koopo' ),
                    'bulkMoveLabel'         => __( 'Move Selected', 'koopo' ),
                    'bulkRemoveLabel'       => __( 'Remove Selected', 'koopo' ),
                    'selectItemLabel'       => __( 'Select', 'koopo' ),
                    'selectAllLabel'        => __( 'Select All', 'koopo' ),
                    'clearSelectionLabel'   => __( 'Clear Selection', 'koopo' ),
                    'selectListLabel'       => __( 'Add to an existing list', 'koopo' ),
                    'selectListPlaceholder' => __( 'Select a list', 'koopo' ),
                    'createNewListLabel'    => __( 'Or create a new list', 'koopo' ),
                    'transferHint'          => __( 'Choose an existing list or enter a new name.', 'koopo' ),
                    'copyToListTitle'       => __( 'Copy to Another List', 'koopo' ),
                    'moveToListTitle'       => __( 'Move to Another List', 'koopo' ),
                    'copyItemsButton'       => __( 'Copy Items', 'koopo' ),
                    'moveItemsButton'       => __( 'Move Items', 'koopo' ),
                    'transferTargetRequired'=> __( 'Select an existing list or enter a new list name.', 'koopo' ),
                    'openSharedLabel'       => __( 'Open Shared List', 'koopo' ),
                    'sharedByLabel'         => __( 'Shared by', 'koopo' ),
                    'copySharedLabel'       => __( 'Copy As New List', 'koopo' ),
                    'copySharedSuccess'     => __( 'Shared list copied to your favorites.', 'koopo' ),
                    'pickerTitle'           => __( 'Save To Favorites', 'koopo' ),
                    'saveButton'            => __( 'Save', 'koopo' ),
                    'cancelButton'          => __( 'Cancel', 'koopo' ),
                    'favoritesLabel'        => __( 'Favorites', 'koopo' ),
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

    public function maybe_use_shared_template( $template ) {
        if ( is_admin() || ! isset( $_GET['koopo_favorites_share'] ) ) {
            return $template;
        }

        $shared_template = __DIR__ . '/templates/shared-list-template.php';

        if ( file_exists( $shared_template ) ) {
            return $shared_template;
        }

        return $template;
    }

    public function render_favorite_button_shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'post_id'         => 0,
                'label'           => '',
                'class'           => '',
                'disable_modal'   => '',
                'list'            => '',
                'icon'            => 'heart',
                'icon_color'      => '',
                'icon_background' => '',
                'icon_padding'    => '',
                'icon_size'       => '',
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

        $label            = $atts['label'] ? sanitize_text_field( $atts['label'] ) : __( 'Favorites', 'koopo' );
        $extra_class      = $atts['class'] ? sanitize_html_class( $atts['class'] ) : '';
        $disable_modal    = $this->is_truthy_attribute( $atts['disable_modal'] );
        $target_list_name = $disable_modal ? sanitize_text_field( (string) $atts['list'] ) : '';
        $behavior         = $disable_modal ? 'direct' : 'picker';
        $icon             = $this->sanitize_icon_type( $atts['icon'] );
        $styles           = array();

        if ( '' !== $atts['icon_color'] ) {
            $styles[] = '--koopo-favorite-color:' . $this->sanitize_css_value( $atts['icon_color'] );
        }
        if ( '' !== $atts['icon_background'] ) {
            $styles[] = '--koopo-favorite-background:' . $this->sanitize_css_value( $atts['icon_background'] );
        }
        if ( '' !== $atts['icon_padding'] ) {
            $styles[] = '--koopo-favorite-padding:' . $this->sanitize_css_value( $atts['icon_padding'] );
        }
        if ( '' !== $atts['icon_size'] ) {
            $styles[] = '--koopo-favorite-size:' . $this->sanitize_css_value( $atts['icon_size'] );
        }

        ob_start();
        ?>
        <span
            role="button"
            tabindex="0"
            class="koopo-favorite-heart koopo-favorite-heart--<?php echo esc_attr( $icon ); ?> <?php echo esc_attr( $extra_class ); ?>"
            data-post-id="<?php echo esc_attr( $post_id ); ?>"
            data-icon="<?php echo esc_attr( $icon ); ?>"
            data-behavior="<?php echo esc_attr( $behavior ); ?>"
            <?php if ( $disable_modal && '' === $target_list_name ) : ?>
                data-target-list-id="<?php echo esc_attr( Koopo_Favorites_Service::DEFAULT_LIST_ID ); ?>"
            <?php endif; ?>
            <?php if ( $disable_modal && '' !== $target_list_name ) : ?>
                data-target-list-name="<?php echo esc_attr( $target_list_name ); ?>"
            <?php endif; ?>
            aria-pressed="false"
            aria-label="<?php echo esc_attr( $label ); ?>"
            title="<?php echo esc_attr( $label ); ?>"
            <?php if ( ! empty( $styles ) ) : ?>
                style="<?php echo esc_attr( implode( ';', $styles ) ); ?>"
            <?php endif; ?>
        >
            <span class="koopo-favorite-heart__icon" aria-hidden="true"><?php echo $this->get_icon_markup( $icon ); ?></span>
            <span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
        </span>
        <?php
        return ob_get_clean();
    }

    public function inject_heart_button( $content ) {
        if ( ! $this->service->is_auto_display_enabled() ) {
            return $content;
        }

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
        if ( ! $this->service->is_auto_display_enabled() ) {
            return;
        }

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

    private function sanitize_icon_type( $icon ) {
        $icon = sanitize_key( (string) $icon );
        return in_array( $icon, array( 'heart', 'bookmark' ), true ) ? $icon : 'heart';
    }

    private function is_truthy_attribute( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }

    private function sanitize_css_value( $value ) {
        return preg_replace( '/[^#%(),.\\s\\-\\/0-9a-zA-Z]/', '', (string) $value );
    }

    private function get_icon_markup( $icon ) {
        if ( 'bookmark' === $icon ) {
            return '<svg viewBox="0 0 24 24" focusable="false"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1Z" fill="currentColor"/></svg>';
        }

        return '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 21.35 10.55 20C5.4 15.24 2 12.09 2 8.25A5.25 5.25 0 0 1 7.25 3c1.8 0 3.53.84 4.75 2.17A6.35 6.35 0 0 1 16.75 3 5.25 5.25 0 0 1 22 8.25c0 3.84-3.4 6.99-8.55 11.76L12 21.35Z" fill="currentColor"/></svg>';
    }

    private function get_current_url() {
        if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
            return home_url( '/' );
        }

        $scheme = is_ssl() ? 'https://' : 'http://';
        return esc_url_raw( $scheme . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] ) );
    }
}
