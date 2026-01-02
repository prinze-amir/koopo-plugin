<?php
/**
 * Koopo: GeoDirectory Location Manager footer modal/script allowlist.
 *
 * GeoDirectory Location Manager adds its location switcher modal + autocomplete JS via:
 *   add_action( 'wp_footer', 'geodir_location_autocomplete_script' );
 *
 * That output can break layouts on pages that don't load the expected GeoDirectory/Bootstrap UI assets
 * (e.g., BuddyBoss profile pages). This file removes that footer injection everywhere except the
 * GeoDirectory archive/search pages where it's actually needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp', function () {

    // Only act if the add-on is active.
    if ( ! function_exists( 'geodir_location_autocomplete_script' ) ) {
        return;
    }

    // Allow only on approved pages.
    if ( ! koopo_should_allow_gdlm_footer_location_switcher() ) {
        remove_action( 'wp_footer', 'geodir_location_autocomplete_script' );
    }

}, 20 );

/**
 * Allowlist logic: only enable the GeoDirectory Location Manager footer script/modal on directory archives.
 *
 * Your confirmed GeoDirectory CPTs:
 *  - gd_place
 *  - gd_event
 *
 * You also mentioned location data is stored in wp_geodir_post_locations (not a WP taxonomy),
 * so location archives are typically rewrite-driven. We therefore allow by both query context and
 * URL path prefixes.
 */
function koopo_should_allow_gdlm_footer_location_switcher(): bool {

    // Never in admin, feeds, AJAX, cron.
    if ( is_admin() || is_feed() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
        return false;
    }

    // Hard-block on BuddyPress/BuddyBoss user/profile screens.
    if ( function_exists( 'bp_is_user' ) && bp_is_user() ) {
        return false;
    }

    // Allow on GeoDirectory CPT archives.
    if ( is_post_type_archive( 'gd_place' ) || is_post_type_archive( 'gd_event' ) ) {
        return true;
    }

    /**
 * Allow only GeoDirectory search pages (NOT WP global search).
 * You said your GeoDirectory search is linked to a page with slug "search-places".
 */
    $allowed_geodir_search_pages = apply_filters( 'koopo_gdlm_allowed_geodir_search_pages', [
    'search-places',
    // add more slugs if you create them later:
    // 'search-events',
    ] );

    if ( is_page( $allowed_geodir_search_pages ) ) {
        return true;
    }


    // Allow when GeoDirectory appears to be driving the page via query vars.
    // These vary by config/addons, so we check both query vars and $_GET.
    $maybe_geodir_qvs = [
        'geodir_search',
        'gd_search',
        'gd_location',
        'country',
        'region',
        'city',
        'neighbourhood',
        'postcode',
        'zip',
        'near',
        'sgeo',
    ];

    foreach ( $maybe_geodir_qvs as $qv ) {
        $val = get_query_var( $qv );
        if ( ! empty( $val ) ) {
            return true;
        }
        if ( isset( $_GET[ $qv ] ) && $_GET[ $qv ] !== '' ) {
            return true;
        }
    }

    // Fallback: allow by URL path prefix (covers /places, /events, /location/..., etc.).
    $allowed_prefixes = apply_filters( 'koopo_gdlm_allowed_path_prefixes', [
        '/places',
        '/events',
        '/location',
    ] );

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if ( is_string( $request_uri ) && $request_uri !== '' ) {
        $path = wp_parse_url( $request_uri, PHP_URL_PATH );
        if ( is_string( $path ) && $path !== '' ) {
            foreach ( $allowed_prefixes as $prefix ) {
                $prefix = '/' . ltrim( (string) $prefix, '/' );
                if ( $prefix !== '/' && str_starts_with( $path, $prefix ) ) {
                    return true;
                }
            }
        }
    }

    return false;
}
