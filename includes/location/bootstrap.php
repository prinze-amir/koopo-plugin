<?php
/** Shared location routing. Intentionally excludes shipping postal validation. */
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-settings.php';
require_once __DIR__ . '/class-usage.php';
require_once __DIR__ . '/class-providers.php';
require_once __DIR__ . '/class-router.php';
require_once __DIR__ . '/class-rest.php';
require_once __DIR__ . '/class-gateway.php';
\Koopo\Location\Settings::boot();
\Koopo\Location\Rest::boot();
\Koopo\Location\Gateway::boot();

function koopo_location_geocode(array $address,array $context=[]) { return \Koopo\Location\Router::geocode($address,$context); }
function koopo_location_autocomplete(string $query,array $input=[],array $context=[]) { return \Koopo\Location\Router::run('autocomplete',array_merge($input,['query'=>$query]),$context); }
function koopo_location_search(string $query,array $input=[],array $context=[]) { return \Koopo\Location\Router::run('search',array_merge($input,['query'=>$query]),$context); }
function koopo_location_reverse($latitude,$longitude,array $context=[]) { return \Koopo\Location\Router::run('reverse',['latitude'=>$latitude,'longitude'=>$longitude],$context); }
