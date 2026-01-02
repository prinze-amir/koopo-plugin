<?php
/**
 * Plugin Name: Koopo
 * Plugin URI: http://www.docs.koopoonline.com/
 * Description: Custom blocks and shortcodes for advance features.
 * Version: 2.1
 * Author: Plu2oprinze
 * Author URI: http://www.koopoonline.com
 */

define( 'KOOPO_PATH', plugin_dir_path( __FILE__ ) );

add_action( 'plugins_loaded', 'kb_load_textdomain' );

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

// Stories (BuddyBoss) — bootstrap on plugins_loaded so BuddyBoss/BuddyPress APIs are available.
add_action( 'plugins_loaded', function () {
    // Only initialize if enabled in settings.
    // The option is registered in admin-settings/tweaks; we defensively check here.
    // Stories feature (always load admin/settings; frontend output guarded internally)
    if ( file_exists( KOOPO_PATH . 'includes/stories-feature.php' ) ) {
        require_once KOOPO_PATH . 'includes/stories-feature.php';

        if ( class_exists( 'Koopo_Stories_Feature' ) ) {
            $koopo_stories = new Koopo_Stories_Feature();
            $koopo_stories->init();
        }
    }
}, 20 );

// GeoDirectory Location Manager: restrict footer modal/script to GeoDirectory archive/search pages only.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/geodir-location-manager-allowlist.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/geodir-location-manager-allowlist.php';
}
// BuddyBoss/Woo/Dokan registration bridge + stale pending cleanup.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'includes/registration-bridge.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/registration-bridge.php';
}
//add_action( 'wp_enqueue_scripts' , 'enqueu_koopo_styles');

/*
require_once( plugin_dir_path( __FILE__ ) . 'includes/elementor.php');
require_once( plugin_dir_path( __FILE__ ) . 'includes/shortcodes.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/business.php' );

require_once( plugin_dir_path( __FILE__ ) . 'classes/koopo.php' );
require_once( plugin_dir_path( __FILE__ ) . 'classes/business.php' );
require_once( plugin_dir_path( __FILE__ ) . '/includes/wyz-theme-options.php' );
require_once( plugin_dir_path( __FILE__ ) . '/includes/theme-options-filters.php' );

add_action('init', function(){

	require_once( plugin_dir_path( __FILE__ ) . 'classes/helpers-override.php');
	require_once( plugin_dir_path( __FILE__ ) . 'classes/wyz-map-override.php');
	require_once( plugin_dir_path( __FILE__ ) . 'classes/business-post-override.php');
}, 10);

add_action('wp_insert_post', 'create_new_biz_activity_posts', 100, 2);

add_action( 'admin_enqueue_scripts', function(){

	wp_enqueue_style( 'koopo-extra',  '/wp-content/plugins/koopo-custom-blocks/assets/css/backend.css' );

});

add_action( 'wp_head', function(){

	/*$logo = wyz_get_option( 'header-logo-upload' );

	if ( is_mobile_screen() && !is_singular(['artists','koopo_music','kvidz','albums']) && !is_post_type_archive(['artists','koopo_music','kvidz','albums']) ) {

		echo '<div id="page-loader-init" style="background-color:rgba(255,255,255,0.90);"><div class="logo"><img src="'.$logo.'"/></div><div class="spinner"><div class="bounce1"></div><div class="bounce2"></div><div class="bounce3"></div></div></div>';

	}*/
/*
	echo '<script>

	jQuery(window).on("load", function(){
		if ( window.isNativeApp == true){
			jQuery("#masthead").css("display", "none");
		//	jQuery(".mobile-footer").css("display", "none");

		}
		jQuery("#page-loader-init").fadeOut("fast");
		jQuery("#wp-admin-bar-elementor_edit_page").hover(
			function(){
				jQuery(this).children(".ab-sub-wrapper").show();
			},
			function(){
				jQuery(this).children(".ab-sub-wrapper").hide();
			}
		);
	});</script>';

});*/

