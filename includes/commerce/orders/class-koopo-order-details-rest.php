<?php
/** Read-only native projection of the account order layout. Woo REST owns authorization. */
defined( 'ABSPATH' ) || exit;
final class Koopo_Order_Details_REST {
    public static function boot() {
        add_filter( 'woocommerce_rest_prepare_shop_order_object', array( __CLASS__, 'prepare' ), 20, 3 );
    }
    public static function prepare( $response, $order, $request ) {
        // Keep lists lean. Only enrich the already-authorized, single-order GET response.
        if ( 'GET' !== $request->get_method() || ! preg_match( '#/orders/\d+/?$#', $request->get_route() ) || ! $order instanceof WC_Order ) return $response;
        $data = $response->get_data();
        $data['koopo_details'] = self::model( $order );
        $response->set_data( $data );
        return $response;
    }
    public static function text( $html ) {
        return trim( html_entity_decode( wp_strip_all_tags( preg_replace( '#<br\s*/?>#i', "\n", (string) $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }
    public static function model( $order ) {
        $type = Koopo_Order_Details::type( $order );
        $items = array();
        foreach ( $order->get_items() as $id => $item ) {
            if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) continue;
            $kind = Koopo_Order_Details::kind( $item );
            // Execute the website's owner-checked context renderer, not a second booking resolver.
            ob_start();
            try { Koopo_Order_Details::context( $item, $order ); $html = ob_get_contents(); }
            finally { ob_end_clean(); }
            $context = self::context( $html );
            $metadata = $item->get_formatted_meta_data();
            if ( 'booking' === $kind ) $metadata = Koopo_Order_Details::booking_formatted_meta( $metadata, $item );
            $context['metadata'] = array();
            foreach ( $metadata as $meta ) {
                $context['metadata'][] = array( 'label' => self::text( $meta->display_key ), 'value' => self::text( $meta->display_value ) );
            }
            $context['itemId'] = (int) $id;
            $context['kind'] = $kind;
            $context['sellerId'] = Koopo_Order_Details::seller( $item, $order );
            if ( $context['sellerId'] && function_exists( 'dokan' ) ) {
                $vendor = dokan()->vendor->get( $context['sellerId'] );
                if ( $vendor && $vendor->get_id() ) {
                    $context['sellerName'] = self::text( $vendor->get_shop_name() );
                    $context['sellerAvatarUrl'] = esc_url_raw( $vendor->get_avatar() );
                }
            }
            $product = $item->get_product();
            $context['purchaseNote'] = $product && $order->has_status( array( 'completed', 'processing' ) ) ? self::text( $product->get_purchase_note() ) : '';
            $items[] = $context;
        }
        $totals = array();
        foreach ( $order->get_order_item_totals() as $key => $total ) {
            if ( 'payment_method' === $key ) continue;
            $totals[] = array( 'label' => self::text( $total['label'] ), 'value' => self::text( $total['value'] ), 'strong' => 'order_total' === $key );
        }
        $actions = array();
        foreach ( wc_get_account_orders_actions( $order ) as $key => $action ) {
            if ( 'view' !== $key ) $actions[] = array( 'label' => self::text( $action['name'] ), 'url' => esc_url_raw( $action['url'] ), 'primary' => 'pay' === $key );
        }
        $updates = array();
        foreach ( $order->get_customer_order_notes() as $note ) $updates[] = array( 'date' => $note->comment_date, 'text' => self::text( $note->comment_content ) );
        $date = static function ( $value ) { return $value ? $value->format( 'c' ) : null; };
        return array(
            'version' => 1, 'type' => $type, 'title' => Koopo_Order_Details::title( $type ),
            'orderNumber' => $order->get_order_number(),
            'summaryTitle' => array( 'membership' => __( 'Membership Summary', 'koopo' ), 'site_plan' => __( 'Plan Summary', 'koopo' ), 'subscription' => __( 'Subscription Summary', 'koopo' ), 'support' => __( 'Transaction Summary', 'koopo' ), 'booking' => __( 'Booking Summary', 'koopo' ) )[ $type ] ?? __( 'Order Summary', 'koopo' ),
            'paymentLabel' => 'booking' === $type && Koopo_Order_Details::payment_is_verified( $order ) ? __( 'Payment verified', 'koopo' ) : __( 'Payment received', 'koopo' ),
            'createdAt' => $date( $order->get_date_created() ), 'paidAt' => $date( $order->get_date_paid() ), 'completedAt' => $date( $order->get_date_completed() ),
            'showShipping' => in_array( $type, array( 'product', 'mixed' ), true ),
            'items' => $items, 'totals' => $totals, 'actions' => $actions,
            'customerNote' => $order->get_customer_note(), 'updates' => $updates
        );
    }
    /** Parse only our own rendered context; no HTML or private metadata reaches the app. */
    public static function context( $html ) {
        $result = array( 'features' => array(), 'facts' => array(), 'actions' => array(), 'benefits' => array(), 'alerts' => array() );
        if ( ! $html || ! class_exists( 'DOMDocument' ) ) return $result;
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        try { $document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ); }
        finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
        $xpath = new DOMXPath( $document );
        $class = static function ( $name ) { return "contains(concat(' ', normalize-space(@class), ' '), ' $name ')"; };
        foreach ( $xpath->query( '//div[' . $class( 'kod-feature' ) . ' or ' . $class( 'kod-booking-heading' ) . ']' ) as $node ) {
            $heading = $xpath->query( './/h3', $node )->item( 0 );
            $eyebrow = $xpath->query( './/span[' . $class( 'kod-eyebrow' ) . ']', $node )->item( 0 );
            $image = $xpath->query( './/img', $node )->item( 0 );
            $description = $xpath->query( './/p', $node )->item( 0 );
            $result['features'][] = array( 'title' => $heading ? self::text( $heading->textContent ) : '', 'label' => $eyebrow ? self::text( $eyebrow->textContent ) : '', 'imageUrl' => $image ? esc_url_raw( $image->getAttribute( 'src' ) ) : '', 'description' => $description ? self::text( $description->textContent ) : '', 'actions' => self::actions( $xpath->query( './/a[' . $class( 'kod-button' ) . ']', $node ) ) );
        }
        if ( ! $result['features'] ) {
            $heading = $xpath->query( '//h3' )->item( 0 );
            $eyebrow = $xpath->query( '//span[' . $class( 'kod-eyebrow' ) . ']' )->item( 0 );
            if ( $heading ) $result['features'][] = array( 'title' => self::text( $heading->textContent ), 'label' => $eyebrow ? self::text( $eyebrow->textContent ) : '' );
        }
        foreach ( $xpath->query( '//div[' . $class( 'kod-fact' ) . ']' ) as $node ) {
            $label = $xpath->query( './dt', $node )->item( 0 ); $value = $xpath->query( './dd', $node )->item( 0 );
            if ( $label && $value ) $result['facts'][] = array( 'label' => self::text( $label->textContent ), 'value' => self::text( $value->textContent ) );
        }
        $result['actions'] = self::actions( $xpath->query( '//a[' . $class( 'kod-button' ) . ' and not(ancestor::div[' . $class( 'kod-feature' ) . '])]' ) );
        foreach ( $xpath->query( '//ul/li' ) as $node ) $result['benefits'][] = self::text( $node->textContent );
        foreach ( $xpath->query( '//div[' . $class( 'kod-alert' ) . ']' ) as $node ) $result['alerts'][] = self::text( $node->textContent );
        return $result;
    }
    private static function actions( $nodes ) {
        $actions = array();
        foreach ( $nodes as $node ) $actions[] = array( 'label' => trim( str_replace( '→', '', self::text( $node->textContent ) ) ), 'url' => esc_url_raw( $node->getAttribute( 'href' ) ), 'primary' => in_array( 'kod-primary', explode( ' ', $node->getAttribute( 'class' ) ), true ) );
        return $actions;
    }

}
