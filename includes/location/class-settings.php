<?php
namespace Koopo\Location;
defined('ABSPATH') || exit;

final class Settings {
  const OPTION = 'koopo_location_settings';
  const KEYS = 'koopo_location_keys';
  const LEGACY_KEYS = ['geocodefarm'=>'koopo_appt_geocodefarm_key','geoapify'=>'koopo_appt_geoapify_key','google'=>'koopo_appt_google_geocoding_key'];

  public static function defaults(): array {
    return ['mode'=>'auto', 'order'=>['geocodefarm','geoapify','latlng','acuris','mapbox','google','osm'], 'daily'=>['geocodefarm'=>250,'geoapify'=>3000,'google'=>0,'latlng'=>0,'acuris'=>0,'mapbox'=>0,'osm'=>100], 'monthly'=>[], 'autocomplete_order'=>[], 'gateway_url'=>'', 'osm_public'=>false, 'user_daily'=>150];
  }

  public static function get(): array {
    $stored = get_option(self::OPTION, null);
    if (!is_array($stored)) {
      $stored = self::defaults();
      $stored['mode'] = get_option('koopo_appt_geocoder_mode', 'auto');
      $stored['order'] = get_option('koopo_appt_geocoder_auto_order', $stored['order']);
      $stored['daily'] = get_option('koopo_appt_geocoder_daily_limits', $stored['daily']);
      $stored['osm_public'] = (bool) get_option('koopo_appt_geocoder_osm_public', false);
      add_option(self::OPTION, self::sanitize($stored), '', false);
    }
    return self::sanitize($stored);
  }

  public static function sanitize($value): array {
    $value = is_array($value) ? $value : [];
    $defaults = self::defaults();
    $known = array_keys(Providers::all());
    $mode = Router::canonical_provider((string) ($value['mode'] ?? 'auto'));
    $order = $value['order'] ?? $defaults['order'];
    if (is_string($order)) $order = explode(',', $order);
    $order = array_values(array_unique(array_filter(array_map([Router::class, 'canonical_provider'], (array) $order), static fn($p)=>in_array($p, $known, true))));
    $autocomplete_order=$value['autocomplete_order'] ?? [];
    if (is_string($autocomplete_order)) $autocomplete_order=explode(',',$autocomplete_order);
    $autocomplete_order=array_values(array_unique(array_filter(array_map([Router::class,'canonical_provider'],(array)$autocomplete_order),static fn($p)=>in_array($p,$known,true))));
    $limits = [];
    foreach (['daily','monthly'] as $period) {
      foreach ($known as $provider) {
        $limits[$period][$provider] = min(1000000, max(0, (int) ($value[$period][$provider] ?? $defaults[$period][$provider] ?? 0)));
      }
    }
    $gateway_url=rtrim(trim((string)($value['gateway_url'] ?? '')),'/');
    if ($gateway_url!=='' && (!filter_var($gateway_url,FILTER_VALIDATE_URL) || parse_url($gateway_url,PHP_URL_SCHEME)!=='https')) $gateway_url='';
    return ['mode'=>in_array($mode, array_merge(['auto','disabled'], $known), true) ? $mode : 'auto', 'order'=>$order ?: $defaults['order'], 'daily'=>$limits['daily'], 'monthly'=>$limits['monthly'], 'autocomplete_order'=>$autocomplete_order, 'gateway_url'=>$gateway_url, 'osm_public'=>!empty($value['osm_public']), 'user_daily'=>min(10000, max(1, (int) ($value['user_daily'] ?? 150)))];
  }

  private static function material(string $domain): string {
    $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
    if ($material === '') throw new \RuntimeException('WordPress authentication keys are required.');
    return hash('sha256', $material . '|' . $domain, true);
  }

  public static function seal(array $value): string {
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt(wp_json_encode($value), 'aes-256-gcm', self::material('koopo-location'), OPENSSL_RAW_DATA, $iv, $tag, 'koopo-location-v1');
    if ($cipher === false) throw new \RuntimeException('Location encryption failed.');
    return rtrim(strtr(base64_encode($iv . $tag . $cipher), '+/', '-_'), '=');
  }

  public static function open(string $value, bool $legacy = false): array {
    try {
      $bytes = base64_decode(strtr($value, '-_', '+/'), true);
      if ($bytes === false || strlen($bytes) < 29) return [];
      $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', self::material($legacy ? 'koopo-appointments-calendar' : 'koopo-location'), OPENSSL_RAW_DATA, substr($bytes,0,12), substr($bytes,12,16), $legacy ? 'koopo-calendar-v1' : 'koopo-location-v1');
      $decoded = is_string($plain) ? json_decode($plain, true) : null;
      return is_array($decoded) ? $decoded : [];
    } catch (\Throwable $e) { return []; }
  }

