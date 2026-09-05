<?php
/**
 * Plugin Name: Koopo
 * Plugin URI: http://www.docs.koopoonline.com/
 * Description: Custom blocks and shortcodes for advance features.
 * Version: 2.58
 * Author: Plu2oprinze
 * Author URI: http://www.koopoonline.com
 */

define( 'KOOPO_PATH', plugin_dir_path( __FILE__ ) );

function koopo_install_commerce_native_checkout_mu_plugin() {
    $source = plugin_dir_path( __FILE__ ) . 'includes/commerce/koopo-commerce-native-checkout-mu.php';
    $target_dir = WP_CONTENT_DIR . '/mu-plugins';
    $target = $target_dir . '/koopo-commerce-native-checkout.php';

    if ( ! file_exists( $source ) ) {
        return false;
    }

    if ( ! is_dir( $target_dir ) ) {
        wp_mkdir_p( $target_dir );
    }

    if ( ! is_dir( $target_dir ) || ! is_writable( $target_dir ) ) {
        return false;
    }

    $source_hash = md5_file( $source );
    $target_hash = file_exists( $target ) ? md5_file( $target ) : '';
    if ( $source_hash && $source_hash === $target_hash ) {
        return true;
    }

    return copy( $source, $target );
}

register_activation_hook( __FILE__, 'koopo_install_commerce_native_checkout_mu_plugin' );
add_action( 'plugins_loaded', 'koopo_install_commerce_native_checkout_mu_plugin', 1 );

add_action( 'plugins_loaded', 'kb_load_textdomain' );
function kb_load_textdomain() {
	load_plugin_textdomain( 'koopo', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

/*
* This function will add target="_blank" to external product add to cart button
*/  
function my_override_woocommerce_external_template($template, $template_name, $template_path) {
    if ($template_name === 'single-product/add-to-cart/external.php') {
        // Path to your custom template inside the plugin
        $plugin_template = plugin_dir_path(__FILE__) . 'templates/woocommerce/single-product/add-to-cart/external.php';
        
        // Check if the custom template exists
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }
    return $template;
}
add_filter('woocommerce_locate_template', 'my_override_woocommerce_external_template', 10, 3);

// In koopo.php
if ( file_exists( plugin_dir_path(__FILE__) . 'includes/tweaks.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'includes/tweaks.php';
}
if ( file_exists( plugin_dir_path(__FILE__) . 'admin/admin-settings.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'admin/admin-settings.php';
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/elementor/elementor-influencer-registration.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/elementor/elementor-influencer-registration.php';
}
if ( file_exists( plugin_dir_path(__FILE__) . 'includes/koopo-shortcodes.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'includes/koopo-shortcodes.php';
}
if ( file_exists( plugin_dir_path(__FILE__) . 'includes/dokan-pack-free-checkout.php' ) ) {
    require_once plugin_dir_path(__FILE__) . 'includes/dokan-pack-free-checkout.php';
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/dokan/vendor-starter-pack-auto-assign.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/dokan/vendor-starter-pack-auto-assign.php';
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/Buddy boss/class-koopo-buddyboss-profile-tabs.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Buddy boss/class-koopo-buddyboss-profile-tabs.php';
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/Buddy boss/class-koopo-buddyboss-poll-permissions.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Buddy boss/class-koopo-buddyboss-poll-permissions.php';
    Koopo_BuddyBoss_Poll_Permissions::boot();
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/Buddy boss/class-koopo-buddyboss-display-name.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Buddy boss/class-koopo-buddyboss-display-name.php';
    if ( class_exists( 'Koopo_BuddyBoss_Display_Name' ) ) {
        $koopo_buddyboss_display_name = new Koopo_BuddyBoss_Display_Name();
        $koopo_buddyboss_display_name->init();
    }
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/class-koopo-account-settings-rest.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-koopo-account-settings-rest.php';
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/creator-support/class-koopo-creator-support.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/creator-support/class-koopo-creator-support.php';
    if ( class_exists( 'Koopo_Creator_Support' ) ) {
        Koopo_Creator_Support::instance();
    }
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/sms-auth/class-koopo-sms-auth.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/sms-auth/class-koopo-sms-auth.php';
    if ( class_exists( 'Koopo_SMS_Auth' ) ) {
        register_activation_hook( __FILE__, array( 'Koopo_SMS_Auth', 'activate' ) );
        Koopo_SMS_Auth::instance();
    }
}
// Koopo Dokan upgrade modal integration
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/dokan/koopo-dokan-upgrade.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/dokan/koopo-dokan-upgrade.php';
}

// GeoDirectory Location Manager: restrict footer modal/script to GeoDirectory archive/search pages only.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/geodir-location-manager-allowlist.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/geodir-location-manager-allowlist.php';
}
// GeoDirectory listing/event video uploads through Koopo Video + Bunny Stream.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/geodir-bunny-video/class-koopo-geodir-bunny-video.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/geodir-bunny-video/class-koopo-geodir-bunny-video.php';
    if ( class_exists( 'Koopo_Geodir_Bunny_Video' ) ) {
        Koopo_Geodir_Bunny_Video::instance();
    }
}
// Published products that are discoverable only from their linked GeoDirectory Place.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/commerce/class-koopo-place-only-products.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/commerce/class-koopo-place-only-products.php';
    if ( class_exists( 'Koopo_Place_Only_Products' ) ) {
        Koopo_Place_Only_Products::boot();
    }
}
// BuddyBoss/Woo/Dokan registration bridge + stale pending cleanup.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/registration-bridge.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/registration-bridge.php';
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/influencer-square/class-koopo-influencer-square.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/influencer-square/class-koopo-influencer-square.php';
    if ( class_exists( 'Koopo_Influencer_Square' ) ) {
        register_activation_hook( __FILE__, array( 'Koopo_Influencer_Square', 'activate' ) );
        register_deactivation_hook( __FILE__, array( 'Koopo_Influencer_Square', 'deactivate' ) );
        Koopo_Influencer_Square::instance();
    }
}
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/favorites/class-koopo-favorites.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/favorites/class-koopo-favorites.php';
    Koopo_Favorites::instance();
}
if ( class_exists( 'Koopo_Account_Settings_Rest' ) ) {
    $koopo_account_settings_rest = new Koopo_Account_Settings_Rest();
    $koopo_account_settings_rest->init();
}

