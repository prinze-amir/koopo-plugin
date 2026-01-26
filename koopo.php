<?php
/**
 * Plugin Name: Koopo
 * Plugin URI: http://www.docs.koopoonline.com/
 * Description: Custom blocks and shortcodes for advance features.
 * Version: 2.20
 * Author: Plu2oprinze
 * Author URI: http://www.koopoonline.com
 */

define( 'KOOPO_PATH', plugin_dir_path( __FILE__ ) );

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

// GeoDirectory Location Manager: restrict footer modal/script to GeoDirectory archive/search pages only.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/geodir-location-manager-allowlist.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/geodir-location-manager-allowlist.php';
}
// BuddyBoss/Woo/Dokan registration bridge + stale pending cleanup.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/registration-bridge.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/registration-bridge.php';
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

