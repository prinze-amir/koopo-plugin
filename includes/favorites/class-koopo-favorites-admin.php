<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Favorites_Admin {
    private $service;

    public function __construct( Koopo_Favorites_Service $service ) {
        $this->service = $service;
    }

    public function hooks() {
        add_action( 'koopo_admin_register_submenus', array( $this, 'register_submenu' ), 20, 2 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_submenu( $parent_slug, $capability ) {
        add_submenu_page(
            $parent_slug,
            __( 'Favorites', 'koopo' ),
            __( 'Favorites', 'koopo' ),
            $capability,
            'koopo-favorites',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'koopo_favorites_settings_group',
            Koopo_Favorites_Service::OPTION_ENABLED_POST_TYPES,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_post_types' ),
                'default'           => $this->service->get_default_enabled_post_types(),
            )
        );
    }

    public function sanitize_post_types( $value ) {
        $all = get_post_types( array( 'public' => true ), 'names' );
        $all = array_map( 'sanitize_key', array_values( (array) $all ) );

        $types = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $value ) ) ) );
        $types = array_values( array_intersect( $types, $all ) );

        if ( empty( $types ) ) {
            $types = array( 'post' );
        }

        return $types;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $all_post_types = get_post_types( array( 'public' => true ), 'objects' );
        $enabled        = $this->service->get_enabled_post_types();

        ?>
        <div class="wrap koopo-favorites-admin">
            <h1><?php esc_html_e( 'Favorites Settings', 'koopo' ); ?></h1>
            <p><?php esc_html_e( 'Enable favorites for selected post types. Users will be able to create lists and save items with the heart button.', 'koopo' ); ?></p>

            <style>
                .koopo-favorites-admin .koopo-fav-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); max-width: 900px; margin: 18px 0; }
                .koopo-favorites-admin .koopo-fav-card { border: 1px solid #dcdcde; background: #fff; border-radius: 8px; padding: 10px 12px; }
                .koopo-favorites-admin .koopo-fav-card label { display: flex; align-items: center; gap: 8px; }
                .koopo-favorites-admin .koopo-fav-note { color: #50575e; margin-top: 12px; }
                .koopo-favorites-admin code { background: #f0f0f1; padding: 2px 6px; border-radius: 4px; }
            </style>

            <form method="post" action="options.php">
                <?php settings_fields( 'koopo_favorites_settings_group' ); ?>
                <input type="hidden" name="<?php echo esc_attr( Koopo_Favorites_Service::OPTION_ENABLED_POST_TYPES ); ?>[]" value="" />

                <h2><?php esc_html_e( 'Enabled Post Types', 'koopo' ); ?></h2>
                <div class="koopo-fav-grid">
                    <?php foreach ( $all_post_types as $post_type ) : ?>
                        <?php if ( 'attachment' === $post_type->name ) : ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="koopo-fav-card">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( Koopo_Favorites_Service::OPTION_ENABLED_POST_TYPES ); ?>[]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $enabled, true ) ); ?> />
                                <span>
                                    <strong><?php echo esc_html( $post_type->labels->singular_name ); ?></strong>
                                    <small>(<?php echo esc_html( $post_type->name ); ?>)</small>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php submit_button( __( 'Save Favorites Settings', 'koopo' ) ); ?>
            </form>

            <p class="koopo-fav-note">
                <?php esc_html_e( 'Shortcodes:', 'koopo' ); ?>
                <code>[koopo_favorites]</code>
                <code>[koopo_favorite_button]</code>
                <code>[koopo_favorites_shared]</code>
            </p>
        </div>
        <?php
    }
}