  public static function api_key(string $provider): string {
    $keys = (array) get_option(self::KEYS, []);
    if (array_key_exists($provider, $keys)) return (string) (self::open((string) $keys[$provider])['secret'] ?? '');
    $legacy = self::LEGACY_KEYS[$provider] ?? '';
    if (!$legacy) return '';
    $secret=(string)(self::open((string)get_option($legacy,''),true)['secret'] ?? '');
    // Historical appointments saves may have encrypted an already encrypted key.
    // Unwrap only authenticated legacy envelopes, with a bounded depth.
    for ($depth=0;$depth<2;$depth++) {
      $nested=self::open($secret,true);
      if (!isset($nested['secret']) || !is_string($nested['secret'])) break;
      $secret=$nested['secret'];
    }
    return $secret;
  }

  public static function save_keys($input): array {
    $input = is_array($input) ? $input : [];
    // WordPress may sanitize twice when creating an option. Never double-encrypt
    // a key or lose an explicit removal on that second pass.
    if (($input['__format'] ?? '') === 'koopo-location-keys-v1') {
      $valid = true;
      foreach ($input as $provider => $value) {
        if ($provider === '__format') continue;
        if (!isset(Providers::all()[$provider]) || !is_string($value) || ($value !== '' && !isset(self::open($value)['secret']))) { $valid = false; break; }
      }
      if ($valid) return $input;
    }
    $keys = (array) get_option(self::KEYS, []);
    $keys['__format'] = 'koopo-location-keys-v1';
    foreach (Providers::all() as $provider=>$spec) {
      if ($provider === 'osm') continue;
      if (!empty($input[$provider . '_clear'])) { $keys[$provider] = ''; continue; }
      $value = trim((string) ($input[$provider] ?? ''));
      if ($value === '') continue;
      try { $keys[$provider] = self::seal(['secret'=>$value]); }
      catch (\Throwable $e) { add_settings_error(self::KEYS, 'location_key_error', 'The API key could not be encrypted; the existing key was preserved.'); }
    }
    return $keys;
  }

  public static function boot(): void {
    add_action('admin_init', static function() {
      register_setting('koopo_location', self::OPTION, ['sanitize_callback'=>[self::class,'sanitize']]);
      register_setting('koopo_location', self::KEYS, ['sanitize_callback'=>[self::class,'save_keys']]);
    });
    add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    add_action('admin_menu', static function() { add_submenu_page('koopo', 'Location Services', 'Location Services', 'manage_options', 'koopo-location', [self::class,'page']); }, 30);
  }

  public static function enqueue_assets(string $hook): void {
    if (substr($hook, -strlen('_page_koopo-location')) !== '_page_koopo-location') return;
    $url = plugin_dir_url(__FILE__);
    wp_enqueue_style('koopo-location-settings', $url . 'admin-settings.css', [], (string) filemtime(__DIR__ . '/admin-settings.css'));
    wp_enqueue_script('koopo-location-settings', $url . 'admin-settings.js', [], (string) filemtime(__DIR__ . '/admin-settings.js'), true);
  }

