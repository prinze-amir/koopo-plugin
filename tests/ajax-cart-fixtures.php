<?php
/** Local HTTP/browser acceptance fixtures. Run with wp eval-file; delete with argument cleanup. */
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
    throw new RuntimeException( 'Local only.' );
}
$key = 'koopo_ajax_cart_test_fixtures';
$existing = get_option( $key, array() );
if ( ( $args[0] ?? '' ) === 'cleanup' ) {
    foreach ( array_reverse( $existing ) as $id ) { wp_delete_post( $id, true ); }
    delete_option( $key );
    echo "Fixtures removed.\n";
    return;
}
if ( $existing ) { echo wp_json_encode( $existing ); return; }
$ids = array();
foreach ( array( 'simple', 'limited' ) as $name ) {
    $p = new WC_Product_Simple();
    $p->set_name( 'Cart QA ' . $name );
    $p->set_status( 'publish' );
    $p->set_regular_price( '12' );
    $p->set_short_description( 'A comfortable everyday essential. Choose your quantity and keep browsing.' );
    if ( 'limited' === $name ) { $p->set_manage_stock( true ); $p->set_stock_quantity( 1 ); }
    $ids[$name] = $p->save();
}
$p = new WC_Product_Variable();
$p->set_name( 'Cart QA relaxed tee' );
$p->set_status( 'publish' );
$p->set_short_description( 'Soft cotton, an easy fit, and room to move. Choose your size below.' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Size' );
$attribute->set_options( array( 'Small', 'Large' ) );
$attribute->set_variation( true );
$p->set_attributes( array( $attribute ) );
$ids['variable'] = $p->save();
$v = new WC_Product_Variation();
$v->set_parent_id( $p->get_id() );
$v->set_status( 'publish' );
$v->set_attributes( array( 'size' => '' ) ); // Wildcard must preserve the customer's actual selection.
$v->set_regular_price( '24' );
$ids['variation'] = $v->save();
WC_Product_Variable::sync( $p->get_id() );
$g = new WC_Product_Grouped();
$g->set_name( 'Cart QA everyday set' );
$g->set_status( 'publish' );
$g->set_children( array( $ids['simple'], $ids['limited'], $ids['variable'] ) );
$ids['grouped'] = $g->save();
$ids['page'] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cart acceptance preview', 'post_content' => '[products ids="' . implode( ',', array( $ids['simple'], $ids['variable'], $ids['grouped'] ) ) . '"]' ) );
// Dokan rejects purchases from author 0. Supply an existing enabled local seller as argument.
$seller = absint( $args[0] ?? 0 );
if ( $seller ) {
    foreach ( $ids as $name => $id ) {
        if ( 'page' !== $name ) { wp_update_post( array( 'ID' => $id, 'post_author' => $seller ) ); }
    }
}
update_option( $key, $ids, false );
echo wp_json_encode( $ids );
