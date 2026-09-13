<?php
/** No database writes. */
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) throw new RuntimeException( 'Local only.' );
function kod_assert( $ok, $label ) { if ( ! $ok ) throw new RuntimeException( $label ); echo "PASS $label\n"; }
foreach ( array( 'support' => '_koopo_creator_support', 'ticket' => '_koopo_ticket_type_id', 'booking' => '_koopo_booking_id', 'product' => '' ) as $type => $meta ) {
    $order = new WC_Order(); $item = new WC_Order_Item_Product(); $item->set_name( 'Example' ); if ( $meta ) $item->add_meta_data( $meta, 1 ); $order->add_item( $item );
    kod_assert( Koopo_Order_Details::type( $order ) === $type, "$type identified by saved line-item metadata" );
    if ( $meta ) { $order->add_item( new WC_Order_Item_Product() ); kod_assert( 'mixed' === Koopo_Order_Details::type( $order ), "mixed $type and products retain generic order title" ); }
}
kod_assert( 'original' === Koopo_Order_Details::template( 'original', 'order/order-details.php', '' ), 'thank-you/email order template is untouched outside account view' );
$item = new WC_Order_Item_Product(); $order = new WC_Order();
kod_assert( 0 === Koopo_Order_Details::seller( $item, $order ), 'deleted product does not inherit current page author' );
