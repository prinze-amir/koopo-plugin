<?php
// Local only. Synthetic provider objects; no Stripe charges or transfers.
if ( ! defined('ABSPATH') || ! in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1'],true)) { throw new RuntimeException('Local only'); }
class KCS_Test_Provider {
    public $paymentIntents; public $intents=[]; public $calls=0;
    function __construct(){ $this->paymentIntents=$this; }
    function create($data,$options){ $this->calls++; $p=(object)array_merge($data,['id'=>'pi_kcs_test_'.$this->calls,'object'=>'payment_intent','livemode'=>false,'client_secret'=>'synthetic_secret','status'=>'requires_payment_method','latest_charge'=>null]);$this->intents[$p->id]=$p;return $p; }
    function retrieve($id,$options=[]){return $this->intents[$id];}
}
class KCS_Test_Checkout extends Koopo_Creator_Support_Checkout {
    public $fake;
    function __construct(){ $this->fake=new KCS_Test_Provider(); }
    protected function client(){return $this->fake;}
    protected function vendor_ready($creator){return true;}
}
class KCS_Test_Payouts extends \Koopo\Payouts\Services\PayoutsService { protected function provider_is_eligible( int $id ): bool { return true; } }
function kcs_check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$users=[];$orders=[];$product=0;$stream=null;$keys=[];global $wpdb;
try{
 foreach(['administrator','subscriber'] as $role){$users[]=wp_insert_user(['user_login'=>'kcs-inline-'.wp_generate_password(12,false),'user_pass'=>wp_generate_password(32),'role'=>$role]);}
 [$creator,$viewer]=$users;wp_set_current_user($viewer);
 $product=Koopo_Creator_Support::instance()->service()->ensure_product_for_creator($creator);
 kcs_check(is_numeric($product),'support product exists');
 kcs_check(wc_get_product($product)->is_virtual(),'support product virtual');
 $checkout=new KCS_Test_Checkout();
 $repo=new \Koopo\Live\Repository();$stream=$repo->create_stream($creator,$creator,['title'=>'Inline support test']);$wpdb->update($wpdb->prefix.'koopo_live_streams',['state'=>'live'],['id'=>$stream['id']]);
 $context=['creator_id'=>$creator,'module'=>'videos','surface'=>'live_paid_chat','context_post_id'=>(int)$stream['id'],'context_post_type'=>'koopo_live_stream'];
 $r=new WP_REST_Request('POST','/koopo/v1/creator-support/sessions');
 $key=wp_generate_password(32,false);$keys[]='kcs_session_'.hash('sha256',$viewer.':'.$key);
 foreach(['context'=>Koopo_Creator_Support_Checkout::context_token($context),'amount'=>12.5,'message'=>'Thank you for the stream','request_key'=>$key] as $k=>$v)$r->set_param($k,$v);
 $result=$checkout->create($r);
 kcs_check(!is_wp_error($result),'inline order preparation: '.(is_wp_error($result)?$result->get_error_message():''));
 $orders[]=$result['order_id'];$o=wc_get_order($result['order_id']);
 kcs_check($o->get_payment_method()==='dokan_stripe_express' && !$o->is_paid(),'pending Express order');
 kcs_check($o->get_total()==='12.50' && !$o->needs_shipping_address(),'dedicated amount without shipping');
 kcs_check(Koopo_Creator_Support_Checkout::support_only($o),'support-only classification');
 $again=$checkout->create($r);kcs_check($again['order_id']===$o->get_id() && $checkout->fake->calls===1,'idempotent retry one order and intent');
 $r->set_param('amount',20);kcs_check(is_wp_error($checkout->create($r)),'reused key cannot change amount');$r->set_param('amount',12.5);
 $pi=reset($checkout->fake->intents);$checkout->payment_completed($o,$pi);kcs_check(wc_get_order($o->get_id())->get_status()==='pending','unpaid cannot complete');
 $pi->status='succeeded';$pi->amount_received=1250;$pi->latest_charge='ch_synthetic';$o->set_transaction_id('ch_synthetic');$o->set_date_paid(time());$o->set_status('processing');$o->update_meta_data('_dokan_stripe_express_charge_captured','yes');$o->save();
 $checkout->payment_completed($o,$pi);$o=wc_get_order($o->get_id());
 kcs_check($o->get_status()==='completed' && $o->get_meta('_koopo_support_verified')==='yes','verified support auto-completes');
 $checkout->payment_completed($o,$pi);
 $rows=$repo->list_chat_messages((int)$stream['id']);kcs_check(count($rows)===1 && (float)$rows[0]['support_amount']===12.5,'paid chat appears exactly once');
 kcs_check(wc_get_order($o->get_id())->get_meta('_koopo_support_chat_state')==='posted','publication status persisted');
 $payouts=new KCS_Test_Payouts(new \Koopo\Payouts\Settings(),new \Koopo\Payouts\Infrastructure\OperationalStore(),new \Koopo\Payouts\Infrastructure\DokanStripeExpressAdapter());
 $payouts->observe_order($o,'support_acceptance');
 $row=$wpdb->get_row($wpdb->prepare("SELECT state,eligibility_state,ready_at,release_basis FROM {$wpdb->prefix}koopo_payout_earnings WHERE order_id=%d",$o->get_id()),ARRAY_A);
 kcs_check($row && $row['state']==='available' && $row['eligibility_state']==='support_immediate' && $row['release_basis']==='verified_support_payment' && strtotime($row['ready_at'].' UTC')<=time(),'payout ledger eligible immediately without delivery evidence');

 $normal=new WC_Product_Simple();$normal->set_name('Unrelated virtual test');$normal->set_virtual(true);$normal->set_regular_price('1');$normal->save();
 $extra=new WC_Order_Item_Product();$extra->set_product($normal);$extra->set_quantity(1);$extra->set_total(1);$o->add_item($extra);$o->save();kcs_check(!Koopo_Creator_Support_Checkout::support_only($o),'mixed orders excluded');$normal->delete(true);
 wp_set_current_user($creator);$read=new WP_REST_Request('GET');$read->set_param('id',$o->get_id());kcs_check(is_wp_error($checkout->status($read)),'another user cannot read session');
 kcs_check(Koopo_Creator_Support_Checkout::context('tampered')===null,'tampered recipient context rejected');
}finally{
 foreach($orders as $id){wp_clear_scheduled_hook('koopo_support_reconcile',[$id]);$o=wc_get_order($id);if($o)$o->delete(true);$wpdb->delete($wpdb->prefix.'dokan_orders',['order_id'=>$id]);$wpdb->delete($wpdb->prefix.'dokan_vendor_balance',['trn_id'=>$id,'trn_type'=>'dokan_orders']);$wpdb->delete($wpdb->prefix.'koopo_payout_earnings',['order_id'=>$id]);}
 foreach($keys as $key){delete_option($key);delete_option($key.'_lock');}
 if($stream){$wpdb->delete($wpdb->prefix.'koopo_live_chat_messages',['stream_id'=>$stream['id']]);$wpdb->delete($wpdb->prefix.'koopo_live_streams',['id'=>$stream['id']]);}
 if(is_numeric($product)&&$product)wp_delete_post($product,true);
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $id){delete_transient('kcs_create_rate_'.$id);wp_delete_user($id);}wp_set_current_user(0);
}
