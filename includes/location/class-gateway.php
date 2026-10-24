<?php
namespace Koopo\Location;
defined('ABSPATH') || exit;

/** Private server configuration and conservative prepaid quota batches. */
final class Gateway {
  public static function snapshot(): array {
    $settings=Settings::get(); $providers=[];
    foreach (['google','acuris','geoapify'] as $provider) {
      $key=Settings::api_key($provider);
      if ($key!=='') $providers[$provider]=['key'=>$key,'attribution'=>Providers::all()[$provider]['attribution']];
    }
    return ['schema'=>1,'revision'=>hash('sha256',wp_json_encode([$settings,$providers])),
      'settings'=>$settings,'providers'=>$providers,
      'token_secret'=>hash_hmac('sha256','koopo-location-gateway-v2',wp_salt('auth'))];
  }
  public static function permission($request=null) {
    $saved=Settings::open((string)get_option('koopo_location_gateway_bridge_key',''))['secret'] ?? '';
    $provided=$request ? (string)$request->get_header('X-Koopo-Location-Key') : '';
    if (strlen($saved)>=64 && hash_equals($saved,$provided)) return true;
    return current_user_can('manage_options') ? true : new \WP_Error('location_gateway_forbidden','Server administration credentials are required.',['status'=>403]);
  }
  public static function lease(array $input) {
    $snapshot=self::snapshot(); $s=$snapshot['settings'];
    if (($input['revision'] ?? '')!==$snapshot['revision']) return new \WP_Error('location_config_changed','Refresh location configuration.',['status'=>409]);
    $provider=(string)($input['provider'] ?? '');
    if ($s['mode']==='disabled' || !isset($snapshot['providers'][$provider]) || ($s['mode']!=='auto' && $s['mode']!==$provider)) return new \WP_Error('location_disabled','This provider is disabled.',['status'=>503]);
    $units=Usage::reserve_batch($provider,(int)$s['daily'][$provider],(int)$s['monthly'][$provider],min(5,max(1,(int)($input['units'] ?? 5))));
    return ['revision'=>$snapshot['revision'],'provider'=>$provider,'units'=>$units,
      'expires'=>strtotime('tomorrow UTC'),'counts'=>Usage::counts($provider)];
  }
  public static function boot(): void {
    add_action('rest_api_init',static function() {
      register_rest_route('koopo/v1','/location/gateway/config',[
        'methods'=>'POST','permission_callback'=>[self::class,'permission'],
        'callback'=>static function() { $r=new \WP_REST_Response(self::snapshot()); $r->header('Cache-Control','private, no-store'); return $r; },
      ]);
      register_rest_route('koopo/v1','/location/gateway/lease',[
        'methods'=>'POST','permission_callback'=>[self::class,'permission'],
        'callback'=>static function($request) {
          $input=$request->get_json_params();
          if (!is_array($input)) return new \WP_Error('location_input_invalid','A JSON object is required.',['status'=>400]);
          $data=self::lease($input); if (is_wp_error($data)) return $data;
          $r=new \WP_REST_Response($data); $r->header('Cache-Control','private, no-store'); return $r;
        },
      ]);
    });
  }
}
