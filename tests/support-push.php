<?php
/** Standalone execution of actual Love job and Expo transport with a captured HTTP boundary. */
define('ABSPATH',__DIR__);
$GLOBALS['orders']=array();$GLOBALS['options']=array();$GLOBALS['users']=array();$GLOBALS['scheduled']=array();$GLOBALS['requests']=array();$GLOBALS['mode']='ok';
function add_action(){} function absint($n){return abs((int)$n);} function sanitize_key($s){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',$s));} function sanitize_text_field($s){return trim(strip_tags($s));}
function get_user_meta($id,$key){return $GLOBALS['users'][$id][$key]??array();} function update_user_meta($id,$key,$value){$GLOBALS['users'][$id][$key]=$value;} function delete_user_meta($id,$key){unset($GLOBALS['users'][$id][$key]);}
function get_option($k,$fallback=false){return $GLOBALS['options'][$k]??$fallback;} function add_option($k,$v){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;} function delete_option($k){unset($GLOBALS['options'][$k]);} function update_option($k,$v){$GLOBALS['options'][$k]=$v;}
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;} function get_userdata($id){return (object)array('display_name'=>'Sam');}
function wp_next_scheduled($hook,$args){return $GLOBALS['scheduled'][$hook.':'.$args[0]]??false;} function wp_schedule_single_event($at,$hook,$args){$GLOBALS['scheduled'][$hook.':'.$args[0]]=$at;return true;} function wp_clear_scheduled_hook($hook,$args){unset($GLOBALS['scheduled'][$hook.':'.$args[0]]);}
function wp_strip_all_tags($s){return strip_tags($s);} function wc_price($n,$args){return '$'.number_format($n,2);} function wp_html_excerpt($s,$n,$more){return mb_substr($s,0,$n).(mb_strlen($s)>$n?$more:'');}
function wp_json_encode($s,$flags=0){return json_encode($s,$flags);} function is_wp_error($s){return $s instanceof WP_Error;} class WP_Error{}
function admin_url($path){return "https://beta.example.test/".$path;} function wp_salt($kind){return "test-only-signing-salt";}
function wp_remote_post($url,$args){if(strpos($url,"admin-ajax.php")!==false){return array("http"=>200,"body"=>"{}");}if(strpos($url,"getReceipts")!==false){return array("http"=>200,"body"=>json_encode(array("data"=>array("ticket-test"=>array("status"=>($GLOBALS["mode"]==="receipt_invalid"?"error":"ok"),"details"=>array("error"=>"DeviceNotRegistered"))))));}$GLOBALS['requests'][]=json_decode($args['body'],true)[0];$GLOBALS['last_bytes']=strlen($args['body']);$mode=$GLOBALS['mode'];if($mode==='unknown')return new WP_Error();if($mode==='rate')return array('http'=>429,'body'=>'{}');return array('http'=>200,'body'=>json_encode(array('data'=>array(array('status'=>$mode==='invalid'?'error':'ok','id'=>'ticket-test','details'=>array('error'=>'DeviceNotRegistered'))))));}
function wp_remote_retrieve_response_code($r){return $r['http'];} function wp_remote_retrieve_body($r){return $r['body'];}
class PushTestOrder{
 public $paid=false;public $id;public $meta=array('_koopo_support_session'=>'bound','_dokan_vendor_id'=>201,'_koopo_support_note'=>'Keep creating!','_koopo_love_notification_id'=>1309);
 function __construct($id){$this->id=$id;}function get_id(){return $this->id;}function get_customer_id(){return 1;}function is_paid(){return $this->paid;}function has_status(){return false;}function get_meta($k){return $this->meta[$k]??'';}function update_meta_data($k,$v){$this->meta[$k]=$v;}function save(){}function get_total(){return '5.00';}function get_currency(){return 'USD';}
}
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
require dirname(__DIR__).'/includes/creator-support/class-koopo-support-push.php';
require dirname(dirname(__DIR__)).'/koopo-mobile-api/includes/class-push-notifications-controller.php';
$key=Koopo_Mobile_API_Push_Notifications_Controller::USER_META_KEY;$ownerKey=Koopo_Mobile_API_Push_Notifications_Controller::TOKEN_OWNERS_OPTION;
$good='ExpoPushToken[creator]';$wrong='ExpoPushToken[other]';$old='ExpoPushToken[expired]';
$GLOBALS['users'][201][$key]=array(array('token'=>$good,'updated_at'=>time()),array('token'=>$wrong,'updated_at'=>time()),array('token'=>$old,'updated_at'=>1));
$GLOBALS['options'][$ownerKey]=array(hash('sha256',$good)=>201,hash('sha256',$wrong)=>202,hash('sha256',$old)=>201);
$job=new Koopo_Support_Push();$order=new PushTestOrder(42);$GLOBALS['orders'][42]=$order;
$job->enqueue(42);$job->deliver(42);check(!$GLOBALS['scheduled']&&!$GLOBALS['requests'],'unpaid Love never schedules or sends push');
$order->paid=true;$job->deliver(42);check(!$GLOBALS['requests'],'paid without provider verification never sends push');
$order->meta['_koopo_support_verified']='yes';$job->enqueue(42);check(isset($GLOBALS['scheduled']['koopo_support_push_deliver:42'])&&!$GLOBALS['requests'],'verified payment enqueues without blocking checkout');
$job->deliver(42);$payload=$GLOBALS['requests'][0];check(count($GLOBALS['requests'])===1&&$payload['to']===$good,'only fresh tokens owned by the creator receive push');
check(strpos($payload['body'],'$5.00')!==false&&strpos($payload['body'],'Keep creating!')!==false&&$payload['data']['note']==='Keep creating!','amount and encouragement note are included');
check($payload['data']['creatorId']===201&&$payload['data']['type']==='koopo_love','tap payload binds the creator and notification kind');
$job->deliver(42);$job->enqueue(42);check(count($GLOBALS['requests'])===1,'repeated verified callbacks do not resend accepted push');
foreach(array(43=>'rate',44=>'unknown',45=>'invalid',46=>'ok') as $id=>$mode){$o=new PushTestOrder($id);$o->paid=true;$o->meta['_koopo_support_verified']='yes';$GLOBALS['orders'][$id]=$o;$GLOBALS['mode']=$mode;if($id===46){$GLOBALS['users'][201][$key]=array(array('token'=>$good,'updated_at'=>time()));$GLOBALS['options'][$ownerKey][hash('sha256',$good)]=201;$o->meta['_koopo_support_note']=str_repeat('💛',500);}
 $job->deliver($id);
 if($id===43){check(!$o->get_meta('_koopo_love_push_finished')&&isset($GLOBALS['scheduled']['koopo_support_push_deliver:43']),'explicit rate rejection schedules bounded retry');$GLOBALS['mode']='ok';$job->deliver($id);check($o->get_meta('_koopo_love_push_finished')==='processed','rate-limited device can later receive its push');}
 if($id===44){$before=count($GLOBALS['requests']);$job->deliver($id);check(count($GLOBALS['requests'])===$before,'ambiguous network result is not blindly replayed');}
 if($id===45)check(!isset($GLOBALS['options'][$ownerKey][hash('sha256',$good)]),'unregistered device token is removed');
 if($id===46)check($GLOBALS['last_bytes']<4096&&$GLOBALS['requests'][count($GLOBALS['requests'])-1]['data']['note']===str_repeat('💛',500),'full Unicode note fits the Expo payload limit');
}

