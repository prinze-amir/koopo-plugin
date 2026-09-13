<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use WeDevs\DokanPro\Modules\StripeExpress\Processors\Payment;
use WeDevs\DokanPro\Modules\StripeExpress\Support\Config;
use WeDevs\DokanPro\Modules\StripeExpress\Support\Settings as ExpressSettings;
use WeDevs\DokanPro\Modules\StripeExpress\Support\OrderMeta;
use WeDevs\DokanPro\Modules\StripeExpress\Support\Helper;

/** Dedicated virtual support orders, independent of the shopping cart. */
class Koopo_Creator_Support_Checkout {
    public function hooks() {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_action( 'dokan_stripe_express_payment_completed', array( $this, 'payment_completed' ), 5, 2 );
        add_action( 'woocommerce_payment_complete', array( $this, 'reconcile' ), 30 );
        add_action( 'koopo_support_reconcile', array( $this, 'reconcile' ) );
    }

    public function routes() {
        register_rest_route( 'koopo/v1', '/creator-support/sessions', array(
            'methods' => 'POST', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'create' ),
            'args' => array( 'context' => array( 'required' => true, 'type' => 'string' ), 'amount' => array( 'required' => true, 'type' => 'number', 'minimum' => 1, 'maximum' => 10000 ), 'request_key' => array( 'required' => true, 'type' => 'string', 'minLength' => 16, 'maxLength' => 80 ), 'message' => array( 'type' => 'string', 'maxLength' => 500 ) ),
        ) );
        register_rest_route( 'koopo/v1', '/creator-support/sessions/(?P<id>\d+)', array(
            'methods' => 'GET', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'status' ),
        ) );
        register_rest_route( 'koopo/v1', '/creator-support/sessions/(?P<id>\d+)/confirm', array(
            'methods' => 'POST', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'confirm' ),
        ) );
    }

    public static function context_token( array $args ) {
        $data = base64_encode( wp_json_encode( array_intersect_key( $args, array_flip( array( 'creator_id', 'module', 'surface', 'context_post_id', 'context_post_type' ) ) ) ) );
        return $data . '.' . hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
    }

    public static function context( $token ) {
        $parts = explode( '.', (string) $token );
        if ( count( $parts ) !== 2 || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) { return null; }
        $data = json_decode( base64_decode( $parts[0], true ), true );
        return is_array( $data ) ? $data : null;
    }

    private function environment() {
        if ( method_exists( ExpressSettings::class, 'is_sandbox_mode' ) && ExpressSettings::is_sandbox_mode() ) { return 'sandbox'; }
        return ExpressSettings::is_test_mode() ? 'test' : 'live';
    }

    protected function client() { return Config::instance()->client; }
    protected function vendor_ready( $creator_id ) {
        if ( ! function_exists( 'koopo_payouts' ) ) { return false; }
        $service = koopo_payouts()->service();
        return method_exists( $service, 'can_accept_support_payment' ) && $service->can_accept_support_payment( (int) $creator_id );
    }

    private function error( $message, $status = 400 ) { return new WP_Error( 'koopo_support_checkout', $message, array( 'status' => $status ) ); }

    public function create( WP_REST_Request $request ) {
        $context = self::context( $request['context'] );
        $user_id = get_current_user_id();
        if ( ! $context || ! $user_id || empty( $context['creator_id'] ) || $user_id === (int) $context['creator_id'] ) { return $this->error( 'This support context is unavailable. Refresh the page and try again.' ); }
        if ( ! Koopo_Creator_Support_Settings::enabled( $context ) ) { return $this->error( 'Support is disabled for this location.', 403 ); }
        $amount = (float) $request['amount'];
        if ( ! is_finite( $amount ) || $amount < 1 || $amount > 10000 ) { return $this->error( 'Choose an amount between 1 and 10,000.' ); }
        $creator_id = (int) $context['creator_id'];
        $service = Koopo_Creator_Support::instance()->service();
        $state = $service->creator_support_access_state( $creator_id );
        if ( empty( $state['can_accept'] ) ) { return $this->error( 'This creator cannot accept support right now.' ); }
        $live = ( $context['surface'] ?? '' ) === 'live_paid_chat';
        $intent = null;
        if ( $live ) {
            $intent = apply_filters( 'koopo_support_live_context', null, $context, $request->get_param( 'message' ), $user_id );
            if ( is_wp_error( $intent ) ) { return $intent; }
            if ( ! is_array( $intent ) ) { return $this->error( 'Live support is unavailable.' ); }
        }
        if ( ! class_exists( Payment::class ) || ! class_exists( Config::class ) ) { return $this->error( 'Stripe Express is unavailable.', 503 ); }
        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( empty( $gateways['dokan_stripe_express'] ) || 'yes' !== $gateways['dokan_stripe_express']->enabled ) { return $this->error( 'Stripe Express is not enabled.', 503 ); }
        if ( class_exists( '\Koopo\Payouts\Settings' ) ) {
            $environment = ( new \Koopo\Payouts\Settings() )->stripe_environment();
            if ( $environment !== $this->environment() ) { return $this->error( 'Payment and payout environments do not match. Please contact Koopo.', 503 ); }
        }
        if ( ! $this->vendor_ready( $creator_id ) ) { return $this->error( 'This creator must finish payment setup before accepting support.' ); }
        $request_key = (string) $request['request_key'];
        if ( ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/', $request_key ) ) { return $this->error( 'Invalid checkout request.' ); }
        $key = 'kcs_session_' . hash( 'sha256', $user_id . ':' . $request_key );
        $fingerprint = hash( 'sha256', wp_json_encode( array( $context, wc_format_decimal( $amount, wc_get_price_decimals() ), $intent['body'] ?? '' ) ) );
        $saved = get_option( $key );
        if ( $saved && ! hash_equals( (string) $saved['fingerprint'], $fingerprint ) ) { return $this->error( 'This checkout already has a different amount or message. Start a new support payment.', 409 ); }
        if ( ! $saved ) {
            $rate_key = 'kcs_create_rate_' . $user_id;
            $count = (int) get_transient( $rate_key );
            if ( $count >= 10 ) { return $this->error( 'Too many checkout attempts. Please wait before trying again.', 429 ); }
            set_transient( $rate_key, $count + 1, 15 * MINUTE_IN_SECONDS );
        }
        if ( ! $saved && ! add_option( $key, array( 'fingerprint' => $fingerprint, 'order_id' => 0 ), '', false ) ) { return $this->error( 'Checkout is being prepared. Try again shortly.', 409 ); }
        $lock = $key . '_lock';
        if ( ! add_option( $lock, time(), '', false ) ) {
            if ( (int) get_option( $lock ) < time() - 180 ) { delete_option( $lock ); }
            return $this->error( 'Checkout is being prepared. Try again shortly.', 409 );
        }
        try {
            $saved = get_option( $key );
            $order = ! empty( $saved['order_id'] ) ? wc_get_order( $saved['order_id'] ) : null;
            if ( ! $order ) {
                $product_id = $service->product_id_for_creator( $creator_id );
                $product = wc_get_product( $product_id );
                if ( ! $product || ! $service->is_creator_support_product( $product_id, $creator_id ) ) { throw new RuntimeException( 'Support product is unavailable.' ); }
                if ( ! $product->is_virtual() ) { $product->set_virtual( true ); $product->save(); }
                $order = wc_create_order( array( 'customer_id' => $user_id, 'created_via' => 'koopo_creator_support' ) );
                if ( is_wp_error( $order ) ) { return $order; }
                // Save the idempotency binding before any provider call.
                update_option( $key, array( 'fingerprint' => $fingerprint, 'order_id' => $order->get_id() ), false );
                $order->set_currency( get_woocommerce_currency() );
                $order->set_payment_method( 'dokan_stripe_express' );
                $order->set_payment_method_title( 'Stripe Express' );
                $customer = new WC_Customer( $user_id );
                $billing = $customer->get_billing();
                $billing['email'] = $billing['email'] ?: get_userdata( $user_id )->user_email;
                $order->set_address( $billing, 'billing' );
                $order->update_meta_data( '_dokan_vendor_id', $creator_id );
                $order->update_meta_data( '_koopo_support_session', $fingerprint );
                $order->update_meta_data( '_koopo_support_environment', $this->environment() );
                $item = new WC_Order_Item_Product();
                $item->set_product( $product ); $item->set_quantity( 1 );
                $item->set_subtotal( wc_format_decimal( $amount, wc_get_price_decimals() ) ); $item->set_total( $item->get_subtotal() );
                $values = array( 'koopo_creator_support' => true, 'koopo_creator_support_creator_id' => $creator_id, 'koopo_creator_support_amount' => $amount, 'koopo_creator_support_module' => $context['module'] ?? 'general', 'koopo_creator_support_surface' => $context['surface'] ?? 'default', 'koopo_creator_support_context_id' => $context['context_post_id'] ?? 0, 'koopo_creator_support_context_type' => $context['context_post_type'] ?? '' );
                $service->persist_order_line_item_meta( $item, '', $values, $order );
                if ( $live ) {
                    $intent['product_id'] = $product_id; $intent['amount'] = $item->get_total();
                    $item->add_meta_data( '_koopo_paid_chat', $intent, true );
                    $item->add_meta_data( 'Public chat message', $intent['body'], true );
                }
                $order->add_item( $item ); $order->calculate_totals( false ); $order->save();
                if ( function_exists( 'dokan_sync_insert_order' ) ) { dokan_sync_insert_order( $order->get_id() ); }
                $order->update_meta_data( '_koopo_support_prepared', 'yes' ); $order->save();
            }
            if ( $order->get_meta( '_koopo_support_prepared' ) !== 'yes' ) { return $this->error( 'Order preparation was interrupted. Contact Koopo before retrying.', 409 ); }
            if ( $order->is_paid() ) { return $this->payload( $order ); }
            if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) { return $this->error( 'This support checkout is closed.', 409 ); }
            $client = $this->client();
            $id = OrderMeta::get_payment_intent( $order );
            if ( $id ) { $pi = $client->paymentIntents->retrieve( $id, array() ); }
            else {
                $data = Payment::generate_data( $order );
                $pi = $client->paymentIntents->create( array( 'amount' => Helper::get_stripe_amount( $order->get_total(), strtolower( $order->get_currency() ) ), 'currency' => strtolower( $order->get_currency() ), 'description' => $data['description'], 'metadata' => $data['metadata'], 'payment_method_types' => array( 'card' ), 'capture_method' => 'automatic' ), array( 'idempotency_key' => 'koopo-support-order-' . $order->get_id() ) );
                Payment::save_intent_data( $order, $pi );
            }
            if ( ! $this->matches( $order, $pi ) ) { throw new RuntimeException( 'Payment does not match this support order.' ); }
            if ( ! wp_next_scheduled( 'koopo_support_reconcile', array( $order->get_id() ) ) ) { wp_schedule_single_event( time() + 60, 'koopo_support_reconcile', array( $order->get_id() ) ); }
            return array_merge( $this->payload( $order ), array( 'client_secret' => $pi->client_secret, 'publishable_key' => ExpressSettings::get_publishable_key() ) );
        } catch ( Throwable $error ) {
            return $this->error( 'Unable to prepare payment. Please retry; your existing checkout will be reused.', 502 );
        } finally { delete_option( $lock ); }
    }

    private function owned( $id ) {
        $o = wc_get_order( absint( $id ) );
        return $o && $o->get_meta( '_koopo_support_session' ) && (int) $o->get_customer_id() === get_current_user_id() ? $o : null;
    }
    public function status( WP_REST_Request $r ) { $o = $this->owned( $r['id'] ); return $o ? $this->payload( $o ) : $this->error( 'Support checkout not found.', 404 ); }
    public function confirm( WP_REST_Request $r ) {
        $o = $this->owned( $r['id'] );
        if ( ! $o ) { return $this->error( 'Support checkout not found.', 404 ); }
        $this->reconcile( $o->get_id() );
        return $this->payload( wc_get_order( $o->get_id() ) );
    }
    private function matches( $o, $pi ) {
        return ( $pi->object ?? '' ) === 'payment_intent' && (int) ( $pi->amount ?? -1 ) === (int) Helper::get_stripe_amount( $o->get_total(), strtolower( $o->get_currency() ) ) && strtoupper( $pi->currency ?? '' ) === $o->get_currency() && (bool) $pi->livemode === ( $o->get_meta( '_koopo_support_environment' ) === 'live' ) && (string) $pi->id === (string) OrderMeta::get_payment_intent( $o );
    }
    public function reconcile( $id ) {
        $o = wc_get_order( $id );
        if ( ! $o || ! $o->get_meta( '_koopo_support_session' ) || $o->has_status( array( 'cancelled', 'refunded' ) ) ) { return; }
        try {
            $pi = $this->client()->paymentIntents->retrieve( OrderMeta::get_payment_intent( $o ), array() );
            if ( ! $this->matches( $o, $pi ) ) { return; }
            if ( 'succeeded' === $pi->status ) {
                if ( ! $o->is_paid() ) { Payment::process_confirmed_intent( $o, $pi->id, false ); }
                $this->payment_completed( wc_get_order( $id ), $pi );
            }
        } catch ( Throwable $error ) { /* Retry below without affecting provider callbacks. */ }
        $o = wc_get_order( $id );
        $tries = (int) $o->get_meta( '_koopo_support_retries' );
        if ( $tries < 24 && ( ! $o->is_paid() || $o->get_meta( '_koopo_support_chat_state' ) === 'pending' ) && ! wp_next_scheduled( 'koopo_support_reconcile', array( (int) $id ) ) ) {
            $o->update_meta_data( '_koopo_support_retries', $tries + 1 ); $o->save();
            wp_schedule_single_event( time() + 300, 'koopo_support_reconcile', array( (int) $id ) );
        }
    }
    public function payment_completed( $order, $pi = null ) {
        if ( ! $order instanceof WC_Order || ! $order->get_meta( '_koopo_support_session' ) || ! $pi || ! $this->matches( $order, $pi ) || 'succeeded' !== ( $pi->status ?? '' ) || empty( $pi->latest_charge ) || (int) ( $pi->amount_received ?? 0 ) !== (int) Helper::get_stripe_amount( $order->get_total(), strtolower( $order->get_currency() ) ) || ! $order->is_paid() ) { return; }
        $order->update_meta_data( '_koopo_support_verified', 'yes' ); $order->save();
        if ( self::support_only( $order ) && ! $order->has_status( 'completed' ) ) { $order->update_status( 'completed', 'Creator support payment verified; no fulfillment required.' ); }
        if ( self::support_only( $order ) && class_exists( '\WeDevs\DokanPro\Modules\StripeExpress\Processors\Withdraw' ) ) {
            \WeDevs\DokanPro\Modules\StripeExpress\Processors\Withdraw::process_vendor_balance_threshold( $order->get_id(), 0 );
        }
        do_action( 'koopo_support_payment_verified', $order->get_id() );
    }
    public static function support_only( $order ) {
        if ( ! $order || ! $order->get_items() || (float) $order->get_shipping_total() !== 0.0 || ! class_exists( 'Koopo_Creator_Support' ) ) { return false; }
        $service = Koopo_Creator_Support::instance()->service();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $item->get_meta( '_koopo_creator_support' ) || ! $product || ! $product->is_virtual() || ! $service->is_creator_support_product( $product->get_id(), (int) $item->get_meta( '_koopo_creator_support_creator_id' ) ) ) { return false; }
        }
        return true;
    }
    private function payload( $o ) {
        return array( 'order_id' => $o->get_id(), 'status' => $o->get_status(), 'paid' => $o->is_paid() && $o->get_meta( '_koopo_support_verified' ) === 'yes', 'chat_state' => (string) $o->get_meta( '_koopo_support_chat_state' ), 'amount' => $o->get_total(), 'currency' => $o->get_currency(), 'receipt_url' => $o->get_view_order_url(), 'billing' => array( 'name' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ), 'email' => $o->get_billing_email() ) );
    }
}
