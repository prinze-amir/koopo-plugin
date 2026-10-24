<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Love push runs independently from checkout and the creator bell notification. */
class Koopo_Support_Push {
    public function hooks() {
        add_action( 'koopo_support_payment_verified', array( $this, 'enqueue' ), 20 );
        add_action( 'koopo_support_push_deliver', array( $this, 'deliver' ) );
        add_action( 'koopo_support_push_receipts', array( $this, 'receipts' ) );
        add_action( 'wp_ajax_koopo_love_push', array( $this, 'handle_dispatch' ) );
        add_action( 'wp_ajax_nopriv_koopo_love_push', array( $this, 'handle_dispatch' ) );
    }
    private function eligible( $order ) {
        return $order && $order->get_meta( '_koopo_support_session' ) && $order->is_paid() && $order->get_meta( '_koopo_support_verified' ) === 'yes' && ! $order->has_status( array( 'refunded', 'cancelled' ) );
    }
    public function enqueue( $id ) {
        $order = wc_get_order( $id );
        if ( ! $this->eligible( $order ) || $order->get_meta( '_koopo_love_push_finished' ) || wp_next_scheduled( 'koopo_support_push_deliver', array( (int) $id ) ) ) { return; }
        if ( wp_schedule_single_event( time() + 1, 'koopo_support_push_deliver', array( (int) $id ) ) ) {
            $timestamp = time();
            wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 0.01, 'blocking' => false,
                'body' => array( 'action' => 'koopo_love_push', 'order_id' => (int) $id, 'timestamp' => $timestamp,
                    'signature' => hash_hmac( 'sha256', (int) $id . ':' . $timestamp, wp_salt( 'nonce' ) ) ) ) );
        }
    }
    public static function valid_dispatch( $id, $timestamp, $signature ) {
        return $id > 0 && abs( time() - $timestamp ) <= 300 && is_string( $signature ) && hash_equals( hash_hmac( 'sha256', $id . ':' . $timestamp, wp_salt( 'nonce' ) ), $signature );
    }
    public function handle_dispatch() {
        $id = absint( $_POST['order_id'] ?? 0 ); $timestamp = absint( $_POST['timestamp'] ?? 0 );
        if ( ! self::valid_dispatch( $id, $timestamp, $_POST['signature'] ?? '' ) ) { wp_send_json_error( array( 'error' => 'Invalid delivery request.' ), 403 ); return; }
        $this->deliver( $id );
        wp_send_json_success();
    }
    public function deliver( $id ) {
        $order = wc_get_order( $id );
        if ( ! $this->eligible( $order ) || $order->get_meta( '_koopo_love_push_finished' ) ) { return; }
        $lock = 'kcs_push_lock_' . (int) $id;
        if ( ! add_option( $lock, time(), '', false ) ) {
            if ( (int) get_option( $lock ) < time() - 180 ) { delete_option( $lock ); $this->retry( $order ); }
            return;
        }
        try {
            if ( ! class_exists( 'Koopo_Mobile_API_Push_Notifications_Controller' ) ) { $this->retry( $order ); return; }
            $creator = (int) $order->get_meta( '_dokan_vendor_id' );
            if ( ! $creator || ! get_userdata( $creator ) ) { return; }
            $note = (string) $order->get_meta( '_koopo_support_note' );
            $buyer = get_userdata( $order->get_customer_id() );
            $name = wp_html_excerpt( $buyer ? $buyer->display_name : 'A supporter', 80, '…' );
            $amount = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
            $body = $name . ' sent you ' . $amount . '.';
            if ( $note !== '' ) { $body .= ' ' . wp_html_excerpt( $note, 140, '…' ); }
            $payload = array( 'title' => 'Koopo Love received', 'body' => $body, 'sound' => 'default', 'channelId' => 'koopo-love',
                'data' => array( 'type' => 'koopo_love', 'creatorId' => $creator, 'notificationId' => (int) $order->get_meta( '_koopo_love_notification_id' ),
                    'orderId' => $order->get_id(), 'amount' => (string) $order->get_total(), 'currency' => $order->get_currency(), 'note' => $note ) );
            $states = $order->get_meta( '_koopo_love_push_devices' );
            $transport = new Koopo_Mobile_API_Push_Notifications_Controller( false );
            $result = $transport->send_transactional_notification( $creator, $payload, is_array( $states ) ? $states : array(), function( $next ) use ( $order ) {
                $order->update_meta_data( '_koopo_love_push_devices', $next ); $order->save();
            } );
            if ( $result['retry'] ) { $this->retry( $order ); return; }
            $order->update_meta_data( '_koopo_love_push_finished', $result['eligible_devices'] ? 'processed' : 'no_devices' ); $order->save();
            wp_clear_scheduled_hook( 'koopo_support_push_deliver', array( (int) $id ) );
            foreach ( $result['states'] as $entry ) { if ( ( $entry['state'] ?? '' ) === 'accepted' ) { wp_schedule_single_event( time() + 900, 'koopo_support_push_receipts', array( (int) $id ) ); break; } }
        } catch ( Throwable $error ) { $this->retry( $order ); }
        finally { delete_option( $lock ); }
    }
    public function receipts( $id ) {
        $order = wc_get_order( $id );
        if ( ! $order || ! $order->get_meta( '_koopo_support_session' ) || ! class_exists( 'Koopo_Mobile_API_Push_Notifications_Controller' ) ) { return; }
        $states = $order->get_meta( '_koopo_love_push_devices' );
        if ( ! is_array( $states ) ) { return; }
        $transport = new Koopo_Mobile_API_Push_Notifications_Controller( false );
        $result = $transport->check_transactional_receipts( (int) $order->get_meta( '_dokan_vendor_id' ), $states );
        $order->update_meta_data( '_koopo_love_push_devices', $result['states'] );
        $attempts = (int) $order->get_meta( '_koopo_love_push_receipt_attempts' ) + 1;
        $order->update_meta_data( '_koopo_love_push_receipt_attempts', $attempts ); $order->save();
        if ( $result['pending'] && $attempts < 8 && ! wp_next_scheduled( 'koopo_support_push_receipts', array( (int) $id ) ) ) { wp_schedule_single_event( time() + 900, 'koopo_support_push_receipts', array( (int) $id ) ); }
    }
    private function retry( $order ) {
        $attempts = (int) $order->get_meta( '_koopo_love_push_attempts' );
        if ( $attempts >= 12 || wp_next_scheduled( 'koopo_support_push_deliver', array( $order->get_id() ) ) ) { return; }
        $order->update_meta_data( '_koopo_love_push_attempts', $attempts + 1 ); $order->save();
        wp_schedule_single_event( time() + min( 3600, 300 * pow( 2, min( $attempts, 4 ) ) ), 'koopo_support_push_deliver', array( $order->get_id() ) );
    }
}
