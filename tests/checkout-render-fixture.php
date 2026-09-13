<?php
/** Render real Woo/Koopo templates with in-memory fixtures; no orders, rates or provider calls. */
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
    throw new RuntimeException( 'Local fixture only.' );
}
$physical = ( $args[0] ?? 'physical' ) === 'physical';
WC()->initialize_session();
WC()->customer = new WC_Customer( 0 );
WC()->customer->set_billing_country( 'US' );
WC()->customer->set_billing_state( 'DC' );
WC()->customer->set_billing_city( 'Washington' );
WC()->customer->set_billing_postcode( '20500' );
WC()->customer->set_billing_address_1( '1600 Pennsylvania Avenue NW' );
WC()->cart = new WC_Cart();
$cart = array();
foreach ( array( array( 'Ceramic Daily Mug', 24 ), array( 'Monstera House Plant', 34 ) ) as $i => $item ) {
    $p = new WC_Product_Simple();
    $p->set_id( 900000 + $i ); $p->set_status( 'publish' ); $p->set_name( $item[0] ); $p->set_price( $item[1] ); $p->set_virtual( ! $physical );
    $cart['fixture-' . $i] = array( 'key' => 'fixture-' . $i, 'data' => $p, 'product_id' => 0, 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'line_subtotal' => $item[1], 'line_total' => $item[1], 'line_subtotal_tax' => 0, 'line_tax' => 0 );
}
WC()->cart->set_cart_contents( $cart );
WC()->cart->set_totals( array( 'subtotal' => 58, 'subtotal_tax' => 0, 'shipping_total' => $physical ? 6.99 : 0, 'shipping_tax' => 0, 'cart_contents_total' => 58, 'cart_contents_tax' => 0, 'total' => $physical ? 64.99 : 58, 'total_tax' => 0 ) );
// Only template rendering is substituted. No payment gateway is instantiated for this fixture.
remove_all_actions( 'woocommerce_before_checkout_form' );
remove_all_actions( 'woocommerce_checkout_order_review' );
remove_all_actions( 'woocommerce_before_checkout_billing_form' );
remove_all_actions( 'woocommerce_after_checkout_billing_form' );
add_filter( 'woocommerce_checkout_registration_enabled', '__return_false' );
add_filter( 'woocommerce_checkout_registration_required', '__return_false' );
add_filter( 'woocommerce_cart_needs_shipping', function () use ( $physical ) { return $physical; } );
add_filter( 'woocommerce_cart_ready_to_calc_shipping', '__return_false' );
add_filter( 'woocommerce_checkout_get_value', function ( $value, $key ) {
    $values = array( 'billing_first_name' => 'Taylor', 'billing_last_name' => 'Preview', 'billing_email' => 'preview@example.test', 'billing_phone' => '2025550147', 'billing_country' => 'US', 'billing_address_1' => '1600 Pennsylvania Avenue NW', 'billing_city' => 'Washington', 'billing_state' => 'DC', 'billing_postcode' => '20500' );
    return $values[ $key ] ?? $value;
}, 10, 2 );
add_action( 'woocommerce_checkout_order_review', function () use ( $physical ) {
    ob_start(); include KOOPO_PATH . 'templates/woocommerce/checkout/review-order.php'; $html = ob_get_clean();
    if ( $physical ) {
        $shipping = '<tr class="shipping"><th>Shipping: Hearth &amp; Home</th><td><input class="shipping_method" type="radio" name="shipping_method[0]" id="fixture_shipping_0" data-index="0" value="flat_rate:1" checked><label for="fixture_shipping_0">Standard Shipping: $3.99</label></td></tr><tr class="shipping"><th>Shipping: Greenify Co.</th><td><input class="shipping_method" type="hidden" name="shipping_method[1]" id="fixture_shipping_1" data-index="1" value="flat_rate:2"><label for="fixture_shipping_1">Standard Shipping: $3.00</label></td></tr>';
        $html = str_replace( 'Calculated during Delivery', '$6.99', $html );
        $html = str_replace( '<tfoot>', '<tfoot>' . $shipping, $html );
    }
    echo $html;
    echo '<div id="payment" class="woocommerce-checkout-payment"><ul class="wc_payment_methods payment_methods methods"><li><input type="radio" name="payment_method" value="fixture" id="payment_method_fixture" checked><label for="payment_method_fixture">Credit or Debit Card</label><div class="payment_box payment_method_fixture"><p>Isolated payment fixture — no charges</p><input id="fixture-payment-state" aria-label="Test payment state" value="retained"><iframe title="Fixture secure field" src="about:blank"></iframe></div></li></ul><div class="place-order"><label><input type="checkbox" id="terms" name="terms">I agree to the terms and conditions</label><input type="hidden" name="woocommerce-process-checkout-nonce" value="fixture-nonce"><button type="submit" id="place_order" name="woocommerce_checkout_place_order">Place Order</button></div></div>';
} );
$checkout = WC()->checkout();
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Koopo checkout — isolated preview</title><link rel="stylesheet" href="checkout.css"><style>body{margin:0;background:#fafaf9;font-family:Arial,sans-serif}main{max-width:1200px;margin:auto;padding:30px 20px}button,input,select,textarea{font-family:inherit}input[type=checkbox],input[type=radio]{width:18px;height:18px}.preview-label{font-size:12px;color:#65708e;margin:0 0 20px}.fixture-brand{font-size:23px;font-weight:bold;color:#101638}iframe{width:100%;height:40px;border:1px solid #dce1eb;border-radius:6px}.shipping_address{display:none}</style></head><body class="koopo-checkout-page"><main><div class="fixture-brand">KOOPO</div><p class="preview-label">Isolated layout preview · no payment processing</p>';
include KOOPO_PATH . 'templates/woocommerce/checkout/form-checkout.php';
echo '</main><script src="jquery.js"></script><script src="words.js"></script><script src="checkout.js"></script></body></html>';
