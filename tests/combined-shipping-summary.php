<?php
// Run with wp eval-file to exercise the checkout package heading with real products.
$rendering = new ReflectionProperty( 'Koopo_Checkout', 'rendering' );
$rendering->setAccessible( true );
$rendering->setValue( true );

$sweater = wc_get_product( 8862 );
$glasses = wc_get_product( 8496 );
if ( ! $sweater || ! $glasses ) {
	throw new RuntimeException( 'The beta checkout products are unavailable in this environment.' );
}

$html = Koopo_Checkout::shipping_package_name( 'Shipping: So Drippy', 0, array(
	'contents' => array(
		'sweater' => array( 'data' => $sweater, 'quantity' => 2 ),
		'glasses' => array( 'data' => $glasses, 'quantity' => 1 ),
	),
) );
$plain = wp_strip_all_tags( $html );
foreach ( array( '3 items in this package', 'Goku Sweater', 'Blue Light Glasses', 'Quantity: 2', 'Quantity: 1' ) as $expected ) {
	if ( false === strpos( $plain, $expected ) ) {
		throw new RuntimeException( 'Missing checkout package summary: ' . $expected );
	}
}
if ( 2 !== substr_count( $html, 'class="kc-shipping-product"' ) ) {
	throw new RuntimeException( 'Expected two product rows in the shipping summary.' );
}
echo "Combined shipping summary passed: {$plain}\n";
