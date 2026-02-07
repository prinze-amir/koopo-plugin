<?php

//custom shortcodes for Koopo
add_shortcode( 'bb_author_profile_button', 'koopo_bb_author_profile_button_shortcode' );
add_shortcode('mobile-footer-menu', 'add_mobile_footer');
add_shortcode( 'fav-products', 'get_user_fav_products');
add_shortcode( 'joinTheSquare', 'koopo_join_the_square_shortcode' );

add_action( 'wp_ajax_koopo_join_the_square', 'koopo_handle_join_the_square_ajax' );

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

function koopo_handle_join_the_square_ajax() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'not_logged_in' ), 401 );
    }

    $nonce_ok = isset( $_POST['nonce'] ) && wp_verify_nonce( $_POST['nonce'], 'koopo_join_the_square' );
    if ( ! $nonce_ok ) {
        wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
    }

    $user = wp_get_current_user();
    if ( koopo_user_has_influencer_access( $user ) ) {
        wp_send_json_success();
    }

    if ( get_role( 'influencer' ) ) {
        $user->add_role( 'influencer' );
    }

    wp_send_json_success();
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
    $has_access = koopo_user_has_influencer_access( $user );

    if ( ! $has_access && ! get_role( 'influencer' ) ) {
        return '';
    }

    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'koopo_join_the_square' );

    static $assets_printed = false;
    ob_start();

    if ( ! $assets_printed ) :
        $assets_printed = true;
        ?>
        <style>
            .koopo-join-square { display: inline-block; }
            .koopo-join-square [hidden] { display: none !important; }
            .koopo-join-square .button { display: inline-flex; align-items: center; gap: 8px; }
            .koopo-join-square .koopo-icon { width: 16px; height: 16px; display: inline-block; }
            .koopo-join-square-modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.55); display: flex; align-items: center; justify-content: center; z-index: 9999; }
            .koopo-join-square-modal[hidden] { display: none; }
            .koopo-join-square-card { background: #fff; padding: 24px; border-radius: 12px; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
            .koopo-join-square-card h3 { margin: 0 0 10px; }
            .koopo-join-square-card p { margin: 0 0 16px; }
            .koopo-join-square-actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
            .koopo-join-square-close { background: transparent; border: 0; font-size: 22px; line-height: 1; position: absolute; top: 12px; right: 16px; cursor: pointer; }
            .koopo-join-square-wrap { position: relative; }
            .koopo-join-square-welcome { display: inline-flex; align-items: center; gap: 20px; color:#fff}
            .koopo-join-square-btn.koopo-animate-out { animation: koopoJoinSquareFadeOut 0.25s ease forwards; }
            .koopo-join-square-welcome.koopo-animate-in { animation: koopoJoinSquareFadeIn 0.35s ease forwards; }

            @keyframes koopoJoinSquareFadeOut {
                from { opacity: 1; transform: translateY(0); }
                to { opacity: 0; transform: translateY(-6px); }
            }

            @keyframes koopoJoinSquareFadeIn {
                from { opacity: 0; transform: translateY(8px); }
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
        <script>
            (function () {
                function closest(el, selector) {
                    while (el && el.nodeType === 1) {
                        if (el.matches(selector)) return el;
                        el = el.parentElement;
                    }
                    return null;
                }

                function openModal(modal) {
                    if (!modal) return;
                    modal.removeAttribute('hidden');
                }

                function closeModal(modal) {
                    if (!modal) return;
                    modal.setAttribute('hidden', 'hidden');
                }

                document.addEventListener('click', function (e) {
                    var joinBtn = closest(e.target, '.koopo-join-square-btn');
                    if (joinBtn) {
                        e.preventDefault();
                        var wrap = closest(joinBtn, '.koopo-join-square');
                        if (!wrap) return;

                        var ajaxUrl = wrap.getAttribute('data-ajax-url');
                        var nonce = wrap.getAttribute('data-nonce');
                        var welcome = wrap.querySelector('.koopo-join-square-welcome');
                        var modal = wrap.querySelector('.koopo-join-square-modal');

                        joinBtn.disabled = true;
                        joinBtn.setAttribute('aria-busy', 'true');

                        var body = new FormData();
                        body.append('action', 'koopo_join_the_square');
                        body.append('nonce', nonce);

                        fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: body
                        })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data && data.success) {
                                joinBtn.classList.add('koopo-animate-out');
                                setTimeout(function () {
                                    joinBtn.setAttribute('hidden', 'hidden');
                                }, 250);

                                if (welcome) {
                                    welcome.removeAttribute('hidden');
                                    welcome.classList.add('koopo-animate-in');
                                }
                                openModal(modal);
                            }
                        })
                        .finally(function () {
                            joinBtn.disabled = false;
                            joinBtn.removeAttribute('aria-busy');
                        });

                        return;
                    }

                    var closeBtn = closest(e.target, '.koopo-join-square-close');
                    if (closeBtn) {
                        e.preventDefault();
                        var modal = closest(closeBtn, '.koopo-join-square-modal');
                        closeModal(modal);
                        return;
                    }

                    var overlay = closest(e.target, '.koopo-join-square-modal');
                    if (overlay && e.target === overlay) {
                        closeModal(overlay);
                    }
                });
            })();
        </script>
        <?php
    endif;
    ?>
    <div class="koopo-join-square" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <div class="koopo-join-square-wrap">
            <button type="button" class="button koopo-join-square-btn" <?php echo $has_access ? 'hidden="hidden"' : ''; ?>>
                <span class="koopo-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" focusable="false" aria-hidden="true">
                        <path d="M12 2 3 7v10l9 5 9-5V7l-9-5zm0 2.2 6.8 3.7L12 11.6 5.2 7.9 12 4.2zm-7 5.1 6 3.3v7.2l-6-3.3V9.3zm8 10.5v-7.2l6-3.3v7.2l-6 3.3z"></path>
                    </svg>
                </span>
                Join The Square
            </button>

            <div class="koopo-join-square-welcome" <?php echo $has_access ? '' : 'hidden="hidden"'; ?>>
                <strong>Welcome to the Square</strong>
                <a href="/add-new-post" class="button addNewBtn">
                    <span class="koopo-icon" aria-hidden="true">
                        <i class="fa-solid fa-pen"></i>
                    </span>
                    Add New Article
                </a>
            </div>
        </div>

        <div class="koopo-join-square-modal" hidden="hidden" role="dialog" aria-modal="true">
            <div class="koopo-join-square-card">
                <button class="koopo-join-square-close" aria-label="Close">×</button>
                <h3>Welcome to the Square</h3>
                <p>You are now an influencer.</p>
                <div class="koopo-join-square-actions">
                    <a href="/add-new-post" class="button addNewBtn">
                        <span class="koopo-icon" aria-hidden="true">
                            <i class="fa-solid fa-pen"></i>
                        </span>
                        Add New Article
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}

?>
