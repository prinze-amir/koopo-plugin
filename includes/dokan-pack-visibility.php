<?php
/** Seller-facing pack visibility; published products remain available for admin assignment. */
defined( 'ABSPATH' ) || exit;

function koopo_hidden_subscription_pack_ids() {
    return array_values( array_unique( array_filter( array_map( 'absint', (array) get_option( 'koopo_hidden_subscription_pack_ids', array() ) ) ) ) );
}

function koopo_subscription_pack_visibility_args( $args ) {
    // Admin assignment uses the same legacy query. REST requests use frontend rules.
    if ( is_admin() && ( ! wp_doing_ajax() || current_user_can( 'manage_options' ) ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return $args;
    }
    $args['post__not_in'] = array_values( array_unique( array_merge(
        isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(),
        koopo_hidden_subscription_pack_ids()
    ) ) );
    return $args;
}
add_filter( 'dps_get_subscription_pack_arg', 'koopo_subscription_pack_visibility_args', 100 );
add_filter( 'dokan_rest_vendor_subscription_packages_query_args', 'koopo_subscription_pack_visibility_args', 100 );

add_action( 'admin_init', function () {
    register_setting( 'koopo_subscription_visibility', 'koopo_hidden_subscription_pack_ids', array(
        'type' => 'array',
        'default' => array(),
        'sanitize_callback' => 'koopo_sanitize_hidden_subscription_packs',
    ) );
} );

function koopo_sanitize_hidden_subscription_packs( $ids ) {
    $valid = array();
    foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $id ) {
        if ( $id && 'product' === get_post_type( $id ) && has_term( 'product_pack', 'product_type', $id ) ) {
            $valid[] = $id;
        }
    }
    return $valid;
}

add_action( 'koopo_admin_register_submenus', function ( $parent, $capability ) {
    add_submenu_page( $parent, __( 'Subscription Visibility', 'koopo' ), __( 'Subscription Visibility', 'koopo' ), $capability, 'koopo-subscription-visibility', 'koopo_subscription_visibility_page' );
}, 10, 2 );

function koopo_subscription_visibility_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // Deliberately bypass Dokan's frontend package query, including its admin-only filtering.
    $packs = get_posts( array(
        'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
        'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC',
        'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'product_pack' ) ),
    ) );
    $hidden = koopo_hidden_subscription_pack_ids();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Subscription Visibility', 'koopo' ); ?></h1>
        <p><?php esc_html_e( 'Select packs to hide from seller subscription choices, registration and package selection. Keep these products published so administrators can still assign them. This setting does not change existing subscriptions, automatic starter-pack assignment, or direct purchase eligibility.', 'koopo' ); ?></p>
        <?php settings_errors(); ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'koopo_subscription_visibility' ); ?>
            <input type="hidden" name="koopo_hidden_subscription_pack_ids[]" value="0">
            <fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Hidden subscription packs', 'koopo' ); ?></legend>
            <?php foreach ( $packs as $pack ) : ?>
                <p><label><input type="checkbox" name="koopo_hidden_subscription_pack_ids[]" value="<?php echo esc_attr( $pack->ID ); ?>" <?php checked( in_array( (int) $pack->ID, $hidden, true ) ); ?>>
                <?php echo esc_html( sprintf( '%s (#%d — %s)', $pack->post_title, $pack->ID, $pack->post_status ) ); ?></label></p>
            <?php endforeach; ?>
            <?php if ( ! $packs ) : ?><p><?php esc_html_e( 'No Dokan subscription packs found.', 'koopo' ); ?></p><?php endif; ?>
            </fieldset>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
