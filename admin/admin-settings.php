<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared parent slug for Koopo admin menus.
 * Other Koopo plugins can use this slug with add_submenu_page().
 */
function koopo_admin_parent_slug() {
    return 'koopo';
}

add_action( 'admin_menu', 'koopo_register_admin_menu' );
function koopo_register_admin_menu() {
    $parent_slug = koopo_admin_parent_slug();
    $capability  = 'manage_options';
    $icon_url    = plugins_url( 'assets/icons/koopo-share-one.png', dirname( __DIR__ ) . '/koopo.php' );

    add_menu_page(
        __( 'Koopo', 'koopo' ),
        __( 'Koopo', 'koopo' ),
        $capability,
        $parent_slug,
        'koopo_admin_overview_page',
        $icon_url,
        4
    );

    add_submenu_page(
        $parent_slug,
        __( 'Overview', 'koopo' ),
        __( 'Overview', 'koopo' ),
        $capability,
        $parent_slug,
        'koopo_admin_overview_page'
    );

    add_submenu_page(
        $parent_slug,
        __( 'Woo Category Image Fallback', 'koopo' ),
        __( 'Category Fallback', 'koopo' ),
        $capability,
        'koopo-cat-fallback-settings',
        'koopo_cat_fallback_settings_page'
    );

    /**
     * Allow other Koopo plugins to register submenu pages under "Koopo".
     *
     * Example:
     * add_action( 'koopo_admin_register_submenus', function( $parent_slug, $capability ) {
     *     add_submenu_page( $parent_slug, 'My Settings', 'My Settings', $capability, 'my-koopo-page', 'my_callback' );
     * }, 10, 2 );
     */
    do_action( 'koopo_admin_register_submenus', $parent_slug, $capability );
}

add_action( 'admin_head', 'koopo_admin_menu_icon_style' );
function koopo_admin_menu_icon_style() {
    ?>
    <style>
        #toplevel_page_koopo .wp-menu-image img {
            max-width: 100%;
            padding: 0;
        }
    </style>
    <?php
}

add_action( 'admin_init', 'koopo_register_admin_settings' );
function koopo_register_admin_settings() {
    register_setting( 'koopo_cat_fallback_group', 'koopo_default_cat_image' );
    register_setting(
        'koopo_cat_fallback_group',
        'koopo_vendor_starter_pack_id',
        array(
            'sanitize_callback' => 'absint',
        )
    );
}

function koopo_admin_overview_page() {
    global $submenu;

    $parent_slug = koopo_admin_parent_slug();
    $pages       = isset( $submenu[ $parent_slug ] ) ? (array) $submenu[ $parent_slug ] : array();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Koopo', 'koopo' ); ?></h1>
        <p><?php esc_html_e( 'Manage Koopo settings from this menu. Additional Koopo plugins can register their own pages here.', 'koopo' ); ?></p>

        <?php if ( ! empty( $pages ) ) : ?>
            <ul>
                <?php foreach ( $pages as $page ) : ?>
                    <?php
                    if ( empty( $page[2] ) || $page[2] === $parent_slug ) {
                        continue;
                    }

                    $url = admin_url( 'admin.php?page=' . rawurlencode( $page[2] ) );
                    ?>
                    <li>
                        <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( wp_strip_all_tags( $page[0] ) ); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}

function koopo_cat_fallback_settings_page() {
    ?>
    <div class="wrap">
        <h1>Woo Category Image Fallback</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'koopo_cat_fallback_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Default Fallback Image URL</th>
                    <td>
                        <input type="text" id="koopo_default_cat_image" name="koopo_default_cat_image" value="<?php echo esc_attr( get_option( 'koopo_default_cat_image' ) ); ?>" style="width: 60%;" />
                        <input type="button" class="button" id="upload_default_image" value="Upload Image" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Vendor Starter Pack ID</th>
                    <td>
                        <input type="number" min="0" id="koopo_vendor_starter_pack_id" name="koopo_vendor_starter_pack_id" value="<?php echo esc_attr( get_option( 'koopo_vendor_starter_pack_id', 0 ) ); ?>" style="width: 120px;" />
                        <p class="description">Dokan product pack ID to auto-assign to new vendors. Leave as 0 to auto-detect the first free product pack.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($){
        let mediaUploader;
        $('#upload_default_image').click(function(e) {
            e.preventDefault();
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
            mediaUploader = wp.media({
                title: 'Select Default Image',
                button: { text: 'Use This Image' },
                multiple: false
            });
            mediaUploader.on('select', function() {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#koopo_default_cat_image').val(attachment.url);
            });
            mediaUploader.open();
        });
    });
    </script>
    <?php
}