// add_action('wp_footer', function(){

// 	echo '<script>
// 	jQuery(window).on("load", function(){
// 		jQuery("#page-loader-init").fadeOut("fast");
// 		jQuery("#wp-admin-bar-elementor_edit_page").hover(
// 			function(){
// 				jQuery(this).children(".ab-sub-wrapper").show();
// 			},
// 			function(){
// 				jQuery(this).children(".ab-sub-wrapper").hide();
// 			}
// 		);
// 	});</script>';

// });

//add_filter( 'template_include', 'kb_include_audio_templates' );
//add_filter( 'template_include', 'kb_include_video_templates' );

function kb_load_textdomain() {
	load_plugin_textdomain( 'koopo', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

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





function create_new_biz_activity_posts($post_id, $post){

	$type = $post->post_type;
	$types = ['post', 'ajde_events', 'product', 'job_listing', 'attachment', 'wyz_business'];
	if (!in_array($type, $types) || $post->post_status != 'publish' ){
		return;
	}
	if ( !empty( get_post_meta($post_id, 'biz_post_update_once') ) ){
		return;
	}
	$author = $post->post_author;
	update_post_meta($post_id, 'biz_post_update_once', true);
	if ($type == 'ajde_events'){
		return KoopoBlocks\Classes\KoopoBusiness::add_new_event_activity($post_id, $author);
	}

	return	KoopoBlocks\Classes\KoopoBusiness::new_business_activity($post_id, $author);
}


function enqueu_koopo_styles(){

	wp_enqueue_style( 'koopo-frontend-snippet', '/wp-content/plugins/koopo-custom-blocks/assets/css/frontend-snippet.css' );
	wp_enqueue_style( 'koopo-loader', '/wp-content/plugins/koopo-custom-blocks/assets/css/koopo-loader.css' );
	wp_enqueue_style( 'wyz-wp-default-style', plugin_dir_url( __FILE__ ) . "assets/css/wp-default.css" );


	/*this is for business */

	if(is_singular(['wyz_business_post','wyz_offers','wyz_business','wyz_location',]) || is_tax(['wyz_business_category', 'offer-categories']) || is_post_type_archive(['wyz_offers','wyz_business']) || is_page(['business-activity', 'list-your-business','claim'])){
		wp_enqueue_style( 'wyz-template-style', plugin_dir_url( __FILE__ ) . 'assets/css/wyz_style.css' );
		wp_enqueue_style( 'wyz-candy-plugin-style', plugin_dir_url( __FILE__ ) . 'assets/css/wyz-features.min.css' );

		wp_enqueue_style( 'wyz-responsive-style', plugin_dir_url( __FILE__ ) . 'assets/css/responsive.css' );
		wp_enqueue_style( 'wyz-single-bustemplate-style', plugin_dir_url( __FILE__ ) . "assets/css/single-bus-style.css" );
	}
	if (is_singular(['wyz_offers','wyz_business']) || is_page('user-account'))
	wp_enqueue_script( 'wyz-bootstrap-meanmenu-magnificpopup-js', plugin_dir_url( __FILE__ ) . '/assets/js/bootstrap-meanmenu.min.js', array( 'jquery' ), false, false );

	if (is_singular('post') || is_preview() && is_singular('post')){

		wp_enqueue_style( 'ex-lasso-style', plugin_dir_url( __FILE__ ) . "assets/css/ex-lasso.css" );
		wp_enqueue_script( 'ex-lasso-js', plugin_dir_url( __FILE__ ) . '/assets/js/extra-lasso.js', array( 'jquery' ), false, false );
	}

	/*buddyboss*/
	if (function_exists('bp_is_user')){
		if (bp_is_user()){	wp_enqueue_style( 'koopo-custom-bb', '/wp-content/plugins/koopo-custom-blocks/assets/css/custom-bb.css' );
		}
	}

}