$timestamp=time();$signature=hash_hmac('sha256','42:'.$timestamp,wp_salt('nonce'));
check(Koopo_Support_Push::valid_dispatch(42,$timestamp,$signature),'signed server loopback dispatch is accepted');
check(!Koopo_Support_Push::valid_dispatch(43,$timestamp,$signature)&&!Koopo_Support_Push::valid_dispatch(42,$timestamp-600,$signature),'dispatch rejects changed orders and expired signatures');
$job->receipts(46);$states=$GLOBALS['orders'][46]->get_meta('_koopo_love_push_devices');check(reset($states)['receipt_status']==='ok','Expo provider receipts are checked and persisted');

$receiptOrder=new PushTestOrder(47);$GLOBALS['orders'][47]=$receiptOrder;
$receiptOrder->meta['_koopo_love_push_devices']=array(hash('sha256',$good)=>array('state'=>'accepted','ticket_id'=>'ticket-test'));
$GLOBALS['mode']='receipt_invalid';$job->receipts(47);
check(!isset($GLOBALS['options'][$ownerKey][hash('sha256',$good)]),'provider receipt removes an unregistered creator device');
$receiptOrder=new PushTestOrder(48);$GLOBALS['orders'][48]=$receiptOrder;
$receiptOrder->meta['_koopo_love_push_devices']=array(hash('sha256',$good)=>array('state'=>'accepted','ticket_id'=>'ticket-test'));
$GLOBALS['options'][$ownerKey][hash('sha256',$good)]=202;$job->receipts(48);
check($GLOBALS['options'][$ownerKey][hash('sha256',$good)]===202,'late receipt cannot remove a device now owned by another account');
