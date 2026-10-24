<?php
namespace Koopo\Location;
defined('ABSPATH') || exit;

final class Providers {
  public static function all(): array {
    return apply_filters('koopo_location_providers', [
      'geocodefarm'=>['label'=>'GeocodeFarm','attribution'=>'GeocodeFarm','cache_ttl'=>30*DAY_IN_SECONDS,'operations'=>['geocode'], 'request'=>[self::class,'request']],
      'geoapify'=>['label'=>'Geoapify','attribution'=>'© OpenStreetMap contributors · Geoapify','cache_ttl'=>30*DAY_IN_SECONDS,'operations'=>['geocode','reverse','autocomplete','search'], 'request'=>[self::class,'request']],
      'latlng'=>['label'=>'LatLng','note'=>'Free plan: 3,000 calls/day shared across account keys and APIs. Confirm remaining allowance before enabling.','attribution'=>'© OpenStreetMap contributors · LatLng','cache_ttl'=>0,'operations'=>['geocode','reverse','search'], 'request'=>[self::class,'request']],
      'acuris'=>['label'=>'Acuris Geo','note'=>'Address suggestions, geocode, reverse. Requires country hint. UK has separate allowance. Free commercial use requires a visible attribution link.','attribution'=>'Address data by Acuris · https://www.acuris-geo.com','cache_ttl'=>0,'operations'=>['geocode','reverse','autocomplete'], 'request'=>[self::class,'request']],
      'mapbox'=>['label'=>'Mapbox (permanent geocoding)','note'=>'Paid permanent results; only trusted workflows displaying a Mapbox map are eligible. Temporary free allowance does not apply.','attribution'=>'© Mapbox · © OpenStreetMap contributors','cache_ttl'=>0,'operations'=>['geocode','reverse','autocomplete','search'], 'request'=>[self::class,'request']],
      'google'=>['label'=>'Google','attribution'=>'Google Maps','cache_ttl'=>0,'operations'=>['geocode','reverse','autocomplete','search','details'], 'request'=>[self::class,'request']],
      'osm'=>['label'=>'OpenStreetMap / Nominatim','attribution'=>'© OpenStreetMap contributors','cache_ttl'=>30*DAY_IN_SECONDS,'operations'=>['geocode','reverse','search'], 'request'=>[self::class,'request']],
    ]);
  }

  // Acuris requires ISO-3. Unsupported/missing country hints skip it before quota reservation.
  public static function acuris_country(array $input): string {
    $code=strtolower((string)($input['country_code'] ?? $input['address']['country'] ?? ''));
    return ['us'=>'usa','ca'=>'can','gb'=>'gbr','de'=>'deu','at'=>'aut','ch'=>'che','fr'=>'fra','es'=>'esp','be'=>'bel','nl'=>'nld','dk'=>'dnk','fi'=>'fin','se'=>'swe','no'=>'nor','it'=>'ita','jp'=>'jpn','sg'=>'sgp','pt'=>'prt','au'=>'aus','nz'=>'nzl','ie'=>'irl'][$code] ?? '';
  }

