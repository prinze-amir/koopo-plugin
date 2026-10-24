<?php
namespace Koopo\Location;
defined('ABSPATH') || exit;

final class Rest {
  public static function boot(): void {
    add_action('rest_api_init',static function() {
      foreach (['geocode','reverse','autocomplete','search','details'] as $operation) {
        register_rest_route('koopo/v1','/location/' . $operation,[
          'methods'=>'POST','permission_callback'=>static function() use ($operation) {
            // Guest checkout needs address suggestions. Keep all other location
            // operations authenticated; details only resolves a short-lived,
            // encrypted suggestion token created by autocomplete.
            if (is_user_logged_in() || in_array($operation,['autocomplete','details'],true)) return true;
            return new \WP_Error('location_auth_required','Sign in to use location services.',['status'=>401]);
          },
          'callback'=>static function($request) use ($operation) {
            $input=$request->get_json_params();
            if (!is_array($input)) return new \WP_Error('location_input_invalid','A JSON object is required.',['status'=>400]);
            $user_id=get_current_user_id();
            if (!$user_id && !in_array($operation,['autocomplete','details'],true)) return new \WP_Error('location_auth_required','Sign in to use location services.',['status'=>401]);
            if (!$user_id && $operation==='details' && (strpos((string)($input['id'] ?? ''),'loc_')!==0)) return new \WP_Error('location_selection_invalid','Select an address suggestion first.',['status'=>400]);
            // Caller input cannot opt a customer address into the public OSM route.
            $context=['user_id'=>$user_id,'privacy_class'=>'customer_private','purpose'=>'woocommerce_address'];
            $s=Settings::get();
            $identity='user_' . $user_id;
            if (!$user_id) {
              // REMOTE_ADDR avoids trusting caller-supplied forwarding headers.
              // Hash it so raw IP addresses are never written into the options table.
              $ip=(string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
              $identity='guest_' . hash_hmac('sha256',$ip,wp_salt('auth'));
            }
            if (!Usage::reserve($identity,$s['user_daily'])) return new \WP_Error('location_user_limit','You reached today’s location lookup limit.',['status'=>429]);
            $result=Router::run($operation,$input,$context);
            if (is_wp_error($result)) return $result;
            $response=new \WP_REST_Response($result);
            $response->header('Cache-Control','private, no-store');
            return $response;
          },
        ]);
      }
    });
    add_action('wp_enqueue_scripts',static function() {
      wp_register_script('koopo-location',plugins_url('location-client.js',__FILE__),['wp-api-fetch'],'1.1.0',true);
      $is_checkout=function_exists('is_checkout') && is_checkout() && !(function_exists('is_order_received_page') && is_order_received_page());
      $is_account_address=function_exists('is_account_page') && is_account_page() && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('edit-address');
      if ($is_checkout || $is_account_address || get_query_var('koopo-professional-profile', null) !== null) {
        wp_enqueue_script('koopo-location');
        wp_localize_script('koopo-location','koopoLocationConfig',[
          'root'=>esc_url_raw(rest_url()),
          'gateway'=>Settings::get()['gateway_url'],
          'nonce'=>wp_create_nonce('wp_rest'),
          'messages'=>['loading'=>__('Searching addresses…','koopo'),'empty'=>__('No matching addresses found.','koopo'),'error'=>__('Address suggestions are unavailable. You can still enter your address manually.','koopo'),'attribution'=>__('Address suggestions by','koopo')],
        ]);
      }
    });
  }
}
