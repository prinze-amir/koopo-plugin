<?php
namespace Koopo\Location;
defined('ABSPATH') || exit;

/** Shared persistent counters. Reserve before HTTP, including attempts that fail. */
final class Usage {
  public static function lock(string $key): bool {
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', self::lock_name($key))) === '1';
  }
  public static function unlock(string $key): void {
    global $wpdb;
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($key)));
  }
  private static function lock_name(string $key): string {
    global $wpdb;
    return 'koopo_loc_' . substr(hash('sha256', $wpdb->prefix . $key),0,48);
  }
  private static function bucket(string $provider): array {
    $key = 'koopo_location_usage_' . sanitize_key($provider);
    // Do not trust a process-local option cache inside a quota reservation.
    wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options');
    $b = (array) get_option($key, []);
    if (($b['day'] ?? '') !== gmdate('Ymd')) { $b['day'] = gmdate('Ymd'); $b['daily'] = 0; }
    if (($b['month'] ?? '') !== gmdate('Ym')) { $b['month'] = gmdate('Ym'); $b['monthly'] = 0; }
    $legacy = (array) get_transient('koopo_appt_geocoder_usage_' . gmdate('Ymd'));
    if (empty($b['legacy_imported']) || $b['legacy_imported'] !== gmdate('Ymd')) {
      $b['daily'] = max((int)($b['daily'] ?? 0), (int)($legacy[$provider] ?? 0));
      $b['legacy_imported'] = gmdate('Ymd');
    }
    return $b;
  }
  public static function counts(string $provider): array {
    $b = self::bucket($provider);
    return ['daily'=>(int)($b['daily'] ?? 0), 'monthly'=>(int)($b['monthly'] ?? 0)];
  }
  public static function reserve(string $provider, int $daily, int $monthly = 0): bool {
    return self::reserve_batch($provider,$daily,$monthly,1)===1;
  }
  public static function reserve_batch(string $provider,int $daily,int $monthly=0,int $requested=5): int {
    if ($daily<=0 || !self::lock($provider)) return 0;
    try {
      $b=self::bucket($provider);
      $units=min(max(1,min(5,$requested)),max(0,$daily-(int)($b['daily'] ?? 0)));
      if ($monthly>0) $units=min($units,max(0,$monthly-(int)($b['monthly'] ?? 0)));
      if ($units===0) return 0;
      $b['daily']=(int)($b['daily'] ?? 0)+$units;
      $b['monthly']=(int)($b['monthly'] ?? 0)+$units;
      return update_option('koopo_location_usage_' . sanitize_key($provider),$b,false) ? $units : 0;
    } finally { self::unlock($provider); }
  }
}
