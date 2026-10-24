<?php
/** Standalone contract tests; no WordPress database, provider requests or funds. */
namespace WeDevs\DokanPro\Modules\StripeExpress\Support {
    class Helper { public static function get_stripe_amount( $amount, $currency ) { return (int) round( $amount * 100 ); } }
    class OrderMeta { public static function get_payment_intent( $order ) { return 'pi_order_' . $order->get_id(); } }
}
namespace {
define( 'ABSPATH', __DIR__ );
$options = array(); $orders = array(); $user_id = 100;
function add_action( ...$args ) {}
function add_option( $key, $value, ...$args ) { global $options; if ( isset( $options[$key] ) ) { return false; } $options[$key] = $value; return true; }
function get_option( $key ) { global $options; return $options[$key] ?? false; }
function delete_option( $key ) { global $options; unset( $options[$key] ); }
function get_transient( $key ) { return get_option( $key ); }
function set_transient( $key, $value, $ttl ) { global $options; $options[$key] = $value; }
function wp_schedule_single_event( ...$args ) {}
function wp_clear_scheduled_hook( ...$args ) {}
function wp_salt( $kind ) { return 'fixture-secret'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_current_user_id() { global $user_id; return $user_id; }
function wp_set_current_user( $id ) { global $user_id; $user_id = $id; }
function get_userdata( $id ) { return $id > 0 ? (object) array( 'ID' => $id ) : null; }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function wc_get_order( $id ) { global $orders; return $orders[$id] ?? null; }
class WP_Error { public $message; public $data; public function __construct( $code, $message, $data ) { $this->message = $message; $this->data = $data; } }
class WP_REST_Response { public $data; public function __construct( $data ) { $this->data = $data; } public function header( ...$args ) {} }
class WP_REST_Request implements \ArrayAccess {
    private $params = array(); private $headers = array();
    public function __construct( $method ) {}
    public function set_param( $key, $value ) { $this->params[$key] = $value; }
    public function get_param( $key ) { return $this->params[$key] ?? null; }
    public function set_header( $key, $value ) { $this->headers[$key] = $value; }
    public function get_header( $key ) { return $this->headers[$key] ?? ''; }
    public function offsetExists( $key ): bool { return isset( $this->params[$key] ); }
    public function offsetGet( $key ): mixed { return $this->get_param( $key ); }
    public function offsetSet( $key, $value ): void { $this->set_param( $key, $value ); }
    public function offsetUnset( $key ): void { unset( $this->params[$key] ); }
}
class Browser_Test_Order {
    public $id; public $owner = 100; public $status = 'pending';
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_customer_id() { return $this->owner; }
    public function get_meta( $key ) { return $key === '_koopo_support_environment' ? 'test' : ( $key === '_koopo_support_verified' ? '' : 'fixture' ); }
    public function is_paid() { return $this->status === 'completed'; }
    public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
    public function get_status() { return $this->status; }
    public function get_total() { return '10.00'; }
    public function get_currency() { return 'USD'; }
    public function get_view_order_url() { return 'https://example.test/receipt'; }
    public function get_billing_first_name() { return 'Test'; }
    public function get_billing_last_name() { return 'Customer'; }
    public function get_billing_email() { return 'test@example.test'; }
    public function update_status( $status, $message ) { $this->status = $status; }
}
class Koopo_Creator_Support { public static function instance() { return new self(); } public function service() { return $this; } public function creator_display_name( $id ) { return 'Fixture creator'; } }
require __DIR__ . '/../includes/creator-support/class-koopo-creator-support-checkout.php';
require __DIR__ . '/../includes/creator-support/class-koopo-support-browser-checkout.php';
class Browser_Test_Checkout extends Koopo_Creator_Support_Checkout {
    public $calls = array();
    public function create( $request ) { $this->calls[] = get_current_user_id(); return array( 'order_id' => 42, 'paid' => false, 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD', 'client_secret' => 'fixture' ); }
    public function status( $request ) { return array( 'order_id' => $request['id'], 'paid' => false ); }
    public function confirm( $request ) { return array( 'order_id' => $request['id'], 'paid' => false ); }
}
function check( $value, $label ) { if ( ! $value ) { throw new \RuntimeException( $label ); } echo "PASS $label\n"; }
function request( $params = array(), $token = '' ) { $r = new WP_REST_Request( 'POST' ); foreach ( $params as $key => $value ) { $r->set_param( $key, $value ); } $r->set_header( 'x-koopo-checkout-token', $token ); return $r; }
$sessions = new Koopo_Support_Browser_Sessions();
$checkout = new Browser_Test_Checkout();
$browser = new Koopo_Support_Browser_Checkout( $sessions, $checkout );
$orders[42] = new Browser_Test_Order( 42 );
$created = $browser->create( request( array( 'creator_id' => 200, 'amount' => 10, 'request_key' => 'browser-fixture-1234', 'user_id' => 999 ) ) )->data;
check( isset( $created['code'] ) && ! isset( $created['client_secret'] ), 'handoff excludes payment secrets' );
check( $sessions->read( 'handoff', $created['code'] )['user_id'] === 100, 'customer is bound to authenticated identity' );
check( ! is_wp_error( $browser->exchange( request( array( 'code' => $created['code'] ) ) ) ), 'single-use link redeems' );
check( is_wp_error( $browser->exchange( request( array( 'code' => $created['code'] ) ) ) ), 'replayed link is rejected' );
$code = $sessions->issue( array( 'user_id' => 100, 'order_id' => 42, 'creator_id' => 200, 'context' => 'fixture', 'amount' => 10, 'request_key' => 'browser-fixture-1234' ) );
$exchange = $browser->exchange( request( array( 'code' => $code ) ) )->data;
$token = $exchange['session_token'];
check( ! is_wp_error( $browser->exchange( request( array( 'code' => $code ), $token ) ) ), 'same browser may recover a response using its issued credential' );
wp_set_current_user( 999 );
$snapshot = $browser->status( request( array( 'order_id' => 9000 ), $token ) )->data;
check( $snapshot['order_id'] === 42 && end( $checkout->calls ) === 100, 'capability scopes to original customer and order' );
check( get_current_user_id() === 999, 'temporary customer context is restored' );
check( is_wp_error( $browser->status( request( array(), str_repeat( 'a', 64 ) ) ) ), 'unknown capability cannot read an order' );
$orders[42]->owner = 101;
check( is_wp_error( $browser->status( request( array(), $token ) ) ), 'ownership changes invalidate scoped access' );
$orders[42]->owner = 100;
foreach ( $options as $key => $value ) { if ( strpos( $key, 'kcs_browser_session_' ) === 0 ) { $options[$key]['expires_at'] = time() - 1; } }
check( is_wp_error( $browser->status( request( array(), $token ) ) ), 'expired session fails closed' );
check( is_wp_error( $browser->create( request( array( 'creator_id' => 200, 'amount' => INF, 'request_key' => 'browser-fixture-1234' ) ) ) ), 'non-finite amounts rejected' );

class Browser_Test_Cancel extends Koopo_Creator_Support_Checkout {
    public $pi; public $cancelled = 0;
    protected function client() {
        $api = new class( $this ) {
            private $test; public function __construct( $test ) { $this->test = $test; }
            public function retrieve( ...$args ) { return $this->test->pi; }
            public function cancel( ...$args ) { $this->test->cancelled++; $this->test->pi->status = 'canceled'; return $this->test->pi; }
        };
        return (object) array( 'paymentIntents' => $api );
    }
}
wp_set_current_user( 100 );
$cancel = new Browser_Test_Cancel();
$cancel->pi = (object) array( 'object' => 'payment_intent', 'amount' => 1000, 'currency' => 'usd', 'livemode' => false, 'id' => 'pi_order_42', 'status' => 'processing' );
check( is_wp_error( $cancel->cancel( request( array( 'id' => 42 ) ) ) ) && $cancel->cancelled === 0, 'processing payment is never cancelled' );
$cancel->pi->status = 'requires_payment_method'; $cancel->pi->amount = 999;
check( is_wp_error( $cancel->cancel( request( array( 'id' => 42 ) ) ) ) && $cancel->cancelled === 0, 'mismatched payment is never cancelled' );
$cancel->pi->amount = 1000;
check( ! is_wp_error( $cancel->cancel( request( array( 'id' => 42 ) ) ) ) && $cancel->cancelled === 1 && $orders[42]->status === 'cancelled', 'verified unpaid intent can be cancelled' );
$native_browser = new Koopo_Support_Browser_Checkout();
wp_set_current_user( 999 );
check( is_wp_error( $native_browser->app_status( request( array( 'id' => 42 ) ) ) ), 'mobile receipt endpoint rejects another customer' );
wp_set_current_user( 100 );
check( $native_browser->app_status( request( array( 'id' => 42 ) ) )->data['status'] === 'cancelled', 'mobile receipt endpoint reads only the owned support order' );
}
