<?php
/** Scoped account order details; WooCommerce order/order-details.php 10.9.0 hooks retained. */
defined( 'ABSPATH' ) || exit;
if ( Koopo_Order_Details::$active_order && (int) $order_id !== (int) Koopo_Order_Details::$active_order->get_id() ) { include WC_ABSPATH . 'templates/order/order-details.php'; return; }
$order = wc_get_order( $order_id );
if ( ! $order || ! current_user_can( 'view_order', $order->get_id() ) ) return;
$order_items = $order->get_items( apply_filters( 'woocommerce_purchase_order_item_types', 'line_item' ) );
$show_purchase_note = $order->has_status( apply_filters( 'woocommerce_purchase_note_order_statuses', array( 'completed', 'processing' ) ) );
if ( ! empty( $show_downloads ) ) wc_get_template( 'order/order-downloads.php', array( 'downloads' => $order->get_downloadable_items(), 'show_title' => true ) );
do_action( 'woocommerce_order_details_before_order_table', $order );
$groups = array();
foreach ( $order_items as $item_id => $item ) {
    if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) continue;
    $kind = Koopo_Order_Details::kind( $item );
    $seller = Koopo_Order_Details::seller( $item, $order );
    $key = 'product' === $kind ? 'product-' . $seller : $kind . '-' . $item_id;
    if ( ! isset( $groups[ $key ] ) ) $groups[ $key ] = array( 'kind' => $kind, 'seller' => $seller, 'items' => array() );
    $groups[ $key ]['items'][ $item_id ] = $item;
}
// Preserve table-specific extension callbacks in a valid table context.
echo '<table class="kod-extension-table"><tbody>'; do_action( 'woocommerce_order_details_before_order_table_items', $order ); echo '</tbody></table>';
foreach ( $groups as $group ) : ?>
<section class="kod-panel kod-group kod-group--<?php echo esc_attr( $group['kind'] ); ?>">
    <header class="kod-group-heading"><h2><?php echo esc_html( array( 'membership' => __( 'Your membership', 'koopo' ), 'site_plan' => __( 'Your site plan', 'koopo' ), 'subscription' => __( 'Your subscription', 'koopo' ), 'product' => __( 'Items by seller', 'koopo' ), 'ticket' => __( 'Event tickets', 'koopo' ), 'booking' => __( 'Appointment details', 'koopo' ), 'support' => __( 'Your support', 'koopo' ) )[ $group['kind'] ] ); ?></h2>
    <?php if ( 'product' === $group['kind'] && $group['seller'] && function_exists( 'dokan' ) ) { $vendor = dokan()->vendor->get( $group['seller'] ); if ( $vendor && $vendor->get_id() ) Koopo_Order_Details::action( $vendor->get_shop_url(), $vendor->get_shop_name() ); } ?></header>
    <?php foreach ( $group['items'] as $item_id => $item ) Koopo_Order_Details::context( $item, $order ); ?>
    <table class="woocommerce-table woocommerce-table--order-details shop_table order_details kod-items"><thead><tr><th><?php esc_html_e( 'Item', 'koopo' ); ?></th><th><?php esc_html_e( 'Total', 'koopo' ); ?></th></tr></thead><tbody>
    <?php foreach ( $group['items'] as $item_id => $item ) {
        $product = $item->get_product();
        // Native item renderer retains refunded quantities, metadata, purchase notes and filters.
        $thumbnail = static function ( $name, $line_item ) use ( $item_id, $product ) { if ( $line_item->get_id() !== $item_id || ! $product ) return $name; return $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'kod-item-image' ) ) . $name; };
        add_filter( 'woocommerce_order_item_name', $thumbnail, 20, 2 );
        $purchase_note = $product ? $product->get_purchase_note() : '';
        // The theme's legacy item override has four columns; this layout uses core's two-column row.
        $vendor_priority = has_action( 'woocommerce_order_item_meta_start', 'dokan_attach_vendor_name' );
        $hide_vendor = in_array( Koopo_Order_Details::kind( $item ), array( 'site_plan', 'membership' ), true );
        if ( $hide_vendor && false !== $vendor_priority ) remove_action( 'woocommerce_order_item_meta_start', 'dokan_attach_vendor_name', $vendor_priority );
        try { include WC_ABSPATH . 'templates/order/order-details-item.php'; }
        finally { if ( $hide_vendor && false !== $vendor_priority ) add_action( 'woocommerce_order_item_meta_start', 'dokan_attach_vendor_name', $vendor_priority, 2 ); remove_filter( 'woocommerce_order_item_name', $thumbnail, 20 ); }
    } ?>
    </tbody></table>
</section>
<?php endforeach;
echo '<table class="kod-extension-table"><tbody>'; do_action( 'woocommerce_order_details_after_order_table_items', $order ); echo '</tbody></table>';
echo '<div class="kod-extensions">'; do_action( 'woocommerce_order_details_after_order_table', $order ); do_action( 'woocommerce_after_order_details', $order ); echo '</div>';
if ( (int) $order->get_user_id() === get_current_user_id() ) {
    echo '<details class="kod-panel kod-addresses"><summary>' . esc_html__( 'Billing and contact details', 'koopo' ) . '</summary>';
    wc_get_template( 'order/order-details-customer.php', array( 'order' => $order ) );
    echo '</details>';
}
