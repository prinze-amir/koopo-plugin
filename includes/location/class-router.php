<?php
namespace Koopo\Location;
defined('ABSPATH') || exit;

final class Router {
  const PUBLIC_PRIVACY_CLASS='public_profile';
  public static function canonical_provider(string $p): string {
    $p=sanitize_key($p);
    return ['geofarm'=>'geocodefarm','geocode_farm'=>'geocodefarm','apify'=>'geoapify','goeapify'=>'geoapify'][$p] ?? $p;
  }
  public static function valid_coordinates($lat,$lng): bool {
    return is_numeric($lat) && is_numeric($lng) && is_finite((float)$lat) && is_finite((float)$lng) && (float)$lat>=-90 && (float)$lat<=90 && (float)$lng>=-180 && (float)$lng<=180;
  }
  public static function address(array $a): array {
    return array_map('sanitize_text_field',[
      'address_1'=>(string)($a['address_1'] ?? $a['address1'] ?? $a['address'] ?? ''),
      'address_2'=>(string)($a['address_2'] ?? $a['address2'] ?? ''),
      'city'=>(string)($a['city'] ?? ''),'region'=>(string)($a['region'] ?? $a['state'] ?? ''),
      'postal_code'=>(string)($a['postal_code'] ?? $a['postcode'] ?? ''),'country'=>(string)($a['country'] ?? 'United States'),
    ]);
  }
  private static function attribution(string $provider): string {
    $spec=Providers::all()[$provider] ?? [];
    return (string)($spec['attribution'] ?? $spec['label'] ?? $provider);
  }
  public static function geocode(array $address,array $context=[]) {
    $result=self::run('geocode',['address'=>$address],$context);
    if (is_wp_error($result)) return $result;
    return array_merge($result['items'][0],['provider'=>$result['provider'],'geocoded_at'=>current_time('mysql',true),'purpose'=>sanitize_key((string)($context['purpose'] ?? 'location'))]);
  }
  public static function run(string $operation,array $input,array $context=[]) {
    if (!in_array($operation,['geocode','reverse','autocomplete','search','details'],true)) return new \WP_Error('location_operation_invalid','Unsupported location operation.',['status'=>400]);
    foreach (['query','country_code','session_token','id','state','state_name','city','postal_code'] as $field) {
      if (isset($input[$field]) && !is_string($input[$field])) return new \WP_Error('location_input_invalid','Location text fields must be strings.',['status'=>400]);
    }
    if (isset($input['address']) && !is_array($input['address'])) return new \WP_Error('location_input_invalid','An address object is required.',['status'=>400]);
    if (isset($input['address'])) foreach ($input['address'] as $field) {
      if (!is_string($field)) return new \WP_Error('location_input_invalid','Address fields must be strings.',['status'=>400]);
    }
    if (isset($input['limit']) && (!is_int($input['limit']) || $input['limit']<1 || $input['limit']>8)) return new \WP_Error('location_input_invalid','Use a result limit between 1 and 8.',['status'=>400]);
    $context=wp_parse_args($context,['privacy_class'=>'customer_private','purpose'=>'location','user_id'=>get_current_user_id()]);
    $settings=Settings::get();
    if ($settings['mode'] === 'disabled') return new \WP_Error('location_disabled','Location services are disabled.',['status'=>503]);
    if ($operation === 'geocode') {
      $input['address']=self::address((array)($input['address'] ?? []));
      if ($input['address']['city'] === '' || ($input['address']['address_1'] === '' && $input['address']['postal_code'] === '')) return new \WP_Error('incomplete_service_address','Enter a street or postal code and city before checking the location.',['status'=>400]);
    }
    if ($operation === 'reverse' && !self::valid_coordinates($input['latitude'] ?? null,$input['longitude'] ?? null)) return new \WP_Error('location_coordinates_invalid','Valid latitude and longitude are required.',['status'=>400]);
    if (isset($input['latitude'],$input['longitude']) && !self::valid_coordinates($input['latitude'],$input['longitude'])) return new \WP_Error('location_coordinates_invalid','Invalid location bias coordinates.',['status'=>400]);
    if (isset($input['latitude'],$input['longitude'])) { $input['latitude']=(float)$input['latitude']; $input['longitude']=(float)$input['longitude']; }
    if (in_array($operation,['autocomplete','search'],true)) {
      $input['query']=sanitize_text_field((string)($input['query'] ?? ''));
      if (strlen($input['query'])<3) return ['provider'=>'disabled','items'=>[]];
      if (strlen($input['query'])>256) return new \WP_Error('location_query_invalid','The location query is too long.',['status'=>400]);
    }
    if (!empty($input['country_code']) && !preg_match('/^[a-zA-Z]{2}$/',(string)$input['country_code'])) return new \WP_Error('location_country_invalid','Use a two-letter country code.',['status'=>400]);
    if (strlen((string)($input['session_token'] ?? ''))>128) return new \WP_Error('location_session_invalid','Invalid session token.',['status'=>400]);
    foreach (['state','state_name','city','postal_code'] as $field) {
      if (isset($input[$field])) {
        $input[$field]=sanitize_text_field($input[$field]);
        if (strlen($input[$field])>100) return new \WP_Error('location_input_invalid','Address context is too long.',['status'=>400]);
      }
    }
    $selection=null;
    if ($operation === 'details') {
      $id=(string)($input['id'] ?? '');
      $selection=strpos($id,'loc_')===0 && strlen($id)<=8192 ? Settings::open(substr($id,4)) : [];
      if (empty($selection['result']) || ($selection['expires'] ?? 0)<time() || (int)($selection['user'] ?? 0)!==(int)$context['user_id']) return new \WP_Error('location_selection_expired','Select the location again.',['status'=>400]);
      if (self::valid_coordinates($selection['result']['latitude'] ?? null,$selection['result']['longitude'] ?? null)) return ['provider'=>$selection['provider'],'items'=>[$selection['result']],'attribution'=>self::attribution($selection['provider'])];
      // Acuris suggestions already contain the structured address used by checkout.
      // Resolving one through its geocoder would spend another request.
      if ($selection['provider']==='acuris' && !empty($selection['result']['address']['address_1'])) return ['provider'=>$selection['provider'],'items'=>[$selection['result']],'attribution'=>self::attribution($selection['provider'])];
      $input['id']=(string)($selection['result']['id'] ?? '');
      $input['session_token']=$selection['session'] ?? '';
    }
    $providers=$selection ? [$selection['provider']] : ($settings['mode']==='auto' ? $settings['order'] : [$settings['mode']]);
    if (!$selection && $operation==='autocomplete' && $settings['mode']==='auto' && !empty($settings['autocomplete_order'])) $providers=$settings['autocomplete_order'];
    $known=Providers::all(); $errors=[];
    foreach ($providers as $provider) {
      $spec=$known[$provider] ?? null;
      if (!$spec || !in_array($operation,$spec['operations'],true) || !is_callable($spec['request'])) continue;
      if ($provider==='mapbox' && empty($context['mapbox_map'])) continue;
      if ($provider==='acuris' && Providers::acuris_country($input)==='') continue;
      if ($provider==='osm' && ($context['privacy_class']!==self::PUBLIC_PRIVACY_CLASS || !$settings['osm_public'] || $operation==='autocomplete')) continue;
      if ($provider!=='osm' && Settings::api_key($provider)==='') continue;
      if (get_transient('koopo_location_down_' . $provider)) continue;
      $cache_ttl=max(0,(int)($spec['cache_ttl'] ?? 0));
      $cacheable=$context['privacy_class']===self::PUBLIC_PRIVACY_CLASS && in_array($operation,['geocode','reverse'],true) && $cache_ttl>0;
      $cache='koopo_location_' . hash('sha256',wp_json_encode([$provider,$operation,$input]));
      if ($cacheable) { $cached=get_transient($cache); if (is_array($cached)) return $cached; }
      $osm_locked=false;
      if ($provider==='osm') {
        $osm_locked=Usage::lock('osm_http');
        if (!$osm_locked) continue;
        $last=max((float)get_option('koopo_location_osm_last',0),(float)get_option('koopo_appt_geocoder_osm_last_request',0));
        if (microtime(true)-$last<1.05) { Usage::unlock('osm_http'); continue; }
      }
      try {
        if (!Usage::reserve($provider,(int)($settings['daily'][$provider] ?? 0),(int)($settings['monthly'][$provider] ?? 0))) { $errors[]='quota'; continue; }
        if ($osm_locked) update_option('koopo_location_osm_last',(string)microtime(true),false);
        try {
          $result=call_user_func($spec['request'],$provider,$operation,$input,$context);
        } catch (\Throwable $error) {
          $result=new \WP_Error('location_provider_error','The location provider could not complete the request.',['status'=>502,'cooldown'=>120]);
        }
      } finally { if ($osm_locked) Usage::unlock('osm_http'); }
      if (is_wp_error($result)) {
        $data=(array)$result->get_error_data();
        if (!empty($data['cooldown'])) set_transient('koopo_location_down_' . $provider,1,(int)$data['cooldown']);
        $errors[]=$result->get_error_code(); continue;
      }
      if (!is_array($result) || empty($result['items']) || !is_array($result['items'])) { $errors[]='location_response_invalid'; continue; }
      $result['provider']=$provider;
      $result['attribution']=self::attribution($provider);
      if (in_array($operation,['autocomplete','search'],true)) {
        foreach ($result['items'] as &$item) {
          if (empty($item['id'])) $item['id']='geo_' . hash('sha256',wp_json_encode([$provider,$item]));
          $item['id']='loc_' . Settings::seal(['provider'=>$provider,'result'=>$item,'expires'=>time()+900,'user'=>(int)$context['user_id'],'session'=>(string)($input['session_token'] ?? '')]);
        }
        unset($item);
      }
      if ($cacheable) set_transient($cache,$result,$cache_ttl);
      do_action('koopo_location_resolved',$operation,$result,$context);
      return $result;
    }
    if ($errors && count(array_unique($errors)) === 1 && $errors[0] === 'location_not_found') {
      if (in_array($operation,['autocomplete','search'],true)) return ['provider'=>$provider,'items'=>[],'attribution'=>self::attribution($provider)];
      return new \WP_Error('address_not_found','No matching location was found.',['status'=>404]);
    }
    $quota=in_array('quota',$errors,true);
    return new \WP_Error($quota ? 'location_quota_exhausted' : 'geocoder_unavailable',$quota ? 'The location lookup allowance has been reached. Try again after the next reset.' : 'No configured location provider could resolve this request.',['status'=>$quota ? 429 : 503]);
  }
}
