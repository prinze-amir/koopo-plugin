<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'template_redirect', function () {
    if ( ! function_exists( 'WC' ) ) {
        return;
    }
    if ( ! WC()->cart || WC()->cart->is_empty() ) {
        return;
    }
    if ( ! is_cart() && ! is_checkout() ) {
        return;
    }

    $cart  = WC()->cart;
    $total = (float) $cart->get_total( 'edit' );
    if ( $total > 0 ) {
        return;
    }

    foreach ( $cart->get_cart() as $item ) {
        $product = $item['data'] ?? null;
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return;
        }
        if ( ! $product->is_type( 'product_pack' ) ) {
            return;
        }
    }

    $order = wc_create_order( [ 'customer_id' => get_current_user_id() ] );
    if ( is_wp_error( $order ) ) {
        return;
    }

    foreach ( $cart->get_cart() as $item ) {
        $order->add_product( $item['data'], $item['quantity'] );
    }

    $order->calculate_totals();
    $order->payment_complete();
    $order->update_status( 'completed' );

    $cart->empty_cart();

    $redirect = wp_get_referer() ?: home_url( '/' );
    wp_safe_redirect( $redirect );
    exit;
} );
