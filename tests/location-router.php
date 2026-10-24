<?php
/** Behavioral fixtures; no external API calls or site state. */
define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY','location-fixture-auth'); define('SECURE_AUTH_KEY','location-fixture-secure');
define('DAY_IN_SECONDS',86400);
$options=[]; $transients=[]; $requests=[]; $http_status=200; $db_available=true; $filters=[];
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function sanitize_text_field($v) { return trim(strip_tags((string)$v)); }
function wp_parse_args($a,$b) { return array_merge($b,$a); }
function wp_json_encode($v) { return json_encode($v); }
function get_option($k,$d=false) { global $options; return $options[$k] ?? $d; }
function add_option($k,$v,$deprecated='',$autoload=false) { global $options; if (isset($options[$k])) return false; $options[$k]=$v; return true; }
function update_option($k,$v,$autoload=false) { global $options; $options[$k]=$v; return true; }
function get_transient($k) { global $transients; return $transients[$k] ?? false; }
function set_transient($k,$v,$ttl) { global $transients; $transients[$k]=$v; return true; }
function wp_cache_delete($k,$group) {}
function apply_filters($k,$v,...$args) { global $filters; return isset($filters[$k]) ? $filters[$k]($v,...$args) : $v; }
function do_action($k,...$args) {}
function get_current_user_id() { return $GLOBALS["fixture_user_id"] ?? 0; }
function current_time($format,$utc=false) { return gmdate('Y-m-d H:i:s'); }
function home_url($p) { return 'https://fixture.invalid' . $p; }
function wp_salt($scheme='auth') { return 'fixture-salt'; }
function add_query_arg($a,$b,$c=null) { return $c ? $c . '?' . http_build_query([$a=>$b]) : $b . '?' . http_build_query($a); }
class WP_Error {
  public function __construct(private $code='',private $message='',private $data=[]) {}
  public function get_error_message() { return $this->message; }
  public function get_error_code() { return $this->code; }
  public function get_error_data() { return $this->data; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
class FixtureDB {
  public $prefix='fixture_';
  public function prepare($sql,$v) { return $sql; }
  public function get_var($sql) { global $db_available; return $db_available ? '1' : '0'; }
}
$wpdb=new FixtureDB();
function geo_body() { return ['features'=>[['geometry'=>['coordinates'=>[-83.0458,42.3314]],'properties'=>['place_id'=>'geo-detroit','name'=>'Main Street','formatted'=>'123 Main St, Detroit MI','housenumber'=>'123','street'=>'Main St','unit'=>'2B','city'=>'Detroit','state_code'=>'MI','postcode'=>'48201','country_code'=>'us','rank'=>['confidence'=>0.98]]]]]; }
function wp_safe_remote_request($url,$args) {
  global $requests,$http_status;
  $requests[]=[$url,$args];
  if (isset($GLOBALS['provider_fixture_body'])) $body=$GLOBALS['provider_fixture_body'];
  elseif (str_contains($url,'geocodefarm')) $body=['STATUS'=>['status'=>'SUCCESS'],'RESULTS'=>['result'=>['coordinates'=>['lat'=>42.3314,'lon'=>-83.0458],'address'=>['full_address'=>'Detroit'],'accuracy'=>'EXACT_MATCH']]];
  elseif (str_contains($url,'nominatim')) $body=[['lat'=>42.3314,'lon'=>-83.0458,'display_name'=>'Detroit','type'=>'city']];
  elseif (str_contains($url,'places:autocomplete')) $body=['suggestions'=>[['placePrediction'=>['placeId'=>'google-detroit','text'=>['text'=>'Detroit'],'structuredFormat'=>['mainText'=>['text'=>'Detroit']]]]]];
  elseif (str_contains($url,'places.googleapis.com')) $body=['id'=>'google-detroit','displayName'=>['text'=>'Detroit'],'formattedAddress'=>'Detroit, MI','location'=>['latitude'=>42.3314,'longitude'=>-83.0458]];
  elseif (str_contains($url,'maps.googleapis.com')) $body=['status'=>'OK','results'=>[['place_id'=>'google-detroit','formatted_address'=>'Detroit, MI','geometry'=>['location'=>['lat'=>42.3314,'lng'=>-83.0458],'location_type'=>'ROOFTOP']]]];
  else $body=geo_body();
  return ['status'=>$http_status,'body'=>json_encode($body)];
}
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
foreach (['settings','usage','providers','router'] as $file) require_once dirname(__DIR__) . '/includes/location/class-' . $file . '.php';
use Koopo\Location\Settings;
use Koopo\Location\Usage;
use Koopo\Location\Router;
use Koopo\Location\Providers;
function location_expect($v,$m) { if (!$v) throw new RuntimeException($m); }
function configure($mode='auto',$daily=['geoapify'=>3,'google'=>3,'geocodefarm'=>3,'osm'=>3],$monthly=[]) {
  global $options;
  $options[Settings::OPTION]=array_merge(Settings::defaults(),['mode'=>$mode,'daily'=>$daily,'monthly'=>$monthly,'order'=>['geocodefarm','geoapify','google','osm']]);
  $options[Settings::KEYS]=['geoapify'=>Settings::seal(['secret'=>'fixture']),'google'=>Settings::seal(['secret'=>'fixture']),'geocodefarm'=>Settings::seal(['secret'=>'fixture'])];
}
function reset_usage() { global $options,$transients,$requests; foreach(array_keys($options) as $k) if(str_contains($k,'usage_')) unset($options[$k]); $transients=[]; $requests=[]; }
$address=['address_1'=>'123 Main St','city'=>'Detroit','region'=>'MI','postal_code'=>'48201'];
// Existing encrypted keys can be read without loading the appointments plugin.
$iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt(json_encode(['secret'=>'legacy-key']),'aes-256-gcm',hash('sha256',AUTH_KEY.SECURE_AUTH_KEY.'|koopo-appointments-calendar',true),OPENSSL_RAW_DATA,$iv,$tag,'koopo-calendar-v1');
$options['koopo_appt_geoapify_key']=base64_encode($iv.$tag.$cipher);
$options['koopo_appt_geocoder_daily_limits']=['geoapify'=>12];
location_expect(Settings::api_key('geoapify')==='legacy-key','Legacy key migration must work without appointments classes.');
$outer_iv=random_bytes(12); $outer_tag=''; $outer_cipher=openssl_encrypt(json_encode(['secret'=>$options['koopo_appt_geoapify_key']]),'aes-256-gcm',hash('sha256',AUTH_KEY.SECURE_AUTH_KEY.'|koopo-appointments-calendar',true),OPENSSL_RAW_DATA,$outer_iv,$outer_tag,'koopo-calendar-v1');
$options['koopo_appt_geoapify_key']=base64_encode($outer_iv.$outer_tag.$outer_cipher);
location_expect(Settings::api_key('geoapify')==='legacy-key','Double-encrypted legacy settings must resolve the authenticated inner secret.');
location_expect(Settings::get()['daily']['geoapify']===12,'Legacy daily settings must be preserved.');
$saved_keys=Settings::save_keys(['geoapify'=>'new-fixture-key']);
location_expect(Settings::save_keys($saved_keys)===$saved_keys,'Repeated settings sanitization must not double-encrypt API keys.');
$removed=Settings::save_keys(['geoapify_clear'=>1]);
location_expect(Settings::save_keys($removed)===$removed && $removed['geoapify']==='','Repeated settings sanitization must preserve an explicit legacy-key removal.');
configure('geoapify');
$result=Router::run('autocomplete',['query'=>'123 Mai'],['user_id'=>12]);
location_expect(!is_wp_error($result) && count($requests)===1,'Autocomplete should make one shared provider request.');
location_expect($result['items'][0]['address']['address_2']==='2B','Apartment information must survive normalization.');
$id=$result['items'][0]['id'];
$details=Router::run('details',['id'=>$id],['user_id'=>12]);
location_expect(!is_wp_error($details) && $details['items'][0]['id']==='geo-detroit' && count($requests)===1,'Selecting a complete result must not spend another provider lookup or publish the selection token.');
location_expect(is_wp_error(Router::run('details',['id'=>$id],['user_id'=>13])),'Selection tokens must be user-bound.');
$expired='loc_' . Settings::seal(['result'=>['id'=>'expired'],'provider'=>'geoapify','user'=>12,'expires'=>time()-1]);
location_expect(is_wp_error(Router::run('details',['id'=>$expired],['user_id'=>12])),'Expired selection must be rejected.');
Router::geocode($address); Router::run('reverse',['latitude'=>42.3,'longitude'=>-83]);
location_expect(Usage::counts('geoapify')['daily']===3,'All operations must share the provider quota.');
$blocked=Router::geocode($address);
location_expect(is_wp_error($blocked) && $blocked->get_error_code()==='location_quota_exhausted' && count($requests)===3,'Explicit provider mode must enforce daily caps before HTTP.');
reset_usage(); configure('auto',['geocodefarm'=>0,'geoapify'=>1,'google'=>2,'osm'=>0]);
Router::run('search',['query'=>'Detroit']);
$fallback=Router::run('search',['query'=>'Detroit']);
location_expect(!is_wp_error($fallback) && $fallback['provider']==='google','Exhausted providers must route to a capable provider with remaining allowance.');
$google_details=Router::run('details',['id'=>$fallback['items'][0]['id']]);
location_expect(!is_wp_error($google_details) && $google_details['items'][0]['id']==='google-detroit','Google selection must resolve against its original provider.');
location_expect(Usage::counts('geocodefarm')['daily']===0,'Unsupported providers must not spend quota on autocomplete or search.');
reset_usage(); configure('geoapify',['geoapify'=>20],['geoapify'=>1]);
Router::geocode($address);
location_expect(is_wp_error(Router::run('reverse',['latitude'=>42,'longitude'=>-83])),'Monthly cap must be enforced across operations.');
// Old usage is seeded before new requests; UTC buckets roll without a cron job.
reset_usage(); configure('geoapify',['geoapify'=>3]);
$transients['koopo_appt_geocoder_usage_' . gmdate('Ymd')]=['geoapify'=>2];
Router::geocode($address);
location_expect(Usage::counts('geoapify')['daily']===3,'Migration must count today’s old requests.');
$options['koopo_location_usage_geoapify']=['day'=>'19990101','month'=>'199901','daily'=>99,'monthly'=>99]; $transients=[];
location_expect(Usage::reserve('geoapify',1,1) && !Usage::reserve('geoapify',1,1),'UTC day/month rollover must reset both buckets.');
reset_usage(); configure('geoapify'); $db_available=false;
location_expect(is_wp_error(Router::geocode($address)) && !$requests,'Unavailable quota storage must prevent HTTP.'); $db_available=true;
reset_usage(); configure('osm',['osm'=>20]); $options[Settings::OPTION]['osm_public']=true;
location_expect(is_wp_error(Router::geocode($address)) && !$requests,'Private addresses must never reach public Nominatim.');
location_expect(is_wp_error(Router::run('autocomplete',['query'=>'Detroit'],['privacy_class'=>'public_profile'])) && !$requests,'Public Nominatim must not receive autocomplete.');
$public=Router::geocode($address,['privacy_class'=>'public_profile']);
location_expect(!is_wp_error($public) && count($requests)===1,'Explicitly public profile geocoding should remain supported.');
Router::geocode($address,['privacy_class'=>'public_profile']);
location_expect(count($requests)===1,'Repeated public address lookup should use the eligible cache.');
reset_usage(); configure('google'); Router::geocode($address,['privacy_class'=>'public_profile']); Router::geocode($address,['privacy_class'=>'public_profile']);
location_expect(count($requests)===2,'Google address results must not enter a shared persistent address cache.');
reset_usage(); configure('geoapify'); $http_status=429; Router::geocode($address); Router::geocode($address);
location_expect(count($requests)===1 && Usage::counts('geoapify')['daily']===1,'Failed outgoing attempts count, and rate-limited providers enter cooldown.');
$http_status=200;
location_expect(!Router::valid_coordinates(null,null) && !Router::valid_coordinates(999,-83) && Router::valid_coordinates(0,0),'Coordinate validation must preserve 0,0 and reject missing/out-of-range values.');
location_expect(Providers::parse('osm','geocode',[['lat'=>999,'lon'=>-83]])===[],'Malformed provider coordinates must not be accepted.');
echo "Shared location routing fixtures passed.\n";

// HTTP callers cannot promote customer data to the public OSM privacy class.
$actions=[]; $rest_routes=[];
function add_action($name,$callback,...$args) { $GLOBALS['actions'][$name][]=$callback; }
function register_rest_route($namespace,$route,$args) { $GLOBALS['rest_routes'][$route]=$args; }
function is_user_logged_in() { return get_current_user_id()>0; }
class WP_REST_Response {
  public $headers=[];
  public function __construct(public $data) {}
  public function header($name,$value) { $this->headers[$name]=$value; }
}
class FixtureRequest {
  public function __construct(private $data) {}
  public function get_json_params() { return $this->data; }
}
require dirname(__DIR__) . '/includes/location/class-rest.php';
\Koopo\Location\Rest::boot();
foreach ($actions['rest_api_init'] as $action) $action();
location_expect(is_wp_error($rest_routes['/location/geocode']['permission_callback']()),'The HTTP location API must reject anonymous calls.');
location_expect($rest_routes['/location/autocomplete']['permission_callback']()===true && $rest_routes['/location/details']['permission_callback']()===true,'Guest checkout may autocomplete and resolve a signed selection.');
location_expect(is_wp_error($rest_routes['/location/search']['permission_callback']()),'Other location operations remain sign-in-only.');
$fixture_user_id=12; reset_usage(); configure('osm',['osm'=>10]); $options[Settings::OPTION]['osm_public']=true;
$result=$rest_routes['/location/geocode']['callback'](new FixtureRequest(['address'=>$address,'privacy_class'=>'public_profile']));
location_expect(is_wp_error($result) && !$requests,'Public privacy flags in request bodies must never enable Nominatim for customer addresses.');
reset_usage(); configure('geoapify'); $options[Settings::OPTION]['user_daily']=1;
$result=$rest_routes['/location/autocomplete']['callback'](new FixtureRequest(['query'=>'123 Mai']));
location_expect($result instanceof WP_REST_Response && $result->headers['Cache-Control']==='private, no-store','Private lookup responses must prevent HTTP caching.');
$result=$rest_routes['/location/autocomplete']['callback'](new FixtureRequest(['query'=>'123 Mai']));
location_expect(is_wp_error($result) && $result->get_error_code()==='location_user_limit' && count($requests)===1,'The shared REST user allowance must stop repeated calls before provider HTTP.');
$fixture_user_id=0; $_SERVER['REMOTE_ADDR']='192.0.2.10'; reset_usage(); configure('geoapify');
$guest_result=$rest_routes['/location/autocomplete']['callback'](new FixtureRequest(['query'=>'123 Mai']));
location_expect($guest_result instanceof WP_REST_Response && count($requests)===1,'Guest checkout autocomplete uses the shared provider router.');
location_expect(Usage::counts('guest_' . hash_hmac('sha256','192.0.2.10','fixture-salt'))['daily']===1,'Guest lookups receive a hashed per-IP daily counter.');
$guest_id=$guest_result->data['items'][0]['id'];
$guest_detail=$rest_routes['/location/details']['callback'](new FixtureRequest(['id'=>$guest_id]));
location_expect($guest_detail instanceof WP_REST_Response && count($requests)===1,'Guest selection resolves its short-lived server token without a second provider request.');
$guest_geo=$rest_routes['/location/geocode']['callback'](new FixtureRequest(['address'=>$address]));
location_expect(is_wp_error($guest_geo) && $guest_geo->get_error_code()==='location_auth_required','Guest access cannot be used for general geocoding.');
$fixture_user_id=0;
echo "Shared location REST fixtures passed.\n";

// Additional adapters: documented request shapes, shared counters, and eligibility.
reset_usage(); configure('latlng',['latlng'=>2]);
$options[Settings::KEYS]['latlng']=Settings::seal(['secret'=>'fixture-latlng']);
$GLOBALS['provider_fixture_body']=['features'=>[['geometry'=>['coordinates'=>[-83,42]],'properties'=>['name'=>'Detroit','country'=>'United States','state'=>'Michigan']]]];
$r=Router::geocode($address);
location_expect(!is_wp_error($r) && $r['provider']==='latlng','LatLng forward response');
location_expect(str_contains($requests[0][0],'/api?') && $requests[0][1]['headers']['X-Api-Key']==='fixture-latlng','LatLng server header');
$r=Router::run('reverse',['latitude'=>42,'longitude'=>-83]);
location_expect(!is_wp_error($r) && str_contains($requests[1][0],'/reverse?'),'LatLng reverse endpoint');
$r=Router::geocode($address); location_expect(is_wp_error($r) && count($requests)===2,'LatLng shared cap');
reset_usage(); configure('auto',['geoapify'=>0,'acuris'=>3]); $options[Settings::OPTION]['order']=['geoapify','acuris'];
$options[Settings::KEYS]['acuris']=Settings::seal(['secret'=>'fixture-acuris']);
$GLOBALS['provider_fixture_body']=['suggestions'=>[['standardized'=>['house_number'=>'1600','street'=>'Pennsylvania Avenue NW','city'=>'Washington','state'=>'DC','postcode'=>'20500','country'=>'usa','unit_identifier'=>''],'formatted_address'=>'1600 Pennsylvania Avenue NW, Washington, DC 20500']]];
$r=Router::run('autocomplete',['query'=>'1600 Penn','country_code'=>'US'],['user_id'=>12]);
location_expect(!is_wp_error($r) && $r['provider']==='acuris' && $r['items'][0]['address']['address_1']==='1600 Pennsylvania Avenue NW','Acuris suggestions normalize a structured address for autocomplete.');
location_expect(str_contains($requests[0][0],'https://api.acuris-geo.com/suggest?') && str_contains($requests[0][0],'country=usa') && ($requests[0][1]['headers']['X-Acuris-Key'] ?? '')==='fixture-acuris','Acuris suggestions use the address endpoint, country hint and saved key.');
$r_id=$r['items'][0]['id']; $before=count($requests); $selected=Router::run('details',['id'=>$r_id],['user_id'=>12]);
location_expect(!is_wp_error($selected) && $selected['provider']==='acuris' && $selected['items'][0]['address']['city']==='Washington' && count($requests)===$before,'Acuris selection resolves locally from its structured suggestion without another provider call.');
unset($GLOBALS['provider_fixture_body']);
reset_usage(); configure('acuris',['acuris'=>3]); $options[Settings::KEYS]['acuris']=Settings::seal(['secret'=>'fixture-acuris']);
$GLOBALS['provider_fixture_body']=['lat'=>42,'lng'=>-83,'formatted_address'=>'123 Main St, Detroit','accuracy_type'=>'rooftop','standardized'=>['house_number'=>'123','street'=>'Main St','city'=>'Detroit','country'=>'usa']];
$r=Router::geocode($address); location_expect(is_wp_error($r) && !$requests,'Missing Acuris country skips without spending quota');
$r=Router::geocode($address+['country'=>'US']);
location_expect(!is_wp_error($r) && $r['address']['country']==='us','Acuris normalization');
location_expect(json_decode($requests[0][1]['body'],true)['country']==='usa' && str_contains($requests[0][0],'/v1/geocode'),'Acuris country and versioned JSON');
$r=Router::run('reverse',['latitude'=>51,'longitude'=>0,'country_code'=>'GB']);
location_expect(!is_wp_error($r) && str_contains($requests[1][0],'https://paf.acuris-geo.com/v1/reverse?'),'Acuris UK dedicated host');
reset_usage(); configure('mapbox',['mapbox'=>3]);
$options[Settings::KEYS]['mapbox']=Settings::seal(['secret'=>'fixture-mapbox']);
$GLOBALS['provider_fixture_body']=['features'=>[['id'=>'mapbox-id','geometry'=>['coordinates'=>[-83,42]],'properties'=>['name'=>'123 Main St','full_address'=>'123 Main St, Detroit','context'=>['place'=>['name'=>'Detroit'],'country'=>['country_code'=>'US']]]]]];
$r=Router::run('autocomplete',['query'=>'123 Main']); location_expect(is_wp_error($r) && !$requests,'Mapbox requires trusted Mapbox-map workflow');
$r=Router::run('autocomplete',['query'=>'123 Main'],['mapbox_map'=>true,'user_id'=>7]);
location_expect(!is_wp_error($r) && str_contains($requests[0][0],'permanent=true') && str_contains($requests[0][0],'autocomplete=true'),'Mapbox permanent address autocomplete');
$before=count($requests); $detail=Router::run('details',['id'=>$r['items'][0]['id']],['user_id'=>7]);
location_expect(!is_wp_error($detail) && $detail['items'][0]['id']==='mapbox-id' && count($requests)===$before,'Mapbox selection does not bill an extra request');
$r=Router::run('reverse',['latitude'=>42,'longitude'=>-83],['mapbox_map'=>true]);
location_expect(!is_wp_error($r) && str_contains($requests[1][0],'types=address') && str_contains($requests[1][0],'latitude=42'),'Mapbox reverse limit with address type');
location_expect(Providers::parse('latlng','search',['places'=>[['name'=>'Cafe','lat'=>42,'lon'=>-83]]])[0]['name']==='Cafe','LatLng places search');
location_expect(Providers::parse('mapbox','geocode',['features'=>[['geometry'=>['coordinates'=>[999,42]],'properties'=>[]]]])===[],'Malformed provider coordinates rejected');
unset($GLOBALS['provider_fixture_body']);
echo "Additional provider adapter fixtures passed.\n";

// Checkout locality is sent upstream and out-of-state structured results are excluded.
reset_usage(); configure('geoapify');
$mi=geo_body()['features'][0];
$ma=$mi; $ma['properties']['state_code']='MA'; $ma['properties']['formatted']='123 Main St, Boston MA';
$GLOBALS['provider_fixture_body']=['features'=>[$ma,$mi]];
$r=Router::run('autocomplete',['query'=>'123 Main','country_code'=>'US','state'=>'MI','state_name'=>'Michigan','city'=>'Detroit','postal_code'=>'48201']);
location_expect(!is_wp_error($r) && count($r['items'])===1 && $r['items'][0]['address']['region']==='MI','Michigan checkout must not offer Massachusetts results.');
parse_str(parse_url($requests[0][0],PHP_URL_QUERY),$params);
location_expect($params['text']==='123 Main, Detroit, Michigan, 48201' && $params['filter']==='countrycode:us','Checkout city/state/postcode must guide the upstream search.');
location_expect($requests[0][1]['timeout']===3,'Autocomplete failover must use a short provider timeout.');
$before=count($requests); $bad=Router::run('autocomplete',['query'=>'123 Main','state'=>['MI']]);
location_expect(is_wp_error($bad) && count($requests)===$before,'Malformed locality context must be rejected before a paid request.');
unset($GLOBALS['provider_fixture_body']);
reset_usage(); configure('google');
$r=Router::run('autocomplete',['query'=>'123 Main','country_code'=>'US','state'=>'MI','state_name'=>'Michigan','session_token'=>'fixture-session']);
$body=json_decode($requests[0][1]['body'],true);
location_expect($body['input']==='123 Main, Michigan' && $body['sessionToken']==='fixture-session','Google predictions must retain locality and session context.');
Router::run('details',['id'=>$r['items'][0]['id']]);
location_expect($requests[1][1]['headers']['X-Goog-FieldMask']==='id,formattedAddress,location,addressComponents,types','Checkout details must request only Essentials fields.');
echo "Checkout locality and Google billing fixtures passed.\n";
reset_usage(); configure('auto');
$options[Settings::OPTION]['autocomplete_order']=['google','geoapify'];
$r=Router::run('autocomplete',['query'=>'123 Main']);
location_expect(!is_wp_error($r) && $r['provider']==='google','Autocomplete may use its separate configured provider order.');
$r=Router::geocode($address);
location_expect(!is_wp_error($r) && $r['provider']==='geocodefarm','Autocomplete preference must preserve general geocoding order.');
echo "Independent autocomplete routing fixtures passed.\n";
reset_usage(); configure('google',['google'=>4],['google'=>3]);
location_expect(Usage::reserve_batch('google',4,3,5)===3,'A lease must clamp to the smaller remaining shared monthly allowance.');
location_expect(!Usage::reserve('google',4,3),'Legacy callers must see the gateway-reserved monthly budget.');
location_expect(Usage::reserve_batch('google',0,0,5)===0,'Provider off switches must block every lease.');
require_once dirname(__DIR__).'/includes/location/class-gateway.php';
$before=count($requests); $lease=\Koopo\Location\Gateway::lease(['provider'=>'google','revision'=>'stale']);
location_expect(is_wp_error($lease)&&$lease->get_error_code()==='location_config_changed'&&count($requests)===$before,'A stale config cannot reserve provider budget.');
echo "Shared gateway budget fixtures passed.\n";
