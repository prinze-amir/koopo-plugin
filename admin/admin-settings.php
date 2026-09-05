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

    add_submenu_page(
        $parent_slug,
        __( 'Profile Name Display', 'koopo' ),
        __( 'Profile Name Display', 'koopo' ),
        $capability,
        'koopo-profile-name-display',
        'koopo_profile_name_display_settings_page'
    );

    add_submenu_page(
        $parent_slug,
        __( 'Social Features', 'koopo' ),
        __( 'Social Features', 'koopo' ),
        $capability,
        'koopo-social-features',
        'koopo_social_features_settings_page'
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

    register_setting(
        'koopo_profile_name_display_group',
        'koopo_profile_display_name_field_id',
        array(
            'sanitize_callback' => 'absint',
        )
    );

    register_setting(
        'koopo_profile_name_display_group',
        'koopo_company_name_field_id',
        array(
            'sanitize_callback' => 'absint',
        )
    );

    register_setting(
        'koopo_profile_name_display_group',
        'koopo_alias_name_field_id',
        array(
            'sanitize_callback' => 'absint',
        )
    );

    register_setting(
        'koopo_social_features_group',
        Koopo_BuddyBoss_Poll_Permissions::OPTION_ALLOW_ALL_MEMBERS,
        array(
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => array( 'Koopo_BuddyBoss_Poll_Permissions', 'sanitize_enabled' ),
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

function koopo_profile_name_display_settings_page() {
    $display_choice_field_id = absint( get_option( 'koopo_profile_display_name_field_id', 0 ) );
    $company_name_field_id   = absint( get_option( 'koopo_company_name_field_id', 0 ) );
    $alias_name_field_id     = absint( get_option( 'koopo_alias_name_field_id', 0 ) );
    $profile_fields          = koopo_get_xprofile_field_options();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Profile Name Display', 'koopo' ); ?></h1>
        <p><?php esc_html_e( 'Choose which BuddyBoss profile fields control user display names across profiles, member cards, search, and API responses.', 'koopo' ); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'koopo_profile_name_display_group' ); ?>
            <table class="form-table" role="presentation">
                <tr valign="top">
                    <th scope="row">
                        <label for="koopo_profile_display_name_field_id"><?php esc_html_e( 'Display-name choice field', 'koopo' ); ?></label>
                    </th>
                    <td>
                        <?php koopo_render_xprofile_field_select( 'koopo_profile_display_name_field_id', $display_choice_field_id, $profile_fields ); ?>
                        <p class="description">
                            <?php esc_html_e( 'Select the radio/dropdown xProfile field with labels such as First and Last Name, First Name, Username, Company Name, and Nickname.', 'koopo' ); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="koopo_company_name_field_id"><?php esc_html_e( 'Company name field', 'koopo' ); ?></label>
                    </th>
                    <td>
                        <?php koopo_render_xprofile_field_select( 'koopo_company_name_field_id', $company_name_field_id, $profile_fields ); ?>
                        <p class="description">
                            <?php esc_html_e( 'Select the xProfile field that stores the public company or business name.', 'koopo' ); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="koopo_alias_name_field_id"><?php esc_html_e( 'Alias / nickname field', 'koopo' ); ?></label>
                    </th>
                    <td>
                        <?php koopo_render_xprofile_field_select( 'koopo_alias_name_field_id', $alias_name_field_id, $profile_fields ); ?>
                        <p class="description">
                            <?php esc_html_e( 'Select the xProfile field that stores the public alias or nickname. This is used when the display-name choice is Nickname or Alias.', 'koopo' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function koopo_social_features_settings_page() {
    $member_polls_enabled = Koopo_BuddyBoss_Poll_Permissions::members_are_enabled();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Social Features', 'koopo' ); ?></h1>
        <p><?php esc_html_e( 'Control Koopo-wide social permissions shared by the website, API gateway, and mobile application.', 'koopo' ); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'koopo_social_features_group' ); ?>
            <table class="form-table" role="presentation">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'Member Poll Creation', 'koopo' ); ?></th>
                    <td>
                        <input
                            type="hidden"
                            name="<?php echo esc_attr( Koopo_BuddyBoss_Poll_Permissions::OPTION_ALLOW_ALL_MEMBERS ); ?>"
                            value="0"
                        />
                        <label for="<?php echo esc_attr( Koopo_BuddyBoss_Poll_Permissions::OPTION_ALLOW_ALL_MEMBERS ); ?>">
                            <input
                                id="<?php echo esc_attr( Koopo_BuddyBoss_Poll_Permissions::OPTION_ALLOW_ALL_MEMBERS ); ?>"
                                type="checkbox"
                                name="<?php echo esc_attr( Koopo_BuddyBoss_Poll_Permissions::OPTION_ALLOW_ALL_MEMBERS ); ?>"
                                value="1"
                                <?php checked( $member_polls_enabled ); ?>
                            />
                            <?php esc_html_e( 'Allow all logged-in members to create polls in activity and profile feeds', 'koopo' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'BuddyBoss Polls must also be enabled. Group poll permissions remain limited to group owners and moderators.', 'koopo' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function koopo_get_xprofile_field_options() {
    if ( ! function_exists( 'bp_xprofile_get_groups' ) ) {
        return array();
    }

    $groups = bp_xprofile_get_groups(
        array(
            'fetch_fields' => true,
        )
    );

    $fields = array();
    foreach ( (array) $groups as $group ) {
        if ( empty( $group->fields ) || ! is_array( $group->fields ) ) {
            continue;
        }

        foreach ( $group->fields as $field ) {
            if ( empty( $field->id ) || empty( $field->name ) ) {
                continue;
            }

            $fields[] = array(
                'id'    => absint( $field->id ),
                'name'  => (string) $field->name,
                'group' => ! empty( $group->name ) ? (string) $group->name : '',
                'type'  => ! empty( $field->type ) ? (string) $field->type : '',
            );
        }
    }

    return $fields;
}

function koopo_render_xprofile_field_select( $option_name, $selected, $profile_fields ) {
    if ( empty( $profile_fields ) ) {
        ?>
        <input id="<?php echo esc_attr( $option_name ); ?>" type="number" min="0" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $selected ); ?>" style="width: 120px;" />
        <p class="description"><?php esc_html_e( 'BuddyBoss xProfile fields are not available. Enter field IDs after BuddyBoss is active.', 'koopo' ); ?></p>
        <?php
        return;
    }
    ?>
    <select id="<?php echo esc_attr( $option_name ); ?>" name="<?php echo esc_attr( $option_name ); ?>" style="min-width: 360px;">
        <option value="0"><?php esc_html_e( 'Select a profile field', 'koopo' ); ?></option>
        <?php foreach ( (array) $profile_fields as $field ) : ?>
            <?php
            $label = sprintf(
                '#%1$d - %2$s%3$s%4$s',
                absint( $field['id'] ),
                $field['name'],
                '' !== $field['group'] ? ' (' . $field['group'] . ')' : '',
                '' !== $field['type'] ? ' - ' . $field['type'] : ''
            );
            ?>
            <option value="<?php echo esc_attr( $field['id'] ); ?>" <?php selected( $selected, $field['id'] ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}
