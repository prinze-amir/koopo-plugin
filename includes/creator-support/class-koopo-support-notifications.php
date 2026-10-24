<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private creator bell notifications shared by web and mobile, never sent for an unpaid intent. */
class Koopo_Support_Notifications {
    const COMPONENT = 'koopo_love';
    public function hooks() {
        add_action( 'koopo_support_payment_verified', array( $this, 'deliver' ) );
        add_action( 'koopo_support_notification_retry', array( $this, 'deliver' ) );
        add_action( 'bp_setup_globals', array( $this, 'component' ), 20 );
        add_filter( 'bp_notifications_get_registered_components', array( $this, 'registered' ) );
        add_filter( 'bb_notifications_get_registered_components', array( $this, 'registered' ) );
        add_filter( 'bp_notifications_get_notifications_for_user', array( $this, 'format' ), 10, 9 );
        add_filter( 'bb_notifications_get_component_notification', array( $this, 'format' ), 10, 9 );
    }
    public function registered( $components ) { $components[] = self::COMPONENT; return array_unique( $components ); }
    public function component() {
        if ( ! function_exists( 'buddypress' ) ) { return; }
        $bp = buddypress();
        $bp->{self::COMPONENT} = (object) array( 'id' => self::COMPONENT, 'slug' => self::COMPONENT, 'notification_callback' => array( $this, 'callback' ) );
    }
    public function deliver( $id ) {
        $order = wc_get_order( $id );
        if ( ! $order || ! $order->get_meta( '_koopo_support_session' ) || ! $order->is_paid() || $order->get_meta( '_koopo_support_verified' ) !== 'yes' || $order->has_status( array( 'refunded', 'cancelled' ) ) || $order->get_meta( '_koopo_love_notification_id' ) ) { return; }
        $lock = 'kcs_notification_lock_' . (int) $id;
        if ( ! add_option( $lock, time(), '', false ) ) {
            if ( (int) get_option( $lock ) < time() - 180 ) { delete_option( $lock ); $this->retry( $order ); }
            return;
        }
        try {
            $creator = (int) $order->get_meta( '_dokan_vendor_id' );
            if ( ! $creator || ! get_userdata( $creator ) || ! function_exists( 'bp_notifications_add_notification' ) ) { $this->retry( $order ); return; }
            // Recover a successful insert even if a worker stopped before saving the order marker.
            $existing = BP_Notifications_Notification::get( array( 'user_id' => $creator, 'item_id' => (int) $id, 'component_name' => self::COMPONENT, 'component_action' => 'love_received', 'is_new' => null ) );
            $notification_id = $existing ? (int) reset( $existing )->id : bp_notifications_add_notification( array(
                'user_id' => $creator, 'item_id' => (int) $id, 'secondary_item_id' => $order->get_customer_id(),
                'component_name' => self::COMPONENT, 'component_action' => 'love_received', 'is_new' => 1, 'allow_duplicate' => false
            ) );
            if ( ! $notification_id ) { $this->retry( $order ); return; }
            $order->update_meta_data( '_koopo_love_notification_id', $notification_id ); $order->save();
            wp_clear_scheduled_hook( 'koopo_support_notification_retry', array( (int) $id ) );
        } finally { delete_option( $lock ); }
    }
    private function retry( $order ) {
        $attempts = (int) $order->get_meta( '_koopo_love_notification_attempts' );
        if ( $attempts >= 12 || wp_next_scheduled( 'koopo_support_notification_retry', array( $order->get_id() ) ) ) { return; }
        $order->update_meta_data( '_koopo_love_notification_attempts', $attempts + 1 ); $order->save();
        wp_schedule_single_event( time() + 300, 'koopo_support_notification_retry', array( $order->get_id() ) );
    }
    private function content( $id, $format ) {
        $order = wc_get_order( $id );
        if ( ! $order || $order->get_meta( '_koopo_support_verified' ) !== 'yes' ) { return 'Koopo Love received'; }
        $buyer = get_userdata( $order->get_customer_id() );
        $name = $buyer ? $buyer->display_name : 'A supporter';
        $amount = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
        $text = $name . ' sent you ' . $amount . ' Koopo Love.';
        $note = (string) $order->get_meta( '_koopo_support_note' );
        if ( $note !== '' ) { $text .= ' ' . $note; }
        // Keep the note private; link to the creator's own notification center.
        $url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( (int) $order->get_meta( '_dokan_vendor_id' ) ) . 'notifications/' : home_url( '/my-account/' );
        return 'string' === $format ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : array( 'text' => esc_html( $text ), 'link' => esc_url( $url ) );
    }
    public function callback( $action, $item, $secondary, $count, $format = 'string' ) { return $this->content( $item, $format ); }
    public function format( $content, $item, $secondary, $count, $format, $action, $component, $id = 0, $screen = '' ) {
        return self::COMPONENT === $component ? $this->content( $item, $format ) : $content;
    }
}
