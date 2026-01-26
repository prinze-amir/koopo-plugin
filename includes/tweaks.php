<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;


// Register custom dynamic tag for Elementor
add_action( 'elementor/dynamic_tags/register_tags', function( $dynamic_tags ) {
    require_once plugin_dir_path( __FILE__ ) . '/elementor/tag-category-image.php';
    $dynamic_tags->register( new \Koopo\Elementor\Tags\Woo_Category_Image_Tag() );
});

add_filter( 'woocommerce_default_catalog_orderby_options', 'custom_woocommerce_catalog_orderby' );
add_filter( 'woocommerce_catalog_orderby', 'custom_woocommerce_catalog_orderby' );
add_filter( 'woocommerce_get_catalog_ordering_args', 'custom_woocommerce_get_catalog_ordering_args' );

function custom_woocommerce_catalog_orderby( $sortby ) {
    $sortby['random_list'] = 'Random';
    return $sortby;
}

function custom_woocommerce_get_catalog_ordering_args( $args ) {
    $orderby_value = isset( $_GET['orderby'] ) ? wc_clean( $_GET['orderby'] ) : apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby' ) );

    if ( 'random_list' == $orderby_value ) {
    $args['orderby'] = 'rand';
    $args['order'] = '';
    $args['meta_key'] = '';
    }
    return $args;
}