//add_filter( 'template_include', 'kb_include_audio_templates' );
//add_filter( 'template_include', 'kb_include_video_templates' );

/**
 * template that displays Audio Archive and Single.
 *
 * @param string $template_path path to our template file.
 */
function kb_include_audio_templates( $template_path ) {
	global $template_type;
	/*if ( 'dzsap_items' === get_post_type() && is_single() ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-single.php';
		}
*/
		if ( 'koopo_music' === get_post_type() && is_single() ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-single.php';
		}

		if ( 'dzsap_items' === get_post_type() && is_single() ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-single.php';
		}

		if ( 'artists' === get_post_type() && is_single() ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/artist-single.php';
		}

		if ( 'albums' === get_post_type() && is_single() ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/album.php';
        }

		/*if ( is_post_type_archive('dzsap_items') || is_tax('genre') || is_tax('music_tags') ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-archive.php';
		}*/

		if ( is_post_type_archive('koopo_music') || is_tax('genre') || is_tax('music_tags') ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-archive.php';
		}

		if ( is_post_type_archive('artists') || is_post_type_archive('albums') || is_tax('genre') || is_tax('music_tags') ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/audio-archive.php';
		}

	return $template_path;
}

/**
 * template that displays video Archive and Single.
 *
 * @param string $template_path path to our template file.
 */
function kb_include_video_templates( $template_path ) {
	global $template_type;
	if ( 'kvidz' === get_post_type() && is_single() ) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/video-single.php';
        }

		if ( is_post_type_archive('kvidz') || is_tax('kvidz_categories') || is_tax('kmedia_tags')) {

			$template_path = plugin_dir_path( __FILE__ ) . 'templates/video-archive.php';
		}

	return $template_path;
}
