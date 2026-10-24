<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable, hashed capabilities. These are never WordPress login sessions. */
class Koopo_Support_Browser_Sessions {
    const TTL = 1800;
    const HANDOFF_TTL = 300;

    public function hooks() { add_action( 'koopo_support_browser_expire', array( $this, 'expire' ), 10, 1 ); }
    public function expire( $key ) {
        if ( strpos( $key, 'kcs_browser_' ) === 0 ) { delete_option( $key ); delete_option( $key . '_claim' ); }
    }
    private function key( $kind, $token ) { return 'kcs_browser_' . $kind . '_' . hash( 'sha256', $token ); }
    private function save( $key, $value, $ttl ) {
        $value['expires_at'] = time() + $ttl;
        if ( ! add_option( $key, $value, '', false ) ) { throw new RuntimeException( 'Unable to create checkout session.' ); }
        wp_schedule_single_event( $value['expires_at'] + 60, 'koopo_support_browser_expire', array( $key ) );
    }
    public function issue( array $binding, $provided_code = null ) {
        $code = $provided_code ?: bin2hex( random_bytes( 32 ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $code ) ) { throw new InvalidArgumentException( 'Invalid checkout capability.' ); }
        $this->save( $this->key( 'handoff', $code ), $binding, self::HANDOFF_TTL );
        return $code;
    }
    public function read( $kind, $token ) {
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return null; }
        $key = $this->key( $kind, $token );
        $record = get_option( $key );
        if ( ! is_array( $record ) ) { return null; }
        if ( empty( $record['expires_at'] ) || $record['expires_at'] <= time() ) { $this->expire( $key ); return null; }
        return $record;
    }
    public function redeem( $code, $existing_token = '' ) {
        $binding = $this->read( 'handoff', $code );
        // A lost HTTP response may be retried only by the browser holding the issued session.
        if ( ! $binding ) {
            $existing = $this->read( 'session', $existing_token );
            if ( $existing && hash_equals( $existing['handoff_hash'], hash( 'sha256', (string) $code ) ) ) {
                return array( 'token' => $existing_token, 'binding' => $existing );
            }
            return null;
        }
        $key = $this->key( 'handoff', $code );
        // add_option is backed by a unique database key: only one request can claim a link.
        if ( ! add_option( $key . '_claim', time(), '', false ) ) { return null; }
        $token = bin2hex( random_bytes( 32 ) );
        $binding['handoff_hash'] = hash( 'sha256', $code );
        try { $this->save( $this->key( 'session', $token ), $binding, self::TTL ); }
        catch ( Throwable $error ) { delete_option( $key . '_claim' ); throw $error; }
        delete_option( $key );
        return array( 'token' => $token, 'binding' => $this->read( 'session', $token ) );
    }
}