//Dokan subscription override
    add_filter( 'dokan_sub_shortcode', 'custom_dokan_packs',10,2 );
    // add_filter('dps_get_subscription_pack_arg', 'hide_subs');

    // function hide_subs($args): mixed{
    //     $args['post__in'] = [12092,4361,4315,64304,4351,64291,5727];//this hides these product packs from the list the post__not_in not working.
    //   return  $args;
    // }

    function custom_dokan_packs($contents, $subscription_packs){
        global $post;
        $user_id            = dokan_get_current_user_id();
        $helper = new DokanPro\Modules\Subscription\Helper();
        ob_start();
        ?>

        <div class="dokan-subscription-content">
            <h2>Koopo Subscriptions</h2>
            <?php
                $subscription = dokan()->vendor->get( $user_id )->subscription;
            ?>

            <?php if ( $subscription && $subscription->has_pending_subscription() ) : ?>
                <div class="seller_subs_info">
                    <div><h3>Current Subscription</h3></div>
                    <?php printf(
                            __( 'The <span>%s</span> subscription is inactive due to payment failure. Please <a href="?add-to-cart=%s">Pay Now</a> to active it again.', 'dokan' ),
                            $subscription->get_package_title(),
                            $subscription->get_id()
                        );
                    ?>
                </div>
            <?php elseif ( $subscription && $subscription->can_post_product() ) : ?>
                <div class="seller_subs_info">
                                <div><h3>Current Subscription</h3></div>

                    <p>
                    <?php
                        if ( $subscription->is_trial() ) {
                            $trial_title = $subscription->get_trial_range() . ' ' . $subscription->get_trial_period_types();

                            printf( __( 'Your are using <span>%s (%s trial)</span> package.', 'dokan' ), $subscription->get_package_title(), $trial_title  );
                        } else {
                            printf( __( 'Your are using <span>%s</span> package.', 'dokan' ), $subscription->get_package_title() );
                        }
                    ?>
                    </p>
                    <p>
                    <?php
                        $no_of_product = '-1' !== $subscription->get_number_of_products() ? $subscription->get_number_of_products() : __( 'unlimited', 'dokan' );

                        if ( $subscription->is_recurring() ) {
                            printf( __( 'You can add <span>%s</span> products', 'dokan' ), $no_of_product );
                        } elseif ( $subscription->get_pack_end_date() === 'unlimited' ) {
                            printf( __( 'You can add <span>%s</span> product(s)  <span>forever</span>.', 'dokan' ), $no_of_product );
                        } else {
                            printf( __( 'You can add <span>%s</span> product(s) for <span>%s</span> days.', 'dokan' ), $no_of_product, $subscription->get_pack_valid_days() );
                        }
                    ?>
                    </p>
                    <p>
                        <?php
                            if ( $subscription->has_active_cancelled_subscrption() ) {
                                $date   = date_i18n( get_option( 'date_format' ), strtotime( $subscription->get_pack_end_date() ) );
                                $notice = sprintf( __( 'Your subscription has been cancelled! However it\'s is still active till %s', 'dokan' ), $date );
                                printf( "<span>{$notice}</span>" );
                            } else {
                                if ( $subscription->is_trial() ) {
                                    // don't show any text
                                } elseif ( $subscription->is_recurring() ) {
                                    echo sprintf( __( 'You will be charged every %d', 'dokan' ), $subscription->get_recurring_interval() ) . ' ' . $helper->recurring_period( $subscription->get_period_type() );
                                } elseif ( $subscription->get_pack_end_date() === 'unlimited' ) {
                                    printf( __( 'You have a lifetime package.', 'dokan' ) );
                                } else {
                                    printf( __( 'Your package will expire on <span>%s</span>', 'dokan' ), date_i18n( get_option( 'date_format' ), strtotime( $subscription->get_pack_end_date() ) ) );
                                }
                            }
                        ?>
                    </p>

                    <?php
                        if ( ! ( ! $subscription->is_recurring() && $subscription->has_active_cancelled_subscrption() ) ) {
                            ?>
                            <p>
                                <form action="" method="post">
                                    <?php
                                        $maybe_reactivate = $subscription->is_recurring() && $subscription->has_active_cancelled_subscrption();
                                        $notice           = $maybe_reactivate ? __( 'activate', 'dokan' ) : __( 'cancel', 'dokan' );
                                        $nonce            = $maybe_reactivate ? 'dps-sub-activate' : 'dps-sub-cancel';
                                        $input_name       = $maybe_reactivate ? 'dps_activate_subscription' : 'dps_cancel_subscription';
                                        $btn_class        = $maybe_reactivate ? 'btn-success' : 'btn-danger';
                                        $again            = $maybe_reactivate ? __( 'again', 'dokan' ) : '';
                                    ?>

                                    <label><?php _e( "To {$notice} your subscription {$again} click here &rarr;", "dokan" ); ?></label>

                                    <?php wp_nonce_field( $nonce ); ?>
                                    <input type="submit" name="<?php echo esc_attr( $input_name ); ?>" class="<?php echo esc_attr( "btn btn-sm {$btn_class}" ); ?>" value="<?php echo esc_attr( ucfirst( $notice ) ); ?>">
                                </form>
                            </p>
                            <?php
                        }
                    ?>
                </div>
                <?php  else :  ?>
        <div class="seller_subs_info">
            <div><h3>Current Subscription</h3></div>

            <p><b>Your account is not fully activated.</b></p>
            <p class="add-text" style="text-align:left">Please select a subscription package to fully activate your account to unlock your Koopo Store features.</p>
            </div>
            <?php endif; ?>

            <?php if ( $subscription_packs->have_posts() ) {
                ?>

                <?php if ( isset( $_GET['msg'] ) && 'dps_sub_cancelled' === $_GET['msg'] ) : ?>
                    <div class="dokan-message">
                        <?php
                            if ( $subscription && $subscription->has_active_cancelled_subscrption() ) {
                                $date   = date_i18n( get_option( 'date_format' ), strtotime( $subscription->get_pack_end_date() ) );
                                $notice = sprintf( __( 'Your subscription has been cancelled! However the it\'s is still active till %s', 'dokan' ), $date );
                            } else {
                                $notice = __( 'Your subscription has been cancelled!', 'dokan' );
                            }
                        ?>

                        <p><?php printf( $notice ); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ( isset( $_GET['msg'] ) && 'dps_sub_activated' === $_GET['msg'] ) : ?>
                    <div class="dokan-message">
                        <?php
                            esc_html_e( 'Your subscription has been re-activated!', 'dokan' );
                        ?>
                    </div>
                <?php endif; ?>

                <div class="pack_content_wrapper">

                <?php
                while ( $subscription_packs->have_posts() ) {
                    $subscription_packs->the_post();

                    // get individual subscriptoin pack details
                    $sub_pack           = dokan()->subscription->get( get_the_ID() );
                    $is_recurring       = $sub_pack->is_recurring();
                    $recurring_interval = $sub_pack->get_recurring_interval();
                    $recurring_period   = $sub_pack->get_period_type();
                    $the_price          = $sub_pack->get_price();
                    ?>

                    <div class="product_pack_item <?php echo ( $helper->is_vendor_subscribed_pack( get_the_ID() ) || $helper->pack_renew_seller( get_the_ID() ) ) ? 'current_pack ' : ''; ?><?php echo ( $sub_pack->is_trial() && $helper->has_used_trial_pack( get_current_user_id(), get_the_id() ) ) ? 'fp_already_taken' : ''; ?>">
                        <div style="line-height:1" class="pack_price">

                            <span class="dps-amount">
                                <?php if ($the_price < 0){
                                    echo "Free";
                                    } else {
                                        echo wc_price( $sub_pack->get_price() );
                                    }
                                ?>
                            </span>

                            <?php if ( $is_recurring && $recurring_interval === 1 ) { ?>
                                <span class="dps-rec-period">
                                    <span class="sep">/</span><?php echo $helper->recurring_period( $recurring_period ); ?>
                                </span>
                            <?php } ?>
                        </div><!-- .pack_price -->

                        <div class="pack_content">
                    <!--h2><?php echo $sub_pack->get_package_title(); ?></h2-->
                    <?php  the_post_thumbnail( 'large' );?>

                            <?php  

                            $no_of_product = $sub_pack->get_number_of_products();

                            ?><div class="pack_data_option"><?php

                            if ( '-1' === $no_of_product ) {
                                printf( __( '<strong>List Unlimited</strong> Products <br />', 'dokan' ) );
                            } else {
                                printf( __( '<strong>List %d</strong> Products <br />', 'dokan' ), $no_of_product );
                            }

                            ?>

                            <?php if ( $is_recurring && $sub_pack->is_trial() && $helper->has_used_trial_pack( get_current_user_id() ) ) : ?>
                                <span class="dps-rec-period">
                                    <?php printf( __( 'for %d %s(s)', 'dokan' ), $recurring_interval, $helper->recurring_period( $recurring_period ) ); ?>
                                </span>
                            <?php elseif ( $is_recurring && $sub_pack->is_trial() ) : ?>
                                <span class="dps-rec-period">
                                    <?php printf( __( 'for %d %s(s) <p class="trail-details">%d %s(s) trial </p>', 'dokan' ), $recurring_interval, $helper->recurring_period( $recurring_period ), $sub_pack->get_trial_range(), $helper->recurring_period( $sub_pack->get_trial_period_types() ) ); ?>
                                </span>
                            <?php elseif ( $is_recurring && $recurring_interval >= 1) : ?>
                                <span class="dps-rec-period">
                                    <?php printf( __( 'for %d %s(s)', 'dokan' ), $recurring_interval, $helper->recurring_period( $recurring_period ) ); ?>
                                </span>
                            <?php else :
                                if ( $sub_pack->get_pack_valid_days() == 0 ) {
                                    printf( __( '<strong>Forever</strong>', 'dokan' ) );
                                } else {
                                    $pack_validity = $sub_pack->get_pack_valid_days();
                                    printf( __( 'For<br /><strong>%s</strong> Days', 'dokan' ), $pack_validity );
                                }
                            endif; 
                            ?>
                            <div style="height:10px">
                                <hr style="background-color:#333"></hr>
                            </div>
                            <?php  
                            
                                the_content();
                            ?>
                        <h3 style="text-align:center"><strong>Koopo Online Fees</strong></h3>
                        <strong>2.9% + 30¢</strong> Payment Process Fee<br>
                        <?php $admin_fee = get_post_meta(get_the_id(), '_subscription_product_admin_commission', true);
                        echo '<strong>' . $admin_fee . ' %</strong> Referral Fee';
                                ?>
                                <h5>*Percentage decreases with membership upgrade</h5>
                                </div>
                        </div>

                        <div class="buy_pack_button">
                            <?php if ( $helper->is_vendor_subscribed_pack( get_the_ID() ) ): ?>

                                <a href="<?php echo get_permalink( get_the_ID() ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack"><?php _e( 'Your Access', 'dokan' ); ?></a>

                            <?php elseif ( $helper->pack_renew_seller( get_the_ID() ) ): ?>

                                <a href="<?php echo do_shortcode( '[add_to_cart_url id="' . get_the_ID() . '"]' ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack"><?php _e( 'Renew', 'dokan' ); ?></a>

                            <?php else: ?>

                                <?php if ( $sub_pack->is_trial() && $helper->vendor_has_subscription( dokan_get_current_user_id() ) && $helper->has_used_trial_pack( dokan_get_current_user_id() ) ): ?>
                                    <a href="<?php echo do_shortcode( '[add_to_cart_url id="' . get_the_ID() . '"]' ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack"><?php _e( 'Switch Plan', 'dokan' ); ?></a>
                                <?php elseif ( $sub_pack->is_trial() && $helper->has_used_trial_pack( dokan_get_current_user_id() ) ) : ?>
                                    <a href="<?php echo do_shortcode( '[add_to_cart_url id="' . get_the_ID() . '"]' ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack"><?php _e( 'Select', 'dokan' ); ?></a>

                                <?php elseif ( ! $helper->vendor_has_subscription( dokan_get_current_user_id() ) ) : ?>
                                    <?php if ( $sub_pack->is_trial() ) : ?>
                                        <a href="<?php echo do_shortcode( '[add_to_cart_url id="' . get_the_ID() . '"]' ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack trial_pack"><?php _e( 'Start Free Trial', 'dokan' ); ?></a>
                                    <?php else: ?>
                                        <a href="<?php echo do_shortcode( '[add_to_cart_url id="' . get_the_ID() . '"]' ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack"><?php _e( 'Select', 'dokan' ); ?></a>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <a href="<?php echo do_shortcode( '[add_to_cart_url id="' . get_the_ID() . '"]' ); ?>" class="dokan-btn dokan-btn-theme buy_product_pack"><?php _e( 'Switch Plan', 'dokan' ); ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
            } else {
                echo '<h3>' . __( 'No subscription found!', 'dokan' ) . '</h3>';
            }

            wp_reset_postdata();
            ?>
            <div class="clearfix"></div>
            </div>
            
        </div>
        <script>
    const showFeatureButtons = document.getElementsByClassName('show-feature-btn');

    for (const button of showFeatureButtons) {
        
        // Initialize button text (assuming content is hidden initially)
        button.textContent = 'Show Features';

        button.addEventListener('click', function() {
            const packId = this.dataset.packId;
            const targetContent = document.querySelector(`.pack-content[data-pack-id="${packId}"]`);
            
            if (targetContent) {
                // 1. Toggle the CSS class (this triggers the smooth animation)
                targetContent.classList.toggle('is-visible');
                
                // 2. Update the button text based on the new state
                // .classList.contains() checks if the class is currently applied
                if (targetContent.classList.contains('is-visible')) {
                    this.textContent = 'Hide Features';
                } else {
                    this.textContent = 'Show Features';
                }
            }
        });
    }
</script>
        <?php

        $contents = ob_get_clean();

        return $contents;
    }



add_action( 'before_delete_post', 'delete_product_images', 10, 1 );
// Automatically shortens WooCommerce product titles on the main shop, category, and tag pages

function delete_product_images( $post_id )
{
    $product = wc_get_product( $post_id );

    if ( !$product ) {
        return;
    }

    $featured_image_id = $product->get_image_id();
    $image_galleries_id = $product->get_gallery_image_ids();

    if( !empty( $featured_image_id ) ) {
        wp_delete_post( $featured_image_id );
    }

    if( !empty( $image_galleries_id ) ) {
        foreach( $image_galleries_id as $single_image_id ) {
            wp_delete_post( $single_image_id );
        }
    }
}

add_filter( 'option_dokan_selling', function ( $value ) {
    if ( ! is_array( $value ) ) {
        return $value;
    }

    $value['shipping_fee_recipient']     = 'seller';
    $value['shipping_tax_fee_recipient'] = 'seller';

    return $value;
} );
