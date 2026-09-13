<?php
/** Read-only local rendering fixture. Never creates orders or submits provider requests. */
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) throw new RuntimeException( 'Local only.' );
$order_id = absint( $args[0] ?? 0 );
$order = wc_get_order( $order_id );
if ( ! $order || ! $order->get_user_id() ) throw new RuntimeException( 'Existing customer order required.' );
wp_set_current_user( ( $args[1] ?? '' ) === 'denied' ? 0 : $order->get_user_id() );
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/order-details.css"><style>body{margin:0;background:#fafaf9;font-family:Arial,sans-serif}#preview{max-width:1280px;margin:auto;padding:24px}.preview-note{font-size:12px}table{width:100%}.col2-set{display:flex;gap:24px}.col2-set>div{flex:1}@media(max-width:800px){#preview{padding:16px}.col2-set{display:block}}</style></head><body><div id="preview"><p class="preview-note">Local order rendering · no payment or order changes</p>';
WC_Shortcode_My_Account::view_order( $order_id );
echo '</div></body></html>';
