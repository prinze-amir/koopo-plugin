<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Influencer_Square_Analytics {
    const META_VIEWS              = '_koopo_is_views';
    const META_LIKES              = '_koopo_is_likes';
    const META_DISLIKES           = '_koopo_is_dislikes';
    const META_MANUAL_REVENUE     = '_koopo_is_manual_revenue';
    const USER_META_REACTIONS     = 'koopo_is_reactions';
    const OPTION_AD_RPM           = 'koopo_is_ad_rpm';
    const OPTION_CREATOR_SHARE    = 'koopo_is_creator_share_percent';
    const OPTION_REVENUE_SHARE    = 'koopo_is_revenue_sharing_enabled';
    const OPTION_REACTIONS_BEFORE = 'koopo_is_reactions_before_content';
    const OPTION_REACTIONS_AFTER  = 'koopo_is_reactions_after_content';
    const OPTION_CACHE_VERSION    = 'koopo_is_cache_version';
    const OPTION_SCHEMA_VERSION   = 'koopo_is_schema_version';
    const OPTION_BACKFILL_CURSOR  = 'koopo_is_backfill_cursor';
    const OPTION_BACKFILL_DONE    = 'koopo_is_backfill_done';

    const SCHEMA_VERSION         = 2;
    const TABLE_POST_STATS       = 'koopo_is_post_stats';
    const TABLE_DAILY_STATS      = 'koopo_is_daily_stats';
    const TABLE_VIEW_DEDUPE      = 'koopo_is_view_dedupe';
    const CACHE_TTL              = 300;
    const GUEST_VIEW_LIMIT       = 180;
    const DEDUPE_RETENTION_DAYS  = 400;
    const VIEW_POLICY            = 'qualified_daily_unique_viewer';
    const BACKFILL_BATCH_SIZE    = 200;
    const VISITOR_COOKIE         = 'koopo_is_visitor';
    const VISITOR_COOKIE_TTL     = 15552000;
    const FINGERPRINT_POST_LIMIT = 25;
    const FINGERPRINT_DAY_LIMIT  = 250;

    public function __construct() {
        add_action( 'init', array( $this, 'maybe_install_schema' ), 1 );
        add_action( 'init', array( $this, 'maybe_schedule_maintenance' ), 2 );
        add_action( 'koopo_is_cleanup_view_dedupe', array( $this, 'cleanup_view_dedupe' ) );
        add_action( 'koopo_is_backfill_post_stats', array( $this, 'process_backfill_batch' ) );

        add_action( 'updated_option', array( $this, 'maybe_invalidate_cache_for_option' ), 10, 3 );
        add_action( 'updated_post_meta', array( $this, 'maybe_invalidate_cache_for_meta' ), 10, 4 );
        add_action( 'added_post_meta', array( $this, 'maybe_invalidate_cache_for_meta' ), 10, 4 );
        add_action( 'deleted_post_meta', array( $this, 'maybe_invalidate_cache_for_deleted_meta' ), 10, 4 );
        add_action( 'save_post', array( $this, 'maybe_sync_post_stats_on_save' ), 10, 3 );
        add_action( 'deleted_post', array( $this, 'maybe_remove_post_stats' ), 10, 1 );

        add_action( 'comment_post', array( $this, 'handle_comment_post' ), 10, 3 );
        add_action( 'transition_comment_status', array( $this, 'handle_comment_status_transition' ), 10, 3 );
        add_action( 'edit_comment', array( $this, 'handle_comment_edit' ), 10, 1 );
        add_action( 'deleted_comment', array( $this, 'handle_comment_delete' ), 10, 2 );
    }

    public function maybe_install_schema() {
        $installed_version = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
        if ( $installed_version >= self::SCHEMA_VERSION ) {
            $this->maybe_schedule_backfill();
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $post_stats      = $this->get_post_stats_table_name();
        $daily_stats     = $this->get_daily_stats_table_name();
        $view_dedupe     = $this->get_view_dedupe_table_name();

        dbDelta(
            "CREATE TABLE {$post_stats} (
                post_id bigint(20) unsigned NOT NULL,
                author_id bigint(20) unsigned NOT NULL DEFAULT 0,
                post_type varchar(32) NOT NULL DEFAULT '',
                post_status varchar(20) NOT NULL DEFAULT '',
                published_at datetime NULL,
                qualified_views bigint(20) unsigned NOT NULL DEFAULT 0,
                raw_views bigint(20) unsigned NOT NULL DEFAULT 0,
                comments bigint(20) unsigned NOT NULL DEFAULT 0,
                likes bigint(20) unsigned NOT NULL DEFAULT 0,
                dislikes bigint(20) unsigned NOT NULL DEFAULT 0,
                manual_revenue decimal(18,4) NULL,
                updated_at datetime NOT NULL,
                last_viewed_at datetime NULL,
                PRIMARY KEY  (post_id),
                KEY author_status (author_id, post_status),
                KEY type_status (post_type, post_status),
                KEY published_at (published_at)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$daily_stats} (
                post_id bigint(20) unsigned NOT NULL,
                stat_date date NOT NULL,
                author_id bigint(20) unsigned NOT NULL DEFAULT 0,
                post_type varchar(32) NOT NULL DEFAULT '',
                post_status varchar(20) NOT NULL DEFAULT '',
                qualified_views bigint(20) unsigned NOT NULL DEFAULT 0,
                raw_views bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (post_id, stat_date),
                KEY author_date (author_id, stat_date),
                KEY type_status_date (post_type, post_status, stat_date)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$view_dedupe} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                view_date date NOT NULL,
                viewer_hash char(64) NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                fingerprint_hash char(64) NOT NULL DEFAULT '',
                source varchar(20) NOT NULL DEFAULT 'web',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY unique_view (post_id, view_date, viewer_hash),
                KEY post_date (post_id, view_date),
                KEY user_date (user_id, view_date),
                KEY fingerprint_date (fingerprint_hash, view_date),
                KEY view_date (view_date)
            ) {$charset_collate};"
        );

        update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
        update_option( self::OPTION_BACKFILL_CURSOR, 0, false );
        update_option( self::OPTION_BACKFILL_DONE, 0, false );
        $this->bump_cache_version();
        $this->maybe_schedule_backfill( true );
    }

    public function maybe_schedule_maintenance() {
        if ( ! wp_next_scheduled( 'koopo_is_cleanup_view_dedupe' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'koopo_is_cleanup_view_dedupe' );
        }

        $this->maybe_schedule_backfill();
    }

    public function maybe_schedule_backfill( $force = false ) {
        if ( ! $force && (int) get_option( self::OPTION_BACKFILL_DONE, 0 ) ) {
            return;
        }

        if ( wp_next_scheduled( 'koopo_is_backfill_post_stats' ) ) {
            return;
        }

        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'koopo_is_backfill_post_stats' );
    }

    public function process_backfill_batch() {
        $this->maybe_install_schema();

        if ( (int) get_option( self::OPTION_BACKFILL_DONE, 0 ) ) {
            return;
        }

        global $wpdb;

        $post_types    = $this->get_trackable_post_types();
        $statuses      = array( 'publish', 'future', 'draft', 'pending', 'private' );
        $cursor        = absint( get_option( self::OPTION_BACKFILL_CURSOR, 0 ) );
        $type_sql      = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $status_sql    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
        $params        = array_merge( array( $cursor ), $post_types, $statuses, array( self::BACKFILL_BATCH_SIZE ) );
        $sql           = "SELECT ID
                          FROM {$wpdb->posts}
                          WHERE ID > %d
                            AND post_type IN ({$type_sql})
                            AND post_status IN ({$status_sql})
                          ORDER BY ID ASC
                          LIMIT %d";
        $post_ids      = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
        $post_ids      = array_map( 'absint', (array) $post_ids );

        if ( empty( $post_ids ) ) {
            update_option( self::OPTION_BACKFILL_DONE, 1, false );
            wp_clear_scheduled_hook( 'koopo_is_backfill_post_stats' );
            return;
        }

        foreach ( $post_ids as $post_id ) {
            $this->sync_post_stats_row( $post_id, true );
        }

        update_option( self::OPTION_BACKFILL_CURSOR, max( $post_ids ), false );

        if ( count( $post_ids ) < self::BACKFILL_BATCH_SIZE ) {
            update_option( self::OPTION_BACKFILL_DONE, 1, false );
            wp_clear_scheduled_hook( 'koopo_is_backfill_post_stats' );
            return;
        }

        $this->maybe_schedule_backfill( true );
    }

    public function cleanup_view_dedupe() {
        global $wpdb;

        $table      = $this->get_view_dedupe_table_name();
        $cutoff_day = gmdate( 'Y-m-d', current_time( 'timestamp', true ) - ( self::DEDUPE_RETENTION_DAYS * DAY_IN_SECONDS ) );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE view_date < %s",
                $cutoff_day
            )
        );
    }

    public function get_trackable_post_types() {
        $post_types = apply_filters( 'koopo_is_trackable_post_types', array( 'post' ) );
        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );

        return empty( $post_types ) ? array( 'post' ) : $post_types;
    }

    public function is_trackable_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        return $this->is_trackable_post_type( $post->post_type ) && 'publish' === $post->post_status;
    }

    public function is_trackable_post_type( $post_type ) {
        return in_array( sanitize_key( (string) $post_type ), $this->get_trackable_post_types(), true );
    }

    public function should_track_current_request() {
        $user_agent = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
        $purpose    = '';

        foreach ( array( 'HTTP_X_PURPOSE', 'HTTP_PURPOSE', 'HTTP_SEC_PURPOSE' ) as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $purpose = strtolower( sanitize_text_field( (string) $_SERVER[ $header ] ) );
                break;
            }
        }

        if ( '' !== $purpose && preg_match( '/prefetch|preview|prerender/i', $purpose ) ) {
            return false;
        }

        if ( '' !== $user_agent && preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|Slackbot|Discordbot|WhatsApp|preview/i', $user_agent ) ) {
            return false;
        }

        return true;
    }

    public function track_view( $post_id, $dedupe_key = '', $rate_limit_key = '', $source = 'web', $fingerprint_key = '' ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! $this->is_trackable_post( $post_id ) ) {
            return false;
        }

        $this->maybe_install_schema();

        if ( $rate_limit_key && $this->guest_view_rate_limit_exceeded( $rate_limit_key ) ) {
            return false;
        }

        $this->sync_post_stats_row( $post_id, true );

        global $wpdb;

        $now_gmt         = current_time( 'mysql', true );
        $view_date       = gmdate( 'Y-m-d', current_time( 'timestamp', true ) );
        $qualified_delta = 0;
        $user_id         = get_current_user_id();
        $source          = sanitize_key( (string) $source );

        if ( '' !== $dedupe_key ) {
            $viewer_hash      = hash_hmac( 'sha256', $dedupe_key, wp_salt( 'auth' ) );
            $fingerprint_hash = '';

            if ( '' !== $fingerprint_key ) {
                $fingerprint_hash = hash_hmac( 'sha256', $fingerprint_key, wp_salt( 'nonce' ) );
            }

            if ( $user_id || $this->fingerprint_is_within_thresholds( $fingerprint_hash, $view_date, $post_id ) ) {
                $inserted = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$this->get_view_dedupe_table_name()} (post_id, view_date, viewer_hash, user_id, fingerprint_hash, source, created_at) VALUES (%d, %s, %s, %d, %s, %s, %s)",
                        $post_id,
                        $view_date,
                        $viewer_hash,
                        absint( $user_id ),
                        $fingerprint_hash,
                        $source,
                        $now_gmt
                    )
                );

                if ( 1 === (int) $inserted ) {
                    $qualified_delta = 1;
                }
            }
        }

        $context = $this->get_post_context( $post_id );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->get_post_stats_table_name()} SET raw_views = raw_views + 1, qualified_views = qualified_views + %d, last_viewed_at = %s, updated_at = %s WHERE post_id = %d",
                $qualified_delta,
                $now_gmt,
                $now_gmt,
                $post_id
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->get_daily_stats_table_name()} (post_id, stat_date, author_id, post_type, post_status, qualified_views, raw_views, updated_at)
                 VALUES (%d, %s, %d, %s, %s, %d, 1, %s)
                 ON DUPLICATE KEY UPDATE
                    author_id = VALUES(author_id),
                    post_type = VALUES(post_type),
                    post_status = VALUES(post_status),
                    qualified_views = qualified_views + VALUES(qualified_views),
                    raw_views = raw_views + 1,
                    updated_at = VALUES(updated_at)",
                $post_id,
                $view_date,
                $context['author_id'],
                $context['post_type'],
                $context['post_status'],
                $qualified_delta,
                $now_gmt
            )
        );

        if ( $qualified_delta > 0 ) {
            update_post_meta( $post_id, self::META_VIEWS, $this->get_post_views( $post_id ) );
        }

        $this->bump_cache_version_for_post( $post_id );

        return $qualified_delta > 0;
    }

    public function get_current_request_view_context( $allow_guest_cookie_issue = false ) {
        if ( ! $this->should_track_current_request() ) {
            return array(
                'dedupe_key'      => '',
                'rate_limit_key'  => '',
                'fingerprint_key' => '',
                'can_track'       => false,
            );
        }

        if ( is_user_logged_in() ) {
            return array(
                'dedupe_key'      => 'u:' . get_current_user_id(),
                'rate_limit_key'  => '',
                'fingerprint_key' => '',
                'can_track'       => true,
            );
        }

        $visitor_id = $this->get_guest_visitor_id( $allow_guest_cookie_issue );
        if ( '' === $visitor_id ) {
            return array(
                'dedupe_key'      => '',
                'rate_limit_key'  => '',
                'fingerprint_key' => '',
                'can_track'       => false,
            );
        }

        $ip = ! empty( $_SERVER['REMOTE_ADDR'] ) ? wp_privacy_anonymize_ip( sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ua = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

        return array(
            'dedupe_key'      => 'g:' . $visitor_id,
            'rate_limit_key'  => 'koopo_is_rate_' . md5( $visitor_id . '|' . $ip ),
            'fingerprint_key' => $ip . '|' . $ua,
            'can_track'       => true,
        );
    }

    public function maybe_track_view_from_request() {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        if ( ! is_singular( $this->get_trackable_post_types() ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || ! $this->is_trackable_post( $post_id ) ) {
            return;
        }

        $cookie_name = 'koopo_is_view_' . $post_id;
        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
            return;
        }

        $context = $this->get_current_request_view_context( true );
        if ( empty( $context['can_track'] ) ) {
            return;
        }

        $this->track_view( $post_id, $context['dedupe_key'], $context['rate_limit_key'], 'web', $context['fingerprint_key'] );

        setcookie(
            $cookie_name,
            '1',
            time() + ( 30 * MINUTE_IN_SECONDS ),
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        $_COOKIE[ $cookie_name ] = '1';
    }

    public function get_post_stats( $post_id, $viewer_user_id = 0 ) {
        $post_id = absint( $post_id );
        $row     = $this->get_or_seed_post_stats_row( $post_id );

        $views   = isset( $row['qualified_views'] ) ? max( 0, (int) $row['qualified_views'] ) : 0;
        $likes   = isset( $row['likes'] ) ? max( 0, (int) $row['likes'] ) : 0;
        $dislike = isset( $row['dislikes'] ) ? max( 0, (int) $row['dislikes'] ) : 0;
        $metrics = $this->get_revenue_metrics_from_row( $row, $views );

        return array(
            'post_id'           => $post_id,
            'views'             => $views,
            'raw_views'         => isset( $row['raw_views'] ) ? max( 0, (int) $row['raw_views'] ) : 0,
            'view_policy'       => self::VIEW_POLICY,
            'likes'             => $likes,
            'dislikes'          => $dislike,
            'comments'          => isset( $row['comments'] ) ? max( 0, (int) $row['comments'] ) : 0,
            'estimated_revenue' => $metrics['revenue'],
            'creator_share'     => $metrics['creator_share'],
            'admin_share'       => $metrics['admin_share'],
            'revenue_source'    => $metrics['source'],
            'current_reaction'  => $viewer_user_id ? $this->get_user_reaction( $viewer_user_id, $post_id ) : 'none',
        );
    }

    public function get_author_analytics( $author_id ) {
        $author_id = absint( $author_id );
        $author    = get_user_by( 'id', $author_id );
        if ( ! $author ) {
            return array();
        }

        $this->maybe_install_schema();

        $cache_key = $this->get_author_cache_key( $author_id );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $rows     = $this->get_post_stats_rows_for_author( $author_id );
        $totals   = array(
            'articles'          => 0,
            'views'             => 0,
            'likes'             => 0,
            'dislikes'          => 0,
            'comments'          => 0,
            'estimated_revenue' => 0.0,
            'creator_share'     => 0.0,
            'admin_share'       => 0.0,
        );
        $articles = array();

        foreach ( $rows as $row ) {
            $post_id   = (int) $row['post_id'];
            $comments  = max( 0, (int) $row['comments'] );
            $views     = max( 0, (int) $row['qualified_views'] );
            $likes     = max( 0, (int) $row['likes'] );
            $dislikes  = max( 0, (int) $row['dislikes'] );
            $metrics   = $this->get_revenue_metrics_from_row( $row, $views );

            $totals['articles']++;
            $totals['views']             += $views;
            $totals['likes']             += $likes;
            $totals['dislikes']          += $dislikes;
            $totals['comments']          += $comments;
            $totals['estimated_revenue'] += $metrics['revenue'];
            $totals['creator_share']     += $metrics['creator_share'];
            $totals['admin_share']       += $metrics['admin_share'];

            $articles[] = array(
                'id'                => $post_id,
                'title'             => get_the_title( $post_id ),
                'views'             => $views,
                'raw_views'         => max( 0, (int) $row['raw_views'] ),
                'view_policy'       => self::VIEW_POLICY,
                'likes'             => $likes,
                'dislikes'          => $dislikes,
                'comments'          => $comments,
                'estimated_revenue' => $metrics['revenue'],
                'creator_share'     => $metrics['creator_share'],
                'admin_share'       => $metrics['admin_share'],
                'revenue_source'    => $metrics['source'],
                'url'               => get_permalink( $post_id ),
                'edit_url'          => get_edit_post_link( $post_id, '' ),
                'date_gmt'          => get_post_field( 'post_date_gmt', $post_id ),
            );
        }

        usort(
            $articles,
            function( $left, $right ) {
                return (int) $right['views'] <=> (int) $left['views'];
            }
        );

        $totals['estimated_revenue'] = round( $totals['estimated_revenue'], 2 );
        $totals['creator_share']     = round( $totals['creator_share'], 2 );
        $totals['admin_share']       = round( $totals['admin_share'], 2 );

        $data = array(
            'author' => array(
                'id'           => (int) $author_id,
                'display_name' => $author->display_name,
                'email'        => $author->user_email,
            ),
            'settings' => array(
                'ad_rpm'                   => $this->get_ad_rpm(),
                'creator_share_percent'    => $this->get_creator_share_percent(),
                'revenue_sharing_enabled'  => $this->is_revenue_sharing_enabled(),
                'reactions_before_content' => $this->is_reaction_ui_before_content_enabled(),
                'reactions_after_content'  => $this->is_reaction_ui_after_content_enabled(),
                'view_policy'              => self::VIEW_POLICY,
            ),
            'totals'   => $totals,
            'articles' => $articles,
        );

        set_transient( $cache_key, $data, self::CACHE_TTL );

        return $data;
    }

    public function get_global_analytics() {
        $this->maybe_install_schema();

        $cache_key = $this->get_global_cache_key();
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $totals        = $this->get_global_stats_totals();
        $author_rows   = $this->get_global_author_rows();
        $top_post_rows = $this->get_global_top_post_rows();
        $user_ids      = array_unique(
            array_merge(
                wp_list_pluck( $author_rows, 'author_id' ),
                wp_list_pluck( $top_post_rows, 'author_id' )
            )
        );
        $users_map     = $this->get_user_map( $user_ids );

        $authors = array();
        foreach ( $author_rows as $row ) {
            $author_id     = (int) $row['author_id'];
            $user          = isset( $users_map[ $author_id ] ) ? $users_map[ $author_id ] : null;
            $revenue       = isset( $row['revenue'] ) ? round( (float) $row['revenue'], 2 ) : 0.0;
            $creator_share = round( $revenue * ( $this->get_creator_share_percent() / 100 ), 2 );
            $admin_share   = round( max( 0.0, $revenue - $creator_share ), 2 );

            $authors[] = array(
                'author_id'         => $author_id,
                'display_name'      => $user instanceof WP_User ? $user->display_name : sprintf( __( 'Author #%d', 'koopo' ), $author_id ),
                'articles'          => (int) $row['articles'],
                'views'             => (int) $row['views'],
                'likes'             => (int) $row['likes'],
                'dislikes'          => (int) $row['dislikes'],
                'comments'          => (int) $row['comments'],
                'estimated_revenue' => $revenue,
                'creator_share'     => $creator_share,
                'admin_share'       => $admin_share,
            );
        }

        $top_posts = array();
        foreach ( $top_post_rows as $row ) {
            $post_id   = (int) $row['post_id'];
            $author_id = (int) $row['author_id'];
            $metrics   = $this->get_revenue_metrics_from_row( $row, (int) $row['views'] );
            $user      = isset( $users_map[ $author_id ] ) ? $users_map[ $author_id ] : null;

            $top_posts[] = array(
                'id'                => $post_id,
                'title'             => get_the_title( $post_id ),
                'author_id'         => $author_id,
                'author_name'       => $user instanceof WP_User ? $user->display_name : sprintf( __( 'Author #%d', 'koopo' ), $author_id ),
                'views'             => (int) $row['views'],
                'raw_views'         => (int) $row['raw_views'],
                'view_policy'       => self::VIEW_POLICY,
                'likes'             => (int) $row['likes'],
                'dislikes'          => (int) $row['dislikes'],
                'comments'          => (int) $row['comments'],
                'estimated_revenue' => $metrics['revenue'],
                'creator_share'     => $metrics['creator_share'],
                'admin_share'       => $metrics['admin_share'],
                'url'               => get_permalink( $post_id ),
            );
        }

        $data = array(
            'settings' => array(
                'ad_rpm'                   => $this->get_ad_rpm(),
                'creator_share_percent'    => $this->get_creator_share_percent(),
                'revenue_sharing_enabled'  => $this->is_revenue_sharing_enabled(),
                'reactions_before_content' => $this->is_reaction_ui_before_content_enabled(),
                'reactions_after_content'  => $this->is_reaction_ui_after_content_enabled(),
                'view_policy'              => self::VIEW_POLICY,
            ),
            'totals'    => $totals,
            'authors'   => $authors,
            'top_posts' => $top_posts,
        );

        set_transient( $cache_key, $data, self::CACHE_TTL );

        return $data;
    }

    public function maybe_invalidate_cache_for_option( $option, $old_value, $value ) {
        unset( $old_value, $value );

        if ( in_array( $option, array( self::OPTION_AD_RPM, self::OPTION_CREATOR_SHARE, self::OPTION_REVENUE_SHARE, self::OPTION_REACTIONS_BEFORE, self::OPTION_REACTIONS_AFTER ), true ) ) {
            $this->bump_cache_version();
        }
    }

    public function maybe_invalidate_cache_for_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
        unset( $meta_id, $meta_value );

        if ( self::META_MANUAL_REVENUE === $meta_key ) {
            $this->sync_post_stats_row( $post_id, true );
            $this->bump_cache_version_for_post( $post_id );
        }
    }

    public function maybe_invalidate_cache_for_deleted_meta( $meta_ids, $post_id, $meta_key, $meta_value ) {
        unset( $meta_ids, $meta_value );

        if ( self::META_MANUAL_REVENUE === $meta_key ) {
            $this->sync_post_stats_row( $post_id, true );
            $this->bump_cache_version_for_post( $post_id );
        }
    }

    public function maybe_sync_post_stats_on_save( $post_id, $post, $update ) {
        unset( $update );

        if ( wp_is_post_revision( $post_id ) || ! $post instanceof WP_Post ) {
            return;
        }

        if ( ! $this->is_trackable_post_type( $post->post_type ) ) {
            return;
        }

        $this->sync_post_stats_row( $post_id, true );
        $this->bump_cache_version_for_post( $post_id );
    }

    public function maybe_remove_post_stats( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        global $wpdb;

        $wpdb->delete( $this->get_post_stats_table_name(), array( 'post_id' => $post_id ) );
        $wpdb->delete( $this->get_daily_stats_table_name(), array( 'post_id' => $post_id ) );
        $wpdb->delete( $this->get_view_dedupe_table_name(), array( 'post_id' => $post_id ) );

        $this->bump_cache_version();
    }

    public function handle_comment_post( $comment_id, $comment_approved, $comment_data ) {
        unset( $comment_approved );

        if ( ! empty( $comment_data['comment_post_ID'] ) ) {
            $this->sync_comment_count_for_post( absint( $comment_data['comment_post_ID'] ) );
            return;
        }

        $comment = get_comment( $comment_id );
        if ( $comment instanceof WP_Comment ) {
            $this->sync_comment_count_for_post( (int) $comment->comment_post_ID );
        }
    }

    public function handle_comment_status_transition( $new_status, $old_status, $comment ) {
        unset( $new_status, $old_status );

        if ( $comment instanceof WP_Comment ) {
            $this->sync_comment_count_for_post( (int) $comment->comment_post_ID );
        }
    }

    public function handle_comment_edit( $comment_id ) {
        $comment = get_comment( $comment_id );
        if ( $comment instanceof WP_Comment ) {
            $this->sync_comment_count_for_post( (int) $comment->comment_post_ID );
        }
    }

    public function handle_comment_delete( $comment_id, $comment ) {
        if ( $comment instanceof WP_Comment ) {
            $this->sync_comment_count_for_post( (int) $comment->comment_post_ID );
            return;
        }

        $comment = get_comment( $comment_id );
        if ( $comment instanceof WP_Comment ) {
            $this->sync_comment_count_for_post( (int) $comment->comment_post_ID );
        }
    }

    public function get_user_reaction( $user_id, $post_id ) {
        $user_id = absint( $user_id );
        $post_id = absint( $post_id );

        if ( ! $user_id || ! $post_id ) {
            return 'none';
        }

        $reactions = get_user_meta( $user_id, self::USER_META_REACTIONS, true );
        if ( ! is_array( $reactions ) ) {
            return 'none';
        }

        $reaction = isset( $reactions[ $post_id ] ) ? sanitize_key( $reactions[ $post_id ] ) : 'none';
        if ( ! in_array( $reaction, array( 'like', 'dislike' ), true ) ) {
            return 'none';
        }

        return $reaction;
    }

    public function set_user_reaction( $user_id, $post_id, $reaction ) {
        $user_id  = absint( $user_id );
        $post_id  = absint( $post_id );
        $reaction = sanitize_key( $reaction );

        if ( ! $user_id || ! $post_id || ! $this->is_trackable_post( $post_id ) ) {
            return new WP_Error( 'invalid_input', __( 'Invalid user or post.', 'koopo' ), array( 'status' => 400 ) );
        }

        if ( ! in_array( $reaction, array( 'like', 'dislike', 'none' ), true ) ) {
            return new WP_Error( 'invalid_reaction', __( 'Reaction must be like, dislike, or none.', 'koopo' ), array( 'status' => 400 ) );
        }

        $this->maybe_install_schema();
        $this->sync_post_stats_row( $post_id, true );

        $reactions = get_user_meta( $user_id, self::USER_META_REACTIONS, true );
        if ( ! is_array( $reactions ) ) {
            $reactions = array();
        }

        $existing = isset( $reactions[ $post_id ] ) ? sanitize_key( $reactions[ $post_id ] ) : 'none';
        if ( ! in_array( $existing, array( 'like', 'dislike' ), true ) ) {
            $existing = 'none';
        }

        if ( $existing === $reaction ) {
            return $this->get_post_stats( $post_id, $user_id );
        }

        global $wpdb;
        $now_gmt = current_time( 'mysql', true );

        if ( 'like' === $existing ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$this->get_post_stats_table_name()} SET likes = GREATEST(likes - 1, 0), updated_at = %s WHERE post_id = %d", $now_gmt, $post_id ) );
        } elseif ( 'dislike' === $existing ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$this->get_post_stats_table_name()} SET dislikes = GREATEST(dislikes - 1, 0), updated_at = %s WHERE post_id = %d", $now_gmt, $post_id ) );
        }

        if ( 'none' === $reaction ) {
            unset( $reactions[ $post_id ] );
        } else {
            $reactions[ $post_id ] = $reaction;
        }

        update_user_meta( $user_id, self::USER_META_REACTIONS, $reactions );

        if ( 'like' === $reaction ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$this->get_post_stats_table_name()} SET likes = likes + 1, updated_at = %s WHERE post_id = %d", $now_gmt, $post_id ) );
        } elseif ( 'dislike' === $reaction ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$this->get_post_stats_table_name()} SET dislikes = dislikes + 1, updated_at = %s WHERE post_id = %d", $now_gmt, $post_id ) );
        }

        update_post_meta( $post_id, self::META_LIKES, $this->get_post_likes( $post_id ) );
        update_post_meta( $post_id, self::META_DISLIKES, $this->get_post_dislikes( $post_id ) );

        $this->bump_cache_version_for_post( $post_id );

        return $this->get_post_stats( $post_id, $user_id );
    }

    public function get_ad_rpm() {
        $rpm = (float) get_option( self::OPTION_AD_RPM, 8.0 );
        return max( 0.0, $rpm );
    }

    public function get_creator_share_percent() {
        $percent = (float) get_option( self::OPTION_CREATOR_SHARE, 40.0 );
        $percent = max( 0.0, $percent );
        return min( 100.0, $percent );
    }

    public function is_revenue_sharing_enabled() {
        $enabled = get_option( self::OPTION_REVENUE_SHARE, 1 );
        return 1 === (int) $enabled;
    }

    public function is_reaction_ui_before_content_enabled() {
        $enabled = get_option( self::OPTION_REACTIONS_BEFORE, 1 );
        return 1 === (int) $enabled;
    }

    public function is_reaction_ui_after_content_enabled() {
        $enabled = get_option( self::OPTION_REACTIONS_AFTER, 1 );
        return 1 === (int) $enabled;
    }

    private function get_post_views( $post_id ) {
        $row = $this->get_or_seed_post_stats_row( $post_id );
        return isset( $row['qualified_views'] ) ? max( 0, (int) $row['qualified_views'] ) : 0;
    }

    private function get_post_likes( $post_id ) {
        $row = $this->get_or_seed_post_stats_row( $post_id );
        return isset( $row['likes'] ) ? max( 0, (int) $row['likes'] ) : 0;
    }

    private function get_post_dislikes( $post_id ) {
        $row = $this->get_or_seed_post_stats_row( $post_id );
        return isset( $row['dislikes'] ) ? max( 0, (int) $row['dislikes'] ) : 0;
    }

    private function get_revenue_metrics_from_row( $row, $views ) {
        $views = max( 0, (int) $views );

        $manual_revenue = null;
        if ( is_array( $row ) && array_key_exists( 'manual_revenue', $row ) && null !== $row['manual_revenue'] && '' !== $row['manual_revenue'] ) {
            $manual_revenue = (float) $row['manual_revenue'];
        }

        if ( null !== $manual_revenue && $manual_revenue >= 0 ) {
            $revenue = $manual_revenue;
            $source  = 'manual';
        } else {
            $revenue = ( (float) $views / 1000 ) * $this->get_ad_rpm();
            $source  = 'estimated';
        }

        $creator_share = $revenue * ( $this->get_creator_share_percent() / 100 );
        $admin_share   = max( 0.0, $revenue - $creator_share );

        return array(
            'revenue'       => round( $revenue, 2 ),
            'creator_share' => round( $creator_share, 2 ),
            'admin_share'   => round( $admin_share, 2 ),
            'source'        => $source,
        );
    }

    private function sync_post_stats_row( $post_id, $preserve_existing_counts = true ) {
        $post_id = absint( $post_id );
        $post    = get_post( $post_id );

        if ( ! $post instanceof WP_Post || ! $this->is_trackable_post_type( $post->post_type ) ) {
            return false;
        }

        $this->maybe_install_schema();

        global $wpdb;

        $table    = $this->get_post_stats_table_name();
        $existing = $this->get_post_stats_row( $post_id );
        $context  = $this->get_post_context( $post_id );
        $now_gmt  = current_time( 'mysql', true );
        $payload  = array(
            'post_id'        => $post_id,
            'author_id'      => $context['author_id'],
            'post_type'      => $context['post_type'],
            'post_status'    => $context['post_status'],
            'published_at'   => $context['published_at'],
            'comments'       => $this->get_approved_comment_count_for_post( $post_id ),
            'manual_revenue' => $this->get_manual_revenue_value( $post_id ),
            'updated_at'     => $now_gmt,
        );

        if ( empty( $existing ) ) {
            $payload['qualified_views'] = 0;
            $payload['raw_views']       = $preserve_existing_counts ? $this->get_legacy_post_views( $post_id ) : 0;
            $payload['likes']           = $preserve_existing_counts ? max( 0, (int) get_post_meta( $post_id, self::META_LIKES, true ) ) : 0;
            $payload['dislikes']        = $preserve_existing_counts ? max( 0, (int) get_post_meta( $post_id, self::META_DISLIKES, true ) ) : 0;
            $payload['last_viewed_at']  = null;

            $wpdb->replace( $table, $payload );
        } else {
            $wpdb->update( $table, $payload, array( 'post_id' => $post_id ) );
            $this->sync_daily_stats_metadata( $post_id, $context );
        }

        return true;
    }

    private function sync_comment_count_for_post( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! $this->is_trackable_post( $post_id ) ) {
            return;
        }

        $this->sync_post_stats_row( $post_id, true );
        $this->bump_cache_version_for_post( $post_id );
    }

    private function sync_daily_stats_metadata( $post_id, $context ) {
        global $wpdb;

        $wpdb->update(
            $this->get_daily_stats_table_name(),
            array(
                'author_id'   => $context['author_id'],
                'post_type'   => $context['post_type'],
                'post_status' => $context['post_status'],
            ),
            array( 'post_id' => absint( $post_id ) )
        );
    }

    private function get_or_seed_post_stats_row( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return array();
        }

        $this->maybe_install_schema();

        $row = $this->get_post_stats_row( $post_id );
        if ( ! empty( $row ) ) {
            return $row;
        }

        $this->sync_post_stats_row( $post_id, true );

        $row = $this->get_post_stats_row( $post_id );
        return is_array( $row ) ? $row : array();
    }

    private function get_post_stats_row( $post_id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_id, author_id, post_type, post_status, published_at, qualified_views, raw_views, comments, likes, dislikes, manual_revenue, updated_at, last_viewed_at
                 FROM {$this->get_post_stats_table_name()}
                 WHERE post_id = %d",
                absint( $post_id )
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : array();
    }

    private function get_post_stats_rows_for_author( $author_id ) {
        global $wpdb;

        $post_types   = $this->get_trackable_post_types();
        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $params       = array_merge( array( absint( $author_id ) ), $post_types );
        $sql          = "SELECT post_id, author_id, qualified_views, raw_views, comments, likes, dislikes, manual_revenue
                         FROM {$this->get_post_stats_table_name()}
                         WHERE author_id = %d AND post_status = 'publish' AND post_type IN ({$placeholders})
                         ORDER BY qualified_views DESC, post_id DESC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private function get_global_stats_totals() {
        global $wpdb;

        $post_types   = $this->get_trackable_post_types();
        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $sql          = "SELECT COUNT(*) AS articles,
                                COUNT(DISTINCT author_id) AS authors,
                                COALESCE(SUM(qualified_views), 0) AS views,
                                COALESCE(SUM(comments), 0) AS comments,
                                COALESCE(SUM(likes), 0) AS likes,
                                COALESCE(SUM(dislikes), 0) AS dislikes,
                                COALESCE(SUM(CASE WHEN manual_revenue IS NOT NULL THEN manual_revenue ELSE (qualified_views / 1000) * %f END), 0) AS revenue
                         FROM {$this->get_post_stats_table_name()}
                         WHERE post_status = 'publish' AND post_type IN ({$placeholders})";

        $params        = array_merge( array( $this->get_ad_rpm() ), $post_types );
        $row           = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        $row           = is_array( $row ) ? $row : array();
        $revenue       = isset( $row['revenue'] ) ? round( (float) $row['revenue'], 2 ) : 0.0;
        $creator_share = round( $revenue * ( $this->get_creator_share_percent() / 100 ), 2 );
        $admin_share   = round( max( 0.0, $revenue - $creator_share ), 2 );

        return array(
            'articles'          => isset( $row['articles'] ) ? (int) $row['articles'] : 0,
            'authors'           => isset( $row['authors'] ) ? (int) $row['authors'] : 0,
            'views'             => isset( $row['views'] ) ? (int) $row['views'] : 0,
            'likes'             => isset( $row['likes'] ) ? (int) $row['likes'] : 0,
            'dislikes'          => isset( $row['dislikes'] ) ? (int) $row['dislikes'] : 0,
            'comments'          => isset( $row['comments'] ) ? (int) $row['comments'] : 0,
            'estimated_revenue' => $revenue,
            'creator_share'     => $creator_share,
            'admin_share'       => $admin_share,
        );
    }

    private function get_global_author_rows() {
        global $wpdb;

        $post_types   = $this->get_trackable_post_types();
        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $sql          = "SELECT author_id,
                                COUNT(*) AS articles,
                                COALESCE(SUM(qualified_views), 0) AS views,
                                COALESCE(SUM(comments), 0) AS comments,
                                COALESCE(SUM(likes), 0) AS likes,
                                COALESCE(SUM(dislikes), 0) AS dislikes,
                                COALESCE(SUM(CASE WHEN manual_revenue IS NOT NULL THEN manual_revenue ELSE (qualified_views / 1000) * %f END), 0) AS revenue
                         FROM {$this->get_post_stats_table_name()}
                         WHERE post_status = 'publish' AND post_type IN ({$placeholders})
                         GROUP BY author_id
                         ORDER BY views DESC, articles DESC";

        $params = array_merge( array( $this->get_ad_rpm() ), $post_types );
        $rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private function get_global_top_post_rows() {
        global $wpdb;

        $post_types   = $this->get_trackable_post_types();
        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $sql          = "SELECT post_id, author_id, qualified_views AS views, raw_views, comments, likes, dislikes, manual_revenue
                         FROM {$this->get_post_stats_table_name()}
                         WHERE post_status = 'publish' AND post_type IN ({$placeholders})
                         ORDER BY qualified_views DESC, post_id DESC
                         LIMIT 50";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$post_types ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private function get_user_map( $user_ids ) {
        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
        if ( empty( $user_ids ) ) {
            return array();
        }

        $users = get_users(
            array(
                'include' => $user_ids,
                'fields'  => array( 'ID', 'display_name', 'user_email' ),
            )
        );
        $map   = array();

        foreach ( $users as $user ) {
            $map[ (int) $user->ID ] = $user;
        }

        return $map;
    }

    private function get_post_context( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return array(
                'author_id'    => 0,
                'post_type'    => '',
                'post_status'  => '',
                'published_at' => null,
            );
        }

        $published_at = null;
        if ( 'publish' === $post->post_status ) {
            $published_at = $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : get_gmt_from_date( $post->post_date );
        }

        return array(
            'author_id'    => (int) $post->post_author,
            'post_type'    => sanitize_key( $post->post_type ),
            'post_status'  => sanitize_key( $post->post_status ),
            'published_at' => $published_at,
        );
    }

    private function get_manual_revenue_value( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_MANUAL_REVENUE, true );

        if ( '' === $raw || ! is_numeric( $raw ) ) {
            return null;
        }

        return max( 0.0, round( (float) $raw, 4 ) );
    }

    private function get_legacy_post_views( $post_id ) {
        $internal_views = (int) get_post_meta( $post_id, self::META_VIEWS, true );
        $external_views = 0;

        $external_keys = apply_filters(
            'koopo_is_external_view_meta_keys',
            array(
                'wbmb_post_views',
                'wbm_post_views',
                'post_views_count',
                'views',
            )
        );

        foreach ( (array) $external_keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( is_numeric( $value ) ) {
                $external_views = max( $external_views, (int) $value );
            }
        }

        return max( 0, max( $internal_views, $external_views ) );
    }

    private function get_approved_comment_count_for_post( $post_id ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->comments}
                 WHERE comment_post_ID = %d AND comment_approved = '1'",
                absint( $post_id )
            )
        );
    }

    private function guest_view_rate_limit_exceeded( $rate_limit_key ) {
        $rate_limit_key = sanitize_key( (string) $rate_limit_key );
        if ( '' === $rate_limit_key ) {
            return false;
        }

        $count = (int) get_transient( $rate_limit_key );
        if ( $count >= self::GUEST_VIEW_LIMIT ) {
            return true;
        }

        set_transient( $rate_limit_key, $count + 1, HOUR_IN_SECONDS );

        return false;
    }

    private function fingerprint_is_within_thresholds( $fingerprint_hash, $view_date, $post_id ) {
        if ( '' === $fingerprint_hash ) {
            return true;
        }

        global $wpdb;

        $table       = $this->get_view_dedupe_table_name();
        $total_views = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT viewer_hash)
                 FROM {$table}
                 WHERE fingerprint_hash = %s AND view_date = %s",
                $fingerprint_hash,
                $view_date
            )
        );

        if ( $total_views >= self::FINGERPRINT_DAY_LIMIT ) {
            return false;
        }

        $post_views = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT viewer_hash)
                 FROM {$table}
                 WHERE fingerprint_hash = %s AND view_date = %s AND post_id = %d",
                $fingerprint_hash,
                $view_date,
                absint( $post_id )
            )
        );

        return $post_views < self::FINGERPRINT_POST_LIMIT;
    }

    private function get_guest_visitor_id( $allow_create = false ) {
        $cookie_name = self::VISITOR_COOKIE;
        $cookie_value = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

        if ( preg_match( '/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $cookie_value, $matches ) ) {
            $visitor_id = $matches[1];
            $signature  = $matches[2];

            if ( hash_equals( $this->get_visitor_cookie_signature( $visitor_id ), $signature ) ) {
                return $visitor_id;
            }
        }

        if ( ! $allow_create || headers_sent() ) {
            return '';
        }

        try {
            $visitor_id = bin2hex( random_bytes( 16 ) );
        } catch ( Exception $exception ) {
            unset( $exception );
            $visitor_id = md5( wp_generate_password( 32, false, false ) . microtime( true ) );
        }

        $cookie_value = $visitor_id . '.' . $this->get_visitor_cookie_signature( $visitor_id );

        setcookie(
            $cookie_name,
            $cookie_value,
            time() + self::VISITOR_COOKIE_TTL,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
        $_COOKIE[ $cookie_name ] = $cookie_value;

        return $visitor_id;
    }

    private function get_visitor_cookie_signature( $visitor_id ) {
        return hash_hmac( 'sha256', (string) $visitor_id, wp_salt( 'logged_in' ) );
    }

    private function bump_cache_version_for_post( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post || ! $this->is_trackable_post_type( $post->post_type ) ) {
            return;
        }

        $this->bump_cache_version();
    }

    private function bump_cache_version() {
        $version = (int) get_option( self::OPTION_CACHE_VERSION, 1 );
        update_option( self::OPTION_CACHE_VERSION, $version + 1, false );
    }

    private function get_author_cache_key( $author_id ) {
        return 'koopo_is_author_' . absint( $author_id ) . '_' . $this->get_cache_version();
    }

    private function get_global_cache_key() {
        return 'koopo_is_global_' . $this->get_cache_version();
    }

    private function get_cache_version() {
        return max( 1, (int) get_option( self::OPTION_CACHE_VERSION, 1 ) );
    }

    private function get_post_stats_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_POST_STATS;
    }

    private function get_daily_stats_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_DAILY_STATS;
    }

    private function get_view_dedupe_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_VIEW_DEDUPE;
    }
}