  public static function request(string $provider, string $operation, array $input, array $context) {
    $key = Settings::api_key($provider);
    $query = $input['query'] ?? implode(', ', array_filter(array_values($input['address'] ?? [])));
    $limit = min(8, max(1, (int)($input['limit'] ?? 5)));
    // Autocomplete should fail over quickly rather than wait for a slow provider.
    $args = ['timeout'=>$operation === 'autocomplete' ? 3 : 8,'redirection'=>0,'sslverify'=>true,'headers'=>['User-Agent'=>'KoopoLocation/1.0 (+' . home_url('/') . ')']];
    if ($operation === 'autocomplete') {
      foreach (array_filter([$input['city'] ?? '',$input['state_name'] ?? $input['state'] ?? '',$input['postal_code'] ?? '']) as $hint) {
        if (stripos($query,(string)$hint)===false) $query .= ', ' . $hint;
      }
    }
    if ($provider === 'latlng') {
      $args['headers']['X-Api-Key']=$key;
      $params=['limit'=>$operation === 'search' ? $limit : 1];
      if ($operation === 'reverse') { $params['lat']=$input['latitude']; $params['lon']=$input['longitude']; }
      else $params['q']=$query;
      if ($operation === 'search') {
        if (!empty($input['country_code'])) $params['country']=strtolower($input['country_code']);
        if (isset($input['latitude'],$input['longitude'])) { $params['lat']=$input['latitude']; $params['lon']=$input['longitude']; }
      }
      $url=add_query_arg($params,'https://api.latlng.work/' . ['geocode'=>'api','reverse'=>'reverse','search'=>'v1/places/search'][$operation]);
    } elseif ($provider === 'acuris') {
      $country=self::acuris_country($input);
      if ($country === '') return new \WP_Error('location_country_required','A supported country hint is required for Acuris.',['status'=>400]);
      $args['headers']['X-Acuris-Key']=$key;
      $host=$country === 'gbr' ? 'https://paf.acuris-geo.com' : 'https://api.acuris-geo.com';
      if ($operation === 'autocomplete') {
        $params=['country'=>$country,'q'=>$query,'limit'=>$limit];
        if (!empty($input['state'])) $params['state']=sanitize_text_field((string)$input['state']);
        if (isset($input['latitude'],$input['longitude'])) { $params['lat']=$input['latitude']; $params['lng']=$input['longitude']; }
        $url=add_query_arg($params,$host . '/suggest');
      } elseif ($operation === 'reverse') $url=add_query_arg(['country'=>$country,'lat'=>$input['latitude'],'lng'=>$input['longitude'],'limit'=>1],$host . '/v1/reverse');
      else {
        $url=$host . '/v1/geocode'; $args['method']='POST';
        $args['headers']['Content-Type']='application/json';
        $args['body']=wp_json_encode(['country'=>$country,'q'=>$query]);
      }
    } elseif ($provider === 'mapbox') {
      // Consumers save location coordinates. Temporary/free results cannot be stored.
      $params=['access_token'=>$key,'permanent'=>'true','limit'=>$operation === 'geocode' || $operation === 'reverse' ? 1 : $limit];
      if ($operation === 'reverse') { $params['latitude']=$input['latitude']; $params['longitude']=$input['longitude']; $params['types']='address'; }
      else {
        $params['q']=$query; $params['autocomplete']=$operation === 'autocomplete' ? 'true' : 'false';
        if (isset($input['latitude'],$input['longitude'])) $params['proximity']=$input['longitude'] . ',' . $input['latitude'];
        if ($operation === 'autocomplete') $params['types']='address,street';
      }
      if (!empty($input['country_code'])) $params['country']=strtolower($input['country_code']);
      $url=add_query_arg($params,'https://api.mapbox.com/search/geocode/v6/' . ($operation === 'reverse' ? 'reverse' : 'forward'));
    } elseif ($provider === 'geocodefarm') {
      $url = add_query_arg(['key'=>$key,'addr'=>$query], 'https://api.geocodefarm.com/forward/');
    } elseif ($provider === 'geoapify') {
      $path = ['geocode'=>'search','search'=>'autocomplete','autocomplete'=>'autocomplete','reverse'=>'reverse'][$operation];
      $params = ['format'=>'geojson','limit'=>$operation === 'geocode' || $operation === 'reverse' ? 1 : $limit,'apiKey'=>$key];
      if ($operation === 'reverse') { $params['lat']=$input['latitude']; $params['lon']=$input['longitude']; }
      else $params['text']=$query;
      if (!empty($input['country_code'])) $params['filter']='countrycode:' . strtolower($input['country_code']);
      if (isset($input['latitude'],$input['longitude']) && $operation !== 'reverse') $params['bias']='proximity:' . $input['longitude'] . ',' . $input['latitude'];
      $url = add_query_arg($params, 'https://api.geoapify.com/v1/geocode/' . $path);
    } elseif ($provider === 'google' && in_array($operation,['autocomplete','search','details'],true)) {
      $args['headers']['X-Goog-Api-Key']=$key;
      if ($operation === 'details') {
        $args['headers']['X-Goog-FieldMask']='id,formattedAddress,location,addressComponents,types';
        $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($input['id']);
        if (!empty($input['session_token'])) $url=add_query_arg('sessionToken',$input['session_token'],$url);
      } else {
        $body=['input'=>$query,'languageCode'=>'en'];
        if ($operation === 'autocomplete') $body['includedPrimaryTypes']=['street_address','premise','subpremise','route'];
        if (!empty($input['session_token'])) $body['sessionToken']=$input['session_token'];
        if (!empty($input['country_code'])) $body['includedRegionCodes']=[strtoupper($input['country_code'])];
        if (isset($input['latitude'],$input['longitude'])) $body['locationBias']=['circle'=>['center'=>['latitude'=>$input['latitude'],'longitude'=>$input['longitude']],'radius'=>50000]];
        $args['method']='POST'; $args['body']=wp_json_encode($body); $args['headers']['Content-Type']='application/json';
        $args['headers']['X-Goog-FieldMask']='suggestions.placePrediction.placeId,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat,suggestions.placePrediction.types';
        $url='https://places.googleapis.com/v1/places:autocomplete';
      }
    } elseif ($provider === 'google') {
      $params=['key'=>$key];
      $params[$operation === 'reverse' ? 'latlng' : 'address']=$operation === 'reverse' ? $input['latitude'] . ',' . $input['longitude'] : $query;
      $url=add_query_arg($params,'https://maps.googleapis.com/maps/api/geocode/json');
    } else {
      $params=['format'=>'jsonv2','addressdetails'=>1];
      if ($operation === 'reverse') { $params['lat']=$input['latitude']; $params['lon']=$input['longitude']; }
      else { $params['q']=$query; $params['limit']=$operation === 'geocode' ? 1 : $limit; }
      $url=add_query_arg($params,'https://nominatim.openstreetmap.org/' . ($operation === 'reverse' ? 'reverse' : 'search'));
    }
    $response=wp_safe_remote_request($url,$args);
    if (is_wp_error($response)) return new \WP_Error('location_network_error','The location provider could not be reached.',['status'=>502,'cooldown'=>120]);
    $status=(int)wp_remote_retrieve_response_code($response);
    if ($status === 404) return new \WP_Error('location_not_found','No matching location was found.',['status'=>404]);
    if ($status < 200 || $status >= 300) return new \WP_Error('location_provider_error','The location provider rejected the request.',['status'=>502,'cooldown'=>$status === 429 ? 900 : (in_array($status,[401,402,403],true) ? 3600 : 120)]);
    $body=json_decode(wp_remote_retrieve_body($response),true);
    if (!is_array($body)) return new \WP_Error('location_response_invalid','The location provider returned an invalid response.',['status'=>502,'cooldown'=>120]);
    if ($provider === 'google' && isset($body['status']) && !in_array($body['status'],['OK','ZERO_RESULTS'],true)) return new \WP_Error('location_provider_error','Google location services are unavailable.',['status'=>502,'cooldown'=>900]);
    $items=self::parse($provider,$operation,$body);
    if ($operation === 'autocomplete' && strtolower((string)($input['country_code'] ?? ''))==='us' && preg_match('/^[A-Z]{2}$/i',(string)($input['state'] ?? ''))) {
      $state=strtoupper($input['state']);
      $items=array_values(array_filter($items,static function($item) use ($state,$input) {
        $region=(string)($item['address']['region'] ?? '');
        // Google predictions omit address components; the contextual query guides them.
        return $region==='' || strtoupper($region)===$state || strcasecmp($region,(string)($input['state_name'] ?? ''))===0;
      }));
    }
    if (!$items) return new \WP_Error('location_not_found','No matching location was found.',['status'=>404]);
    return ['provider'=>$provider,'items'=>$items];
  }