  public static function page(): void {
    if (!current_user_can('manage_options')) return;
    $s = self::get(); $providers = Providers::all();
    echo '<div class="wrap koopo-admin-settings koopo-location-settings"><header class="koopo-admin-settings__masthead"><div class="koopo-admin-settings__identity"><span class="koopo-admin-settings__mark" aria-hidden="true">K</span><div><span>Koopo operations</span><h1>Location Services</h1><p>Manage location providers, routing, and usage across appointments, web, and mobile.</p></div></div></header>';
    settings_errors();
    echo '<form action="options.php" method="post">'; settings_fields('koopo_location');
    echo '<div class="koopo-admin-settings__layout"><nav class="koopo-admin-settings__nav" aria-label="Settings sections"><strong>Settings</strong><a href="#location-routing" data-koopo-settings-nav>Provider routing</a><a href="#location-providers" data-koopo-settings-nav>Keys and quotas</a><a href="#location-access" data-koopo-settings-nav>Privacy and access</a></nav><main class="koopo-admin-settings__content"><section class="koopo-settings-section" id="location-routing"><header><span>01</span><h2>Provider routing</h2></header>';
    echo '<p><label>Provider mode <select name="' . esc_attr(self::OPTION) . '[mode]">';
    foreach (array_merge(['auto'=>['label'=>'Automatic'], 'disabled'=>['label'=>'Disabled']],$providers) as $p=>$spec) echo '<option value="' . esc_attr($p) . '" ' . selected($s['mode'],$p,false) . '>' . esc_html($spec['label']) . '</option>';
    echo '</select></label></p><p><label>Routing order <input class="large-text" name="' . esc_attr(self::OPTION) . '[order]" value="' . esc_attr(implode(',',$s['order'])) . '"></label></p><p>Only providers supporting the requested operation are considered. Daily limits apply in every mode; 0 disables a provider. Monthly limits of 0 add no monthly cap. These are local spending controls, not a claim that a provider allowance is free.</p>';
    echo '<p><label>Address autocomplete order <input class="large-text" name="' . esc_attr(self::OPTION) . '[autocomplete_order]" value="' . esc_attr(implode(',',$s['autocomplete_order'])) . '"></label></p><p>Leave blank to use the general routing order. This order applies to autocomplete in automatic mode only; all quotas still apply.</p>';
    echo '<p><label>Location gateway URL <input class="large-text" type="url" name="' . esc_attr(self::OPTION) . '[gateway_url]" value="' . esc_attr($s['gateway_url']) . '" placeholder="https://api.kooposerver.com/beta"></label></p><p>Leave blank to use WordPress directly. Keys stay server-side. Gateway provider budgets are reserved in batches of up to five and counted here, including unused reservations. Configuration refreshes every 15 seconds and fails closed if stale.</p>';
    echo '</section><section class="koopo-settings-section" id="location-providers"><header><span>02</span><h2>Keys and quotas</h2></header><p class="description">Keys stay on the server. Leave a saved key blank to keep it.</p><div class="koopo-location-settings__table"><table class="widefat striped"><thead><tr><th scope="col">Provider / operations</th><th scope="col">API key</th><th scope="col">Daily cap / used</th><th scope="col">Monthly cap / used</th></tr></thead><tbody>';
    foreach ($providers as $p=>$spec) {
      $usage = Usage::counts($p);
      echo '<tr><td>' . esc_html($spec['label']) . '<br><small>' . esc_html(implode(', ', $spec['operations'])) . '</small></td><td>';
      if ($p !== 'osm') {
        $saved = self::api_key($p) !== '';
        echo '<input aria-label="' . esc_attr($spec['label'] . ' API key') . '" type="password" autocomplete="new-password" name="' . esc_attr(self::KEYS . '[' . $p . ']') . '" value="" placeholder="' . esc_attr($saved ? 'Saved — leave blank to keep' : 'API key') . '"><br><label><input type="checkbox" name="' . esc_attr(self::KEYS . '[' . $p . '_clear]') . '" value="1"> Remove key</label>';
      }
      if (!empty($spec['note'])) echo '<p class="description">' . esc_html($spec['note']) . '</p>';
      echo '</td>';
      foreach (['daily','monthly'] as $period) echo '<td><input aria-label="' . esc_attr($spec['label'] . ' ' . $period . ' cap') . '" type="number" min="0" max="1000000" name="' . esc_attr(self::OPTION . '[' . $period . '][' . $p . ']') . '" value="' . esc_attr($s[$period][$p]) . '"><br>' . esc_html($usage[$period]) . ' used</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div><p class="description">Daily reset: ' . esc_html(gmdate('Y-m-d H:i', strtotime('tomorrow UTC'))) . ' UTC. Monthly reset: ' . esc_html(gmdate('Y-m-01 00:00', strtotime('first day of next month UTC'))) . ' UTC. Counters are shared across operations and callers.</p></section><section class="koopo-settings-section" id="location-access"><header><span>03</span><h2>Privacy and access</h2></header><p class="description">Shipping address validation is managed by Koopo Shipping.</p><p><label><input type="checkbox" name="' . esc_attr(self::OPTION) . '[osm_public]" value="1" ' . checked($s['osm_public'],true,false) . '> Allow public Nominatim for explicitly public addresses only</label></p><p>Nominatim is excluded from autocomplete, customer addresses, and private device coordinates.</p><p><label>API requests per user or guest IP per day <input type="number" min="1" max="10000" name="' . esc_attr(self::OPTION) . '[user_daily]" value="' . esc_attr($s['user_daily']) . '"></label></p>';
    echo '</section></main></div><div class="koopo-admin-settings__save"><span data-koopo-save-state>Settings are up to date</span>';
    submit_button('Save location settings', 'primary', 'submit', false);
    echo '</div></form></div>';
  }
}
