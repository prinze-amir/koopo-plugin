<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Koopo_Influencer_Square_Analytics {
    const META_VIEWS           = '_koopo_is_views';
    const META_LIKES           = '_koopo_is_likes';
    const META_DISLIKES        = '_koopo_is_dislikes';
    const META_MANUAL_REVENUE  = '_koopo_is_manual_revenue';
    const USER_META_REACTIONS  = 'koopo_is_reactions';
    const OPTION_AD_RPM        = 'koopo_is_ad_rpm';
    const OPTION_CREATOR_SHARE = 'koopo_is_creator_share_percent';
    const OPTION_REVENUE_SHARE = 'koopo_is_revenue_sharing_enabled';

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

        return in_array( $post->post_type, $this->get_trackable_post_types(), true ) && 'publish' === $post->post_status;
    }

    public function track_view( $post_id, $dedupe_key = '' ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! $this->is_trackable_post( $post_id ) ) {
            return false;
        }

        if ( $dedupe_key ) {
            $lock_key = 'koopo_is_view_' . md5( $post_id . '|' . $dedupe_key );
            if ( get_transient( $lock_key ) ) {
                return false;
            }

            set_transient( $lock_key, '1', 30 * MINUTE_IN_SECONDS );
        }

        $this->increment_counter( $post_id, self::META_VIEWS, 1 );
        return true;
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

        $this->track_view( $post_id );

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
        $views   = $this->get_post_views( $post_id );
        $likes   = $this->get_post_likes( $post_id );
        $dislike = $this->get_post_dislikes( $post_id );
        $metrics = $this->get_revenue_metrics_for_post( $post_id, $views );

        return array(
            'post_id'           => $post_id,
            'views'             => $views,
            'likes'             => $likes,
            'dislikes'          => $dislike,
            'comments'          => (int) get_comments_number( $post_id ),
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

        $post_ids = get_posts(
            array(
                'post_type'      => $this->get_trackable_post_types(),
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'author'         => $author_id,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        $totals = array(
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

        foreach ( $post_ids as $post_id ) {
            $stats = $this->get_post_stats( $post_id );

            $totals['articles']++;
            $totals['views']             += $stats['views'];
            $totals['likes']             += $stats['likes'];
            $totals['dislikes']          += $stats['dislikes'];
            $totals['comments']          += $stats['comments'];
            $totals['estimated_revenue'] += $stats['estimated_revenue'];
            $totals['creator_share']     += $stats['creator_share'];
            $totals['admin_share']       += $stats['admin_share'];

            $articles[] = array(
                'id'                => $post_id,
                'title'             => get_the_title( $post_id ),
                'views'             => $stats['views'],
                'likes'             => $stats['likes'],
                'dislikes'          => $stats['dislikes'],
                'comments'          => $stats['comments'],
                'estimated_revenue' => $stats['estimated_revenue'],
                'creator_share'     => $stats['creator_share'],
                'admin_share'       => $stats['admin_share'],
                'revenue_source'    => $stats['revenue_source'],
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

        return array(
            'author' => array(
                'id'           => (int) $author_id,
                'display_name' => $author->display_name,
                'email'        => $author->user_email,
            ),
            'settings' => array(
                'ad_rpm'                => $this->get_ad_rpm(),
                'creator_share_percent' => $this->get_creator_share_percent(),
                'revenue_sharing_enabled' => $this->is_revenue_sharing_enabled(),
            ),
            'totals'   => $totals,
            'articles' => $articles,
        );
    }

    public function get_global_analytics() {
        $post_ids = get_posts(
            array(
                'post_type'      => $this->get_trackable_post_types(),
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        $totals = array(
            'articles'          => 0,
            'authors'           => 0,
            'views'             => 0,
            'likes'             => 0,
            'dislikes'          => 0,
            'comments'          => 0,
            'estimated_revenue' => 0.0,
            'creator_share'     => 0.0,
            'admin_share'       => 0.0,
        );

        $author_rows = array();
        $top_posts   = array();

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post instanceof WP_Post ) {
                continue;
            }

            $stats = $this->get_post_stats( $post_id );

            $totals['articles']++;
            $totals['views']             += $stats['views'];
            $totals['likes']             += $stats['likes'];
            $totals['dislikes']          += $stats['dislikes'];
            $totals['comments']          += $stats['comments'];
            $totals['estimated_revenue'] += $stats['estimated_revenue'];
            $totals['creator_share']     += $stats['creator_share'];
            $totals['admin_share']       += $stats['admin_share'];

            $author_id = (int) $post->post_author;
            if ( ! isset( $author_rows[ $author_id ] ) ) {
                $author_rows[ $author_id ] = array(
                    'author_id'          => $author_id,
                    'display_name'       => get_the_author_meta( 'display_name', $author_id ),
                    'articles'           => 0,
                    'views'              => 0,
                    'likes'              => 0,
                    'dislikes'           => 0,
                    'comments'           => 0,
                    'estimated_revenue'  => 0.0,
                    'creator_share'      => 0.0,
                    'admin_share'        => 0.0,
                );
            }

            $author_rows[ $author_id ]['articles']++;
            $author_rows[ $author_id ]['views']             += $stats['views'];
            $author_rows[ $author_id ]['likes']             += $stats['likes'];
            $author_rows[ $author_id ]['dislikes']          += $stats['dislikes'];
            $author_rows[ $author_id ]['comments']          += $stats['comments'];
            $author_rows[ $author_id ]['estimated_revenue'] += $stats['estimated_revenue'];
            $author_rows[ $author_id ]['creator_share']     += $stats['creator_share'];
            $author_rows[ $author_id ]['admin_share']       += $stats['admin_share'];

            $top_posts[] = array(
                'id'                => $post_id,
                'title'             => get_the_title( $post_id ),
                'author_id'         => $author_id,
                'author_name'       => get_the_author_meta( 'display_name', $author_id ),
                'views'             => $stats['views'],
                'likes'             => $stats['likes'],
                'dislikes'          => $stats['dislikes'],
                'comments'          => $stats['comments'],
                'estimated_revenue' => $stats['estimated_revenue'],
                'creator_share'     => $stats['creator_share'],
                'admin_share'       => $stats['admin_share'],
                'url'               => get_permalink( $post_id ),
            );
        }

        $totals['authors']           = count( $author_rows );
        $totals['estimated_revenue'] = round( $totals['estimated_revenue'], 2 );
        $totals['creator_share']     = round( $totals['creator_share'], 2 );
        $totals['admin_share']       = round( $totals['admin_share'], 2 );

        foreach ( $author_rows as $author_id => $row ) {
            $author_rows[ $author_id ]['estimated_revenue'] = round( $row['estimated_revenue'], 2 );
            $author_rows[ $author_id ]['creator_share']     = round( $row['creator_share'], 2 );
            $author_rows[ $author_id ]['admin_share']       = round( $row['admin_share'], 2 );
        }

        usort(
            $top_posts,
            function( $left, $right ) {
                return (int) $right['views'] <=> (int) $left['views'];
            }
        );

        return array(
            'settings' => array(
                'ad_rpm'                => $this->get_ad_rpm(),
                'creator_share_percent' => $this->get_creator_share_percent(),
                'revenue_sharing_enabled' => $this->is_revenue_sharing_enabled(),
            ),
            'totals'   => $totals,
            'authors'  => array_values( $author_rows ),
            'top_posts' => array_slice( $top_posts, 0, 50 ),
        );
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

        if ( 'like' === $existing ) {
            $this->increment_counter( $post_id, self::META_LIKES, -1 );
        } elseif ( 'dislike' === $existing ) {
            $this->increment_counter( $post_id, self::META_DISLIKES, -1 );
        }

        if ( 'none' === $reaction ) {
            unset( $reactions[ $post_id ] );
        } else {
            $reactions[ $post_id ] = $reaction;
        }

        update_user_meta( $user_id, self::USER_META_REACTIONS, $reactions );

        if ( 'like' === $reaction ) {
            $this->increment_counter( $post_id, self::META_LIKES, 1 );
        } elseif ( 'dislike' === $reaction ) {
            $this->increment_counter( $post_id, self::META_DISLIKES, 1 );
        }

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

    private function get_revenue_metrics_for_post( $post_id, $views = null ) {
        if ( null === $views ) {
            $views = $this->get_post_views( $post_id );
        }

        $manual_revenue_raw = get_post_meta( $post_id, self::META_MANUAL_REVENUE, true );
        $has_manual_revenue = '' !== $manual_revenue_raw && is_numeric( $manual_revenue_raw );

        if ( $has_manual_revenue ) {
            $revenue = max( 0.0, (float) $manual_revenue_raw );
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

    private function get_post_views( $post_id ) {
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

    private function get_post_likes( $post_id ) {
        return max( 0, (int) get_post_meta( $post_id, self::META_LIKES, true ) );
    }

    private function get_post_dislikes( $post_id ) {
        return max( 0, (int) get_post_meta( $post_id, self::META_DISLIKES, true ) );
    }

    private function increment_counter( $post_id, $meta_key, $delta ) {
        $current = (int) get_post_meta( $post_id, $meta_key, true );
        $next    = max( 0, $current + (int) $delta );
        update_post_meta( $post_id, $meta_key, $next );
    }
}
