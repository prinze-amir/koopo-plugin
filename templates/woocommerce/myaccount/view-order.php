<?php
/** Koopo account view, based on WooCommerce myaccount/view-order.php 10.6.0. */
defined( 'ABSPATH' ) || exit;
if ( ! $order instanceof WC_Order || ! current_user_can( 'view_order', $order->get_id() ) ) { wc_print_notice( __( 'Invalid order.', 'woocommerce' ), 'error' ); return; }
$type = Koopo_Order_Details::type( $order );
$notes = $order->get_customer_order_notes();
?>
<div class="kod kod--<?php echo esc_attr( $type ); ?>">
    <nav class="kod-breadcrumb" aria-label="<?php esc_attr_e( 'Order navigation', 'koopo' ); ?>"><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">← <?php esc_html_e( 'Orders', 'koopo' ); ?></a><span>/</span><span><?php echo esc_html( '#' . $order->get_order_number() ); ?></span></nav>
    <header class="kod-header"><div><h1><?php echo esc_html( Koopo_Order_Details::title( $type ) ); ?></h1><p><?php echo esc_html( sprintf( __( 'Order #%s', 'koopo' ), $order->get_order_number() ) ); ?><?php if ( $order->get_date_created() ) : ?> · <?php echo esc_html( wc_format_datetime( $order->get_date_created(), get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ) ) ); ?><?php endif; ?></p></div><span class="kod-status" aria-label="<?php esc_attr_e( 'Order status', 'koopo' ); ?>"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></header>
    <div class="kod-layout"><div class="kod-main">
        <section class="kod-panel kod-overview" aria-label="<?php esc_attr_e( 'Order progress', 'koopo' ); ?>">
            <ol class="kod-progress">
            <?php foreach ( array( array( __( 'Order placed', 'koopo' ), $order->get_date_created() ), array( __( 'Payment received', 'koopo' ), $order->get_date_paid() ), array( __( 'Order completed', 'koopo' ), $order->get_date_completed() ) ) as $milestone ) : ?>
                <li class="<?php echo $milestone[1] ? 'is-recorded' : ''; ?>"><span class="kod-dot" aria-hidden="true"><?php echo $milestone[1] ? '✓' : '–'; ?></span><strong><?php echo esc_html( $milestone[0] ); ?></strong><span><?php echo $milestone[1] ? esc_html( wc_format_datetime( $milestone[1] ) ) : esc_html__( 'Not recorded', 'koopo' ); ?></span></li>
            <?php endforeach; ?>
            </ol>
            <dl class="kod-facts kod-overview-facts"><?php Koopo_Order_Details::fact( __( 'Payment method', 'koopo' ), esc_html( $order->get_payment_method_title() ) ); Koopo_Order_Details::fact( __( 'Contact email', 'koopo' ), esc_html( $order->get_billing_email() ) ); if ( in_array( $type, array( 'product', 'mixed' ), true ) ) Koopo_Order_Details::fact( __( 'Shipping address', 'koopo' ), $order->get_formatted_shipping_address() ); ?></dl>
        </section>
        <?php
        $previous = Koopo_Order_Details::$active_order;
        Koopo_Order_Details::$active_order = $order;
        // Replace only the old single-booking summary; retain every other extension hook.
        $callback = array( 'Koopo_Appointments\Order_Display', 'display_booking_details' );
        $priority = has_action( 'woocommerce_order_details_before_order_table', $callback );
        if ( false !== $priority ) remove_action( 'woocommerce_order_details_before_order_table', $callback, $priority );
        try { do_action( 'woocommerce_view_order', $order->get_id() ); }
        finally { Koopo_Order_Details::$active_order = $previous; if ( false !== $priority ) add_action( 'woocommerce_order_details_before_order_table', $callback, $priority ); }
        ?>
        <?php if ( $notes ) : ?><section class="kod-panel"><h2><?php esc_html_e( 'Order updates', 'koopo' ); ?></h2><ol class="kod-updates"><?php foreach ( $notes as $note ) : ?><li><time><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $note->comment_date ) ) ); ?></time><div><?php echo wp_kses_post( wpautop( $note->comment_content ) ); ?></div></li><?php endforeach; ?></ol></section><?php endif; ?>
    </div><aside class="kod-sidebar" aria-label="<?php esc_attr_e( 'Order summary', 'koopo' ); ?>">
        <section class="kod-panel"><h2><?php echo esc_html( array( 'membership' => __( 'Membership Summary', 'koopo' ), 'site_plan' => __( 'Plan Summary', 'koopo' ), 'subscription' => __( 'Subscription Summary', 'koopo' ), 'support' => __( 'Transaction Summary', 'koopo' ), 'booking' => __( 'Booking Summary', 'koopo' ) )[ $type ] ?? __( 'Order Summary', 'koopo' ) ); ?></h2>
            <dl class="kod-totals"><?php foreach ( $order->get_order_item_totals() as $key => $total ) : if ( 'payment_method' === $key ) continue; ?><div class="<?php echo 'order_total' === $key ? 'kod-total' : ''; ?>"><dt><?php echo esc_html( $total['label'] ); ?></dt><dd><?php echo wp_kses_post( $total['value'] ); ?></dd></div><?php endforeach; ?></dl>
            <div class="kod-actions"><?php foreach ( wc_get_account_orders_actions( $order ) as $key => $action ) { if ( 'view' !== $key ) Koopo_Order_Details::action( $action['url'], $action['name'], 'pay' === $key ); } ?></div>
        </section>
        <?php if ( $order->get_customer_note() ) : ?><section class="kod-panel"><h2><?php esc_html_e( 'Your note', 'koopo' ); ?></h2><p><?php echo nl2br( esc_html( $order->get_customer_note() ) ); ?></p></section><?php endif; ?>
        <div class="kod-help"><strong><?php esc_html_e( 'Need help with this order?', 'koopo' ); ?></strong><p><?php esc_html_e( 'Keep your order number handy when contacting support.', 'koopo' ); ?></p><?php $contact_page = get_page_by_path( 'koopo-contact' ); if ( $contact_page && is_post_publicly_viewable( $contact_page ) ) Koopo_Order_Details::action( get_permalink( $contact_page ), __( 'Contact support', 'koopo' ) ); else Koopo_Order_Details::action( wc_get_account_endpoint_url( 'orders' ), __( 'Back to orders', 'koopo' ) ); ?></div>
    </aside></div>
</div>
