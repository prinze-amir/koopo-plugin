<?php

//custom shortcodes for Koopo
add_shortcode( 'bb_author_profile_button', 'koopo_bb_author_profile_button_shortcode' );
add_shortcode('mobile-footer-menu', 'add_mobile_footer');
add_shortcode( 'fav-products', 'get_user_fav_products');
add_shortcode( 'joinTheSquare', 'koopo_join_the_square_shortcode' );

add_action( 'init', 'koopo_handle_join_the_square' );

function koopo_user_has_influencer_access( $user ) {
    if ( ! $user || ! $user->exists() ) {
        return false;
    }

    $roles = (array) $user->roles;
    if ( in_array( 'influencer', $roles, true ) ) {
        return true;
    }

    $role = get_role( 'influencer' );
    if ( ! $role ) {
        return false;
    }

    $caps = array_keys( array_filter( (array) $role->capabilities ) );
    if ( empty( $caps ) ) {
        return false;
    }

    foreach ( $caps as $cap ) {
        if ( ! $user->has_cap( $cap ) ) {
            return false;
        }
    }

    return true;
}

function koopo_handle_join_the_square() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    if ( empty( $_POST['koopo_join_the_square'] ) ) {
        return;
    }

    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'koopo_join_the_square' ) ) {
        return;
    }

    $user = wp_get_current_user();
    if ( koopo_user_has_influencer_access( $user ) ) {
        $redirect = wp_get_referer() ?: home_url( '/' );
        wp_safe_redirect( $redirect );
        exit;
    }

    if ( get_role( 'influencer' ) ) {
        $user->add_role( 'influencer' );
    }

    $redirect = wp_get_referer() ?: home_url( '/' );
    wp_safe_redirect( $redirect );
    exit;
}

/**
 * BuddyBoss Author Profile Button Shortcode
 * Usage: [bb_author_profile_button]
 */
function koopo_bb_author_profile_button_shortcode( $atts ) {

    // Try to determine author ID in multiple contexts
    if ( is_singular() ) {
        $author_id = get_post_field( 'post_author', get_the_ID() );
    } elseif ( is_author() ) {
        $author_id = get_queried_object_id();
    } else {
        $author_id = get_the_author_meta( 'ID' );
    }

    if ( ! $author_id ) {
        return '';
    }

    // Get BuddyBoss profile URL
    if ( function_exists( 'bp_core_get_user_domain' ) ) {
        $profile_url = bp_core_get_user_domain( $author_id );
    } else {
        return ''; // BuddyBoss not active
    }

    // Build button HTML
    ob_start();
    ?>
    <a href="<?php echo esc_url( $profile_url ); ?>" 
       class=" btn button bb-go-to-profile-btn">
        Go To Profile
    </a>
    <?php

    return ob_get_clean();
}

function add_mobile_footer(){
ob_start();?>
    <style>

            #selectator_wyz-cat-filter {
                display:none;
            }
            .brand img {
                width:90%;
                max-width:165px;
            }

            ul.footer-mobile-menu {
                display: flex;
                flex-flow:row nowrap;
                list-style: none;
                margin:0;
                overflow-x:scroll;
            }
            .mobile-footer {
                background: #fff;
                z-index: 90;
                position: fixed;
                bottom: 0;
                padding:6px 10px 0;
                width:100%;
                overflow:hidden;
                box-shadow: 1px 1px 17px 2px rgba(0,0,0,0.1);
            }
            ul.footer-mobile-menu a {
                padding-right: .65em;
            }
            li.foot-item {
                text-align: center;
                font-size: 16px;
                padding: 5px 5px 0;
            }
            .mobile-foot-link i {
                font-size:20px;
            }
            .foot-item:active{
                transform: translateY(2px);
                box-shadow: 0px 0px 10px 4px #ffcc01;
                font-size: 14px;
            }
            .mobile-foot-link * {
                display:flex;
                flex-wrap:wrap;
                justify-content:center;
                padding:3px;
            }
            @media(min-width:875px){
                .mobile-footer{
                    display:none;
                }
            }

			</style>
			<div  class="mobile-footer">
				<ul class="footer-mobile-menu">
                <a class="mobile-foot-link" href="<?php echo home_url('/user-account/')?>"><li class="foot-item"><i class="fas fa-user"></i>Account</li></a>
                <a id="mobile-new-post" class="mobile-foot-link addNewBtn" href="#"><li class="foot-item"><i class="fas fa-plus"></i>Post</li></a>
                <?php if (is_page('user-account') || is_singular(['koopo_music','kvidz','artists','albums']) || is_post_type_archive( ['koopo_music,albums,kvidz, artists'] )): ?>
                        <a class="mobile-foot-link searchBtn" href="#"><li class="foot-item"><i class="fas fa-search-plus"></i>Search</li></a>
                <?php endif; ?>
                <a class="mobile-foot-link" href="<?php echo home_url('/shop/')?>"><li class="foot-item"><i class="_mi _before buddyboss bb-icon-shopping-cart"></i>Shop</li></a>
                <a class="mobile-foot-link" href="<?php echo home_url('/business/')?>"><li class="foot-item"><i class="_icon buddyboss bb-icon-map-pin"></i>Biz</li></a>
                <a class="mobile-foot-link" href="<?php echo home_url('/events/')?>"><li class="foot-item"><i class="far fa-calendar-alt"></i>Events</li></a>
                <a class="mobile-foot-link" href="<?php echo home_url('/videos/')?>"><li class="foot-item"><i class="_icon buddyboss bb-icon-video"></i>Video</li></a>
                <a class="mobile-foot-link" href="<?php echo home_url('/music/')?>"><li class="foot-item"><i class="fa fa-music"></i>Music</li></a>
                <a class="mobile-foot-link" href="<?php echo home_url('/influencers-square/')?>"><li class="foot-item"><i class="_icon buddyboss bb-icon-minimize-square"></i>Square</li></a>
                <a class="mobile-foot-link" href="https://docs.koopoonline.com"><li class="foot-item"><i class="_icon buddyboss bb-icon-help-circle"></i>Help</li></a>
				</ul>
			</div>
            <?php
        return ob_get_clean();
}


function get_user_fav_products(){
    $GLOBALS['simple_favourites_running'] = true;
    $user = bp_displayed_user_id();
    $favs = get_user_meta( $user, '_simple_favourites_string', true );
    if( empty( $favs ) ){
        $favs = array();
    }
    $favs = swf_check_favourites_products ($favs );
    add_action( 'woocommerce_after_shop_loop_item', array( 'SWF_Display', 'remove_button' ), 10 );

    ob_start();
        Simple_Woocommerce_Favourites::view( 'favourites-template', array( 'favourites' => $favs ) );
    $view = ob_get_clean();
    unset($GLOBALS['simple_favourites_running']);
    return $view;
}

function koopo_join_the_square_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $user = wp_get_current_user();
    if ( koopo_user_has_influencer_access( $user ) ) {
        return '';
    }

    if ( ! get_role( 'influencer' ) ) {
        return '';
    }

    ob_start();
    ?>
    <form method="post">
        <?php wp_nonce_field( 'koopo_join_the_square' ); ?>
        <input type="hidden" name="koopo_join_the_square" value="1" />
        <button type="submit" class="button">Join The Square</button>
    </form>
    <?php
    return ob_get_clean();
}

?>