  public static function parse(string $provider, string $operation, array $body): array {
    $items=[];
    if ($provider === 'google' && in_array($operation,['autocomplete','search'],true)) {
      foreach ($body['suggestions'] ?? [] as $s) {
        $p=$s['placePrediction'] ?? [];
        if (empty($p['placeId']) || empty($p['text']['text'])) continue;
        $items[]=['id'=>$p['placeId'],'formatted_address'=>sanitize_text_field($p['text']['text']),'name'=>sanitize_text_field($p['structuredFormat']['mainText']['text'] ?? $p['text']['text']),'types'=>array_map('sanitize_key',$p['types'] ?? [])];
      }
      return $items;
    }
    if ($provider === 'geocodefarm') {
      if (($body['STATUS']['status'] ?? '') !== 'SUCCESS') return [];
      $r=$body['RESULTS']['result'] ?? [];
      $rows=[['latitude'=>$r['coordinates']['lat'] ?? null,'longitude'=>$r['coordinates']['lon'] ?? null,'formatted_address'=>$r['address']['full_address'] ?? '', 'accuracy'=>$r['accuracy'] ?? '']];
    } elseif ($provider === 'acuris' && $operation === 'autocomplete') {
      foreach ($body['suggestions'] ?? [] as $suggestion) {
        $a=$suggestion['standardized'] ?? $suggestion;
        $house=(string)($a['house_number'] ?? '');
        $street=(string)($a['street'] ?? '');
        $line=trim($house . ' ' . $street);
        $country=array_search($a['country'] ?? '', ['us'=>'usa','ca'=>'can','gb'=>'gbr','de'=>'deu','at'=>'aut','ch'=>'che','fr'=>'fra','es'=>'esp','be'=>'bel','nl'=>'nld','dk'=>'dnk','fi'=>'fin','se'=>'swe','no'=>'nor','it'=>'ita','jp'=>'jpn','sg'=>'sgp','pt'=>'prt','au'=>'aus','nz'=>'nzl','ie'=>'irl'],true) ?: '';
        $formatted=(string)($a['formatted_address'] ?? implode(', ',array_filter([$line,$a['city'] ?? '',$a['state'] ?? '',$a['postcode'] ?? '',$country])));
        $item=['id'=>$suggestion['id'] ?? $suggestion['suggestion_id'] ?? '',
          'formatted_address'=>$formatted,
          'name'=>$formatted,
          'accuracy'=>$suggestion['accuracy_type'] ?? '',
          'address'=>['address_1'=>$line,'address_2'=>$a['unit_identifier'] ?? $a['subpremise'] ?? '',
            'city'=>$a['city'] ?? '', 'region'=>$a['state'] ?? '', 'postal_code'=>$a['postcode'] ?? '', 'country'=>$country]];
        if (Router::valid_coordinates($suggestion['lat'] ?? null,$suggestion['lng'] ?? null)) {
          $item['latitude']=round((float)$suggestion['lat'],7); $item['longitude']=round((float)$suggestion['lng'],7);
        }
        foreach (['id','formatted_address','name','accuracy'] as $field) $item[$field]=sanitize_text_field((string)$item[$field]);
        $item['address']=array_map('sanitize_text_field',$item['address']);
        $items[]=$item;
      }
      return $items;
    } elseif ($provider === 'acuris') {
      $a=$body['standardized'] ?? $body;
      $formatted=$body['formatted_address'] ?? implode(', ',array_filter([trim(($a['house_number'] ?? $a['hno'] ?? '') . ' ' . ($a['street'] ?? '')),$a['city'] ?? '',$a['state'] ?? '',$a['postcode'] ?? $a['zip'] ?? '']));
      $rows=[['latitude'=>$body['lat'] ?? null,'longitude'=>$body['lng'] ?? null,'formatted_address'=>$formatted,'accuracy'=>$body['accuracy_type'] ?? '', 'address'=>['address_1'=>trim(($a['house_number'] ?? $a['hno'] ?? '') . ' ' . ($a['street'] ?? '')),'address_2'=>'','city'=>$a['city'] ?? '', 'region'=>$a['state'] ?? '', 'postal_code'=>$a['postcode'] ?? $a['zip'] ?? '', 'country'=>array_search($a['country'] ?? '', ['us'=>'usa','ca'=>'can','gb'=>'gbr','de'=>'deu','at'=>'aut','ch'=>'che','fr'=>'fra','es'=>'esp','be'=>'bel','nl'=>'nld','dk'=>'dnk','fi'=>'fin','se'=>'swe','no'=>'nor','it'=>'ita','jp'=>'jpn','sg'=>'sgp','pt'=>'prt','au'=>'aus','nz'=>'nzl','ie'=>'irl'],true) ?: '']]];
    } elseif ($provider === 'latlng') {
      $rows=[];
      foreach ($body['features'] ?? $body['places'] ?? $body['results'] ?? [] as $f) {
        $p=$f['properties'] ?? $f;
        $formatted=$p['formatted_address'] ?? implode(', ',array_filter([$p['name'] ?? '',$p['city'] ?? '',$p['state'] ?? '',$p['country'] ?? '']));
        $rows[]=['id'=>$p['osm_id'] ?? '', 'latitude'=>$f['geometry']['coordinates'][1] ?? $p['lat'] ?? null,'longitude'=>$f['geometry']['coordinates'][0] ?? $p['lon'] ?? null,'name'=>$p['name'] ?? $formatted,'formatted_address'=>$formatted,'accuracy'=>$p['type'] ?? $p['category'] ?? '', 'address'=>['address_1'=>trim(($p['housenumber'] ?? '') . ' ' . ($p['street'] ?? '')),'address_2'=>'','city'=>$p['city'] ?? '', 'region'=>$p['state'] ?? '', 'postal_code'=>$p['postcode'] ?? '', 'country'=>$p['countrycode'] ?? '']];
      }
    } elseif ($provider === 'mapbox') {
      $rows=[];
      foreach ($body['features'] ?? [] as $f) {
        $p=$f['properties'] ?? []; $a=$p['context'] ?? [];
        $rows[]=['id'=>$p['mapbox_id'] ?? $f['id'] ?? '', 'latitude'=>$f['geometry']['coordinates'][1] ?? null,'longitude'=>$f['geometry']['coordinates'][0] ?? null,'name'=>$p['name'] ?? '', 'formatted_address'=>$p['full_address'] ?? trim(($p['name'] ?? '') . ', ' . ($p['place_formatted'] ?? ''), ', '),'accuracy'=>$p['coordinates']['accuracy'] ?? $p['feature_type'] ?? '', 'address'=>['address_1'=>$p['name'] ?? '', 'address_2'=>'', 'city'=>$a['place']['name'] ?? $a['locality']['name'] ?? '', 'region'=>$a['region']['region_code'] ?? $a['region']['name'] ?? '', 'postal_code'=>$a['postcode']['name'] ?? '', 'country'=>$a['country']['country_code'] ?? '']];
      }
    } elseif ($provider === 'geoapify') {
      $rows=[];
      foreach ($body['features'] ?? [] as $f) {
        $p=$f['properties'] ?? [];
        $rows[]=['id'=>$p['place_id'] ?? '', 'latitude'=>$f['geometry']['coordinates'][1] ?? null,'longitude'=>$f['geometry']['coordinates'][0] ?? null,'formatted_address'=>$p['formatted'] ?? '', 'name'=>$p['name'] ?? $p['address_line1'] ?? $p['formatted'] ?? '', 'accuracy'=>$p['rank']['confidence'] ?? '', 'address'=>['address_1'=>trim(($p['housenumber'] ?? '') . ' ' . ($p['street'] ?? '')),'address_2'=>$p['unit'] ?? '', 'city'=>$p['city'] ?? $p['town'] ?? $p['village'] ?? '', 'region'=>$p['state_code'] ?? $p['state'] ?? '', 'postal_code'=>$p['postcode'] ?? '', 'country'=>$p['country_code'] ?? '']];
      }
    } elseif ($provider === 'google') {
      $rows=[];
      foreach ($operation === 'details' ? [$body] : ($body['results'] ?? []) as $r) {
        $a=[];
        foreach ($r['addressComponents'] ?? $r['address_components'] ?? [] as $c) {
          foreach ($c['types'] ?? [] as $type) $a[$type]=$c['shortText'] ?? $c['short_name'] ?? $c['longText'] ?? $c['long_name'] ?? '';
        }
        $rows[]=['id'=>$r['id'] ?? $r['place_id'] ?? '', 'latitude'=>$r['location']['latitude'] ?? $r['geometry']['location']['lat'] ?? null,'longitude'=>$r['location']['longitude'] ?? $r['geometry']['location']['lng'] ?? null,'formatted_address'=>$r['formattedAddress'] ?? $r['formatted_address'] ?? '', 'name'=>$r['displayName']['text'] ?? $r['formattedAddress'] ?? $r['formatted_address'] ?? '', 'accuracy'=>$r['geometry']['location_type'] ?? '', 'map_url'=>$r['googleMapsUri'] ?? '', 'types'=>array_map('sanitize_key',$r['types'] ?? []), 'address'=>['address_1'=>trim(($a['street_number'] ?? '') . ' ' . ($a['route'] ?? '')),'address_2'=>$a['subpremise'] ?? '', 'city'=>$a['locality'] ?? $a['postal_town'] ?? '', 'region'=>$a['administrative_area_level_1'] ?? '', 'postal_code'=>$a['postal_code'] ?? '', 'country'=>$a['country'] ?? '']];
      }
    } else {
      $rows=[];
      foreach ($operation === 'reverse' ? [$body] : $body as $r) $rows[]=['latitude'=>$r['lat'] ?? null,'longitude'=>$r['lon'] ?? null,'formatted_address'=>$r['display_name'] ?? '', 'accuracy'=>$r['type'] ?? ''];
    }
    foreach ($rows as $r) {
      if (!Router::valid_coordinates($r['latitude'] ?? null,$r['longitude'] ?? null)) {
        if ($operation !== 'autocomplete' || empty($r['address']['address_1'])) continue;
        unset($r['latitude'],$r['longitude']);
      } else {
        $r['latitude']=round((float)$r['latitude'],7); $r['longitude']=round((float)$r['longitude'],7);
      }
      foreach (['formatted_address','name','accuracy'] as $k) $r[$k]=sanitize_text_field((string)($r[$k] ?? $r['formatted_address']));
      if (isset($r['address'])) $r['address']=array_map('sanitize_text_field',$r['address']);
      $items[]=$r;
    }
    return $items;
  }
}
