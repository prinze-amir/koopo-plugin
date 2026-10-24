<?php
/** Read-only projection checks. No orders are saved and no provider requests are made. */
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) throw new RuntimeException( 'Local only.' );
function kod_native_assert( $ok, $label ) { if ( ! $ok ) throw new RuntimeException( $label ); echo "PASS $label\n"; }
$context = Koopo_Order_Details_REST::context( '<div class="kod-feature"><img src="https://example.com/provider.jpg"><div><span class="kod-eyebrow">Service provider</span><h3>Marcus &amp; Reed</h3></div></div><div class="kod-booking-heading"><span class="kod-eyebrow">Appointment</span><h3>Hot Towel Shave</h3></div><dl><div class="kod-fact"><dt>Appointment status</dt><dd>Confirmed</dd></div></dl><a class="kod-button kod-primary" href="http://localhost:8085/my-account-koopo/appointments/">Manage booking<span> →</span></a><div class="kod-alert"><strong>Payment received.</strong><p>Do not pay again.</p></div>' );
kod_native_assert( 'Marcus & Reed' === $context['features'][0]['title'], 'provider feature and encoded text retained' );
kod_native_assert( 'Hot Towel Shave' === $context['features'][1]['title'], 'service heading retained separately' );
kod_native_assert( 'Confirmed' === $context['facts'][0]['value'], 'appointment status comes from website facts' );
kod_native_assert( 'Manage booking' === $context['actions'][0]['label'] && $context['actions'][0]['primary'], 'manage action preserves label, destination and priority' );
kod_native_assert( 1 === count( $context['alerts'] ), 'paid-but-unconfirmed warning preserved' );
$order = new WC_Order();
$order->set_status( 'completed' );
$order->set_date_created( '2026-09-14T01:11:00-04:00' );
$order->set_date_paid( '2026-09-14T01:11:00-04:00' );
$order->set_date_completed( '2026-09-14T01:12:00-04:00' );
$order->set_payment_method( 'stripe' );
$item = new WC_Order_Item_Product(); $item->set_name( 'Hot Towel Shave' ); $item->add_meta_data( 'bookingId', 999999 ); $item->add_meta_data( 'serviceName', 'Hot Towel Shave' ); $order->add_item( $item );
$model = Koopo_Order_Details_REST::model( $order );
kod_native_assert( 'booking' === $model['type'] && 'Booking Details' === $model['title'] && 'Booking Summary' === $model['summaryTitle'], 'booking labels match account template' );
kod_native_assert( ! $model['showShipping'] && $model['paidAt'] && $model['completedAt'], 'real milestones retained and shipping omitted for appointments' );
kod_native_assert( 'Payment verified' === $model['paymentLabel'], 'verified payment label matches account template' );
kod_native_assert( empty( $model['items'][0]['metadata'] ), 'internal appointment metadata remains hidden' );
$request = new WP_REST_Request( 'GET', '/wc/v3/orders' ); $response = new WP_REST_Response( array( 'id' => 1 ) );
kod_native_assert( ! isset( Koopo_Order_Details_REST::prepare( $response, $order, $request )->get_data()['koopo_details'] ), 'list requests do not generate expanded context' );
