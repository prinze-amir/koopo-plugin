<?php
// Standalone behavior tests: verified payment, private note, duplicate callbacks and crash recovery.
define( 'ABSPATH', __DIR__ );
$GLOBALS['orders'] = array(); $GLOBALS['notices'] = array(); $GLOBALS['options'] = array();
function wc_get_order( $id ) { return $GLOBALS['orders'][$id] ?? null; }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Supporter', 'ID' => $id ); }
function add_option( $key, $value ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function get_option( $key ) { return $GLOBALS['options'][$key] ?? false; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function wp_next_scheduled() { return false; } function wp_schedule_single_event() {} function wp_clear_scheduled_hook() {}
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wc_price( $amount, $args ) { return '$' . number_format( $amount, 2 ); }
function bp_core_get_user_domain( $id ) { return 'https://example.test/members/' . $id . '/'; }
function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function bp_notifications_add_notification( $args ) { $id = count( $GLOBALS['notices'] ) + 1; $GLOBALS['notices'][$id] = (object) array_merge( $args, array( 'id' => $id ) ); return $id; }
class BP_Notifications_Notification { static function get( $args ) { return array_filter( $GLOBALS['notices'], function ( $row ) use ( $args ) { return $row->user_id === $args['user_id'] && $row->item_id === $args['item_id']; } ); } }
class TestOrder {
    public $meta = array( '_koopo_support_session' => 'bound', '_dokan_vendor_id' => 201, '_koopo_support_note' => '<script>Kindness</script> Keep creating!' );
    public $paid = false;
    function get_meta( $k ) { return $this->meta[$k] ?? ''; } function is_paid() { return $this->paid; }
    function has_status() { return false; } function get_id() { return 42; } function get_customer_id() { return 1; }
    function update_meta_data( $k, $v ) { $this->meta[$k] = $v; } function save() {}
    function get_total() { return 5; } function get_currency() { return 'USD'; }
}
function check( $value, $label ) { if ( ! $value ) { throw new RuntimeException( $label ); } echo "PASS $label\n"; }
require dirname( __DIR__ ) . '/includes/creator-support/class-koopo-support-notifications.php';
$order = new TestOrder(); $GLOBALS['orders'][42] = $order; $service = new Koopo_Support_Notifications();
$service->deliver( 42 ); check( ! $GLOBALS['notices'], 'unpaid checkout never notifies creator' );
$order->paid = true; $service->deliver( 42 ); check( ! $GLOBALS['notices'], 'paid without provider verification never notifies creator' );
$order->meta['_koopo_support_verified'] = 'yes'; $service->deliver( 42 );
check( count( $GLOBALS['notices'] ) === 1 && $GLOBALS['notices'][1]->user_id === 201, 'verified Love reaches the bound creator only' );
$service->deliver( 42 ); check( count( $GLOBALS['notices'] ) === 1, 'repeated payment callbacks do not duplicate notification' );
unset( $order->meta['_koopo_love_notification_id'] ); $GLOBALS['notices'][1]->is_new = 0; $service->deliver( 42 );
check( count( $GLOBALS['notices'] ) === 1 && $order->get_meta('_koopo_love_notification_id') === 1, 'insert recovery also finds previously read notifications' );
$content = $service->callback( 'love_received', 42, 1, 1, 'string' );
check( strpos( $content, '$5.00' ) !== false && strpos( $content, 'Keep creating!' ) !== false, 'creator notification contains verified amount and note' );
check( strpos( $content, '<script>' ) === false && strpos( $content, '&lt;script&gt;' ) !== false, 'note is escaped in notification HTML' );
check( $service->format('unchanged',42,1,1,'string','other','other') === 'unchanged', 'other notification components remain unchanged' );
