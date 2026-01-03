<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_REST {

    public static function register_routes() : void {
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [ __CLASS__, 'get_feed' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
                'args' => [
                    'limit' => [ 'default' => 20 ],
                    'scope' => [ 'default' => 'friends' ], // friends|following|all
                    'exclude_me' => [ 'default' => 0 ],
                    'order' => [ 'default' => 'unseen_first' ], // unseen_first|recent_activity
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ __CLASS__, 'create_story' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ __CLASS__, 'get_story' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/items/(?P<id>\d+)/seen', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ __CLASS__, 'mark_seen' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Close friends management
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/close-friends', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ __CLASS__, 'get_close_friends' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/close-friends/(?P<friend_id>\d+)', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [ __CLASS__, 'add_close_friend' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [ __CLASS__, 'remove_close_friend' ],
                'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
            ],
        ] );

        // Reactions
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/reactions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ __CLASS__, 'get_reactions' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/reactions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ __CLASS__, 'add_reaction' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/reactions', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [ __CLASS__, 'remove_reaction' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Replies
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/replies', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ __CLASS__, 'get_replies' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/replies', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [ __CLASS__, 'add_reply' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/replies/(?P<reply_id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [ __CLASS__, 'delete_reply' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        // Analytics & Insights
        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/viewers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ __CLASS__, 'get_viewers' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );

        register_rest_route( Koopo_Stories_Module::REST_NS, '/stories/(?P<story_id>\d+)/analytics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [ __CLASS__, 'get_analytics' ],
            'permission_callback' => [ __CLASS__, 'must_be_logged_in' ],
        ] );
    }

    public static function must_be_logged_in() : bool {
        return is_user_logged_in();
    }

    public static function get_feed( WP_REST_Request $req ) {
        $user_id = get_current_user_id();

// Capability: must be able to upload media
if ( ! current_user_can('upload_files') ) {
    return new WP_REST_Response([ 'error' => 'forbidden', 'message' => 'upload_not_allowed' ], 403);
}

// Enforce per-day upload limit (0 = unlimited)
$max_per_day = (int) get_option('koopo_stories_max_uploads_per_day', 20);
if ( $max_per_day > 0 ) {
    $today_start = strtotime('today', current_time('timestamp'));
    $ids_today = get_posts([
        'post_type' => Koopo_Stories_Module::CPT_ITEM,
        'post_status' => 'any',
        'author' => $user_id,
        'fields' => 'ids',
        'posts_per_page' => -1,
        'date_query' => [
            [
                'after' => date('Y-m-d H:i:s', $today_start),
                'inclusive' => true,
            ],
        ],
    ]); 
                if ( is_array($ids_today) && count($ids_today) >= $max_per_day ) {
        return new WP_REST_Response([ 'error' => 'limit_reached', 'message' => 'daily_upload_limit' ], 429);
    }
}

$duration_hours = (int) get_option('koopo_stories_duration_hours', 24);
if ( $duration_hours < 1 ) $duration_hours = 24;
$expires_at_new = time() + ( $duration_hours * HOUR_IN_SECONDS );

$max_items_per_story = (int) get_option('koopo_stories_max_items_per_story', 20);
if ( $max_items_per_story < 0 ) $max_items_per_story = 0;


        $limit = max(1, min(50, intval($req->get_param('limit'))));
        $scope = $req->get_param('scope');
        $scope = in_array($scope, ['friends','following','all'], true) ? $scope : 'friends';

        $exclude_me = intval($req->get_param('exclude_me')) === 1;
        $order = $req->get_param('order');
        $order = in_array($order, ['unseen_first','recent_activity'], true) ? $order : 'unseen_first';

        // Resolve which authors we should include for this scope
        $author_ids = [];
        if ( $scope === 'friends' ) {
            $author_ids = Koopo_Stories_Permissions::friend_ids($user_id);
        } elseif ( $scope === 'following' ) {
            $author_ids = Koopo_Stories_Permissions::following_ids($user_id);
        }

        if ( $scope !== 'all' ) {
            // include self by default unless excluded
            if ( ! $exclude_me ) {
                $author_ids[] = $user_id;
            }
            $author_ids = array_values(array_unique(array_filter(array_map('intval', $author_ids))));
            if ( empty($author_ids) ) {
                return new WP_REST_Response([ 'stories' => [] ], 200);
            }
        }

        // Query a bit more if we plan to sort by unseen-first, so we can fill the limit after sorting
        $query_limit = $limit;
        if ( $order === 'unseen_first' ) {
            $query_limit = min(200, max($limit, $limit * 4));
        }

        $q = [
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'publish',
            'posts_per_page' => $query_limit,
            'orderby' => ($order === 'recent_activity' || $order === 'unseen_first') ? 'modified' : 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'expires_at',
                    'value' => time(),
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => 'expires_at',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        if ( $scope !== 'all' ) {
            $q['author__in'] = $author_ids;
        }

        $stories = get_posts($q);

        $out = [];
        foreach ( $stories as $story ) {
            $sid = (int) $story->ID;

            // If privacy is connections-only, enforce it (for 'all' scope too)
            if ( ! Koopo_Stories_Permissions::can_view_story($sid, $user_id) ) {
                continue;
            }

            $items = get_posts([
                'post_type' => Koopo_Stories_Module::CPT_ITEM,
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'meta_key' => 'story_id',
                'meta_value' => $sid,
                'orderby' => 'date',
                'order' => 'ASC',
            ]);

            $items_count = is_array($items) ? count($items) : 0;
            if ( $items_count === 0 ) continue;

            $has_unseen = false;
            $unseen_count = 0;
            $cover_thumb = '';

            $seen_map = Koopo_Stories_Views_Table::has_seen_any($items, $user_id);
            foreach ($items as $iid) {
                if ( empty($seen_map[(int)$iid]) ) {
                    $has_unseen = true;
                    $unseen_count++;
                }
            }

            // Cover thumb: first item thumb
            $first_item_id = (int) $items[0];
            $att_id = (int) get_post_meta($first_item_id, 'attachment_id', true);
            if ( $att_id ) {
                $thumb = wp_get_attachment_image_url($att_id, 'thumbnail');
                if ( $thumb ) $cover_thumb = $thumb;
            }

            $author_id = (int) $story->post_author;
            $profile_url = '';
            if ( function_exists('bp_core_get_user_domain') ) {
                $profile_url = bp_core_get_user_domain($author_id);
            }

            $privacy = get_post_meta($sid, 'privacy', true);
            if ( empty($privacy) ) $privacy = 'friends';

            $out[] = [
                'story_id' => $sid,
                'author' => [
                    'id' => $author_id,
                    'name' => get_the_author_meta('display_name', $author_id),
                    'avatar' => get_avatar_url($author_id, [ 'size' => 96 ]),
                    'profile_url' => $profile_url,
                ],
                'cover_thumb' => $cover_thumb,
                'last_updated' => get_post_modified_time(DATE_ATOM, true, $sid),
                'has_unseen' => $has_unseen,
                'unseen_count' => $unseen_count,
                'items_count' => $items_count,
                'privacy' => $privacy,
            ];
        }

        if ( $order === 'unseen_first' ) {
            usort($out, function($a, $b){
                if ( (int)$a['has_unseen'] !== (int)$b['has_unseen'] ) {
                    return ((int)$b['has_unseen']) <=> ((int)$a['has_unseen']);
                }
                return strcmp($b['last_updated'], $a['last_updated']);
            });
        } else {
            usort($out, function($a, $b){
                return strcmp($b['last_updated'], $a['last_updated']);
            });
        }

        $out = array_slice($out, 0, $limit);

        return new WP_REST_Response([ 'stories' => array_values($out) ], 200);
    }

    public static function get_story( WP_REST_Request $req ) {
        $user_id = get_current_user_id();

// Capability: must be able to upload media
if ( ! current_user_can('upload_files') ) {
    return new WP_REST_Response([ 'error' => 'forbidden', 'message' => 'upload_not_allowed' ], 403);
}

// Enforce per-day upload limit (0 = unlimited)
$max_per_day = (int) get_option('koopo_stories_max_uploads_per_day', 20);
if ( $max_per_day > 0 ) {
    $today_start = strtotime('today', current_time('timestamp'));
    $ids_today = get_posts([
        'post_type' => Koopo_Stories_Module::CPT_ITEM,
        'post_status' => 'any',
        'author' => $user_id,
        'fields' => 'ids',
        'posts_per_page' => -1,
        'date_query' => [
            [
                'after' => date('Y-m-d H:i:s', $today_start),
                'inclusive' => true,
            ],
        ],
    ]); 
                if ( is_array($ids_today) && count($ids_today) >= $max_per_day ) {
        return new WP_REST_Response([ 'error' => 'limit_reached', 'message' => 'daily_upload_limit' ], 429);
    }
}

$duration_hours = (int) get_option('koopo_stories_duration_hours', 24);
if ( $duration_hours < 1 ) $duration_hours = 24;
$expires_at_new = time() + ( $duration_hours * HOUR_IN_SECONDS );

$max_items_per_story = (int) get_option('koopo_stories_max_items_per_story', 20);
if ( $max_items_per_story < 0 ) $max_items_per_story = 0;

        $story_id = (int) $req['id'];

        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( $story->post_status !== 'publish' ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        $author_id = (int) $story->post_author;
        $author = get_user_by('id', $author_id);

        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $items_out = [];
        foreach ( $items as $item ) {
            $item_id = (int) $item->ID;
            $attachment_id = (int) get_post_meta($item_id, 'attachment_id', true);
            $type = get_post_meta($item_id, 'media_type', true);
            $type = ($type === 'video') ? 'video' : 'image';
            $src = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
            $thumb = '';
            if ( $attachment_id ) {
                $t = wp_get_attachment_image_src($attachment_id, 'medium');
                if ( is_array($t) && ! empty($t[0]) ) $thumb = $t[0];
            }
            $duration = (int) get_post_meta($item_id, 'duration_ms', true);
            if ( $duration <= 0 && $type === 'image' ) $duration = 5000;

            $items_out[] = [
                'item_id' => $item_id,
                'type' => $type,
                'src' => $src,
                'thumb' => $thumb,
                'duration_ms' => $type === 'image' ? $duration : null,
                'created_at' => mysql_to_rfc3339( get_gmt_from_date($item->post_date) ),
            ];
        }

        $profile_url = '';
        if ( function_exists('bp_core_get_user_domain') ) {
            $profile_url = bp_core_get_user_domain($author_id);
        }

        $privacy = get_post_meta($story_id, 'privacy', true);
        if ( empty($privacy) ) $privacy = 'friends';

        // Get view counts
        $item_ids = array_map(function($item) { return (int) $item->ID; }, $items);
        $view_count = Koopo_Stories_Views_Table::get_story_view_count($item_ids);

        // Get reaction counts
        $reaction_counts = Koopo_Stories_Reactions::get_reaction_counts($story_id);
        $total_reactions = array_sum($reaction_counts);

        return new WP_REST_Response([
            'story_id' => $story_id,
            'author' => [
                'id' => $author_id,
                'name' => $author ? $author->display_name : ('User #' . $author_id),
                'avatar' => get_avatar_url($author_id, [ 'size' => 96 ]),
                'profile_url' => $profile_url,
            ],
            'items' => $items_out,
            'privacy' => $privacy,
            'analytics' => [
                'view_count' => $view_count,
                'reaction_count' => $total_reactions,
                'reactions' => $reaction_counts,
            ],
        ], 200);
    }

    public static function mark_seen( WP_REST_Request $req ) {
        $user_id = get_current_user_id();

// Capability: must be able to upload media
if ( ! current_user_can('upload_files') ) {
    return new WP_REST_Response([ 'error' => 'forbidden', 'message' => 'upload_not_allowed' ], 403);
}

// Enforce per-day upload limit (0 = unlimited)
$max_per_day = (int) get_option('koopo_stories_max_uploads_per_day', 20);
if ( $max_per_day > 0 ) {
    $today_start = strtotime('today', current_time('timestamp'));
    $ids_today = get_posts([
        'post_type' => Koopo_Stories_Module::CPT_ITEM,
        'post_status' => 'any',
        'author' => $user_id,
        'fields' => 'ids',
        'posts_per_page' => -1,
        'date_query' => [
            [
                'after' => date('Y-m-d H:i:s', $today_start),
                'inclusive' => true,
            ],
        ],
    ]); 
                if ( is_array($ids_today) && count($ids_today) >= $max_per_day ) {
        return new WP_REST_Response([ 'error' => 'limit_reached', 'message' => 'daily_upload_limit' ], 429);
    }
}

$duration_hours = (int) get_option('koopo_stories_duration_hours', 24);
if ( $duration_hours < 1 ) $duration_hours = 24;
$expires_at_new = time() + ( $duration_hours * HOUR_IN_SECONDS );

$max_items_per_story = (int) get_option('koopo_stories_max_items_per_story', 20);
if ( $max_items_per_story < 0 ) $max_items_per_story = 0;

        $item_id = (int) $req['id'];

        $item = get_post($item_id);
        if ( ! $item || $item->post_type !== Koopo_Stories_Module::CPT_ITEM ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }
        $story_id = (int) get_post_meta($item_id, 'story_id', true);
        if ( $story_id && ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        Koopo_Stories_Views_Table::mark_seen($item_id, $user_id);
        return new WP_REST_Response([ 'ok' => true ], 200);
    }

    public static function create_story( WP_REST_Request $req ) {
        // MVP: accept multipart upload "file"
        $user_id = get_current_user_id();

// Capability: must be able to upload media
if ( ! current_user_can('upload_files') ) {
    return new WP_REST_Response([ 'error' => 'forbidden', 'message' => 'upload_not_allowed' ], 403);
}

// Enforce per-day upload limit (0 = unlimited)
$max_per_day = (int) get_option('koopo_stories_max_uploads_per_day', 20);
if ( $max_per_day > 0 ) {
    $today_start = strtotime('today', current_time('timestamp'));
    $ids_today = get_posts([
        'post_type' => Koopo_Stories_Module::CPT_ITEM,
        'post_status' => 'any',
        'author' => $user_id,
        'fields' => 'ids',
        'posts_per_page' => -1,
        'date_query' => [
            [
                'after' => date('Y-m-d H:i:s', $today_start),
                'inclusive' => true,
            ],
        ],
    ]); 
                if ( is_array($ids_today) && count($ids_today) >= $max_per_day ) {
        return new WP_REST_Response([ 'error' => 'limit_reached', 'message' => 'daily_upload_limit' ], 429);
    }
}

$duration_hours = (int) get_option('koopo_stories_duration_hours', 24);
if ( $duration_hours < 1 ) $duration_hours = 24;
$expires_at_new = time() + ( $duration_hours * HOUR_IN_SECONDS );

$max_items_per_story = (int) get_option('koopo_stories_max_items_per_story', 20);
if ( $max_items_per_story < 0 ) $max_items_per_story = 0;


        if ( empty($_FILES['file']) || ! is_array($_FILES['file']) ) {
            return new WP_REST_Response([ 'error' => 'missing_file' ], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Upload to media library
        $attachment_id = media_handle_upload('file', 0);
        if ( is_wp_error($attachment_id) ) {
            return new WP_REST_Response([ 'error' => 'upload_failed', 'message' => $attachment_id->get_error_message() ], 400);
        }

        // Determine media type
        $mime = get_post_mime_type($attachment_id);
        $media_type = (is_string($mime) && strpos($mime, 'video/') === 0) ? 'video' : 'image';

                // Find existing active story for this user (within configured duration)
        $existing = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'publish',
            'author' => $user_id,
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'expires_at',
                    'value' => time(),
                    'compare' => '>',
                    'type' => 'NUMERIC',
                ],
                [
                    'key' => 'expires_at',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $story_id = 0;

        if ( ! empty($existing) ) {
            $story_id = (int) $existing[0]->ID;

            // If story has no expiry meta (legacy), set it now.
            $ex = (int) get_post_meta($story_id, 'expires_at', true);
            if ( $ex <= 0 ) {
                update_post_meta($story_id, 'expires_at', $expires_at_new);
            }

            // If privacy is missing (legacy), default to friends.
            $pv = get_post_meta($story_id, 'privacy', true);
            if ( ! $pv ) {
                update_post_meta($story_id, 'privacy', 'friends');
            }

            // Enforce max items per story: if reached, start a new story instead.
            if ( $max_items_per_story > 0 ) {
                $item_ids = get_posts([
                    'post_type' => Koopo_Stories_Module::CPT_ITEM,
                    'post_status' => 'any',
                    'fields' => 'ids',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        [
                            'key' => 'story_id',
                            'value' => $story_id,
                            'compare' => '=',
                        ],
                    ],
                ]);
                if ( is_array($item_ids) && count($item_ids) >= $max_items_per_story ) {
                    $story_id = 0;
                }
            }
        }

        if ( ! $story_id ) {
            $story_id = wp_insert_post([
                'post_type' => Koopo_Stories_Module::CPT_STORY,
                'post_status' => 'publish',
                'post_title' => 'Story - ' . $user_id . ' - ' . current_time('mysql'),
                'post_author' => $user_id,
            ], true);

            if ( is_wp_error($story_id) ) {
                return new WP_REST_Response([ 'error' => 'create_failed' ], 400);
            }

            $privacy = $req->get_param('privacy');
            $allowed_privacy = ['public', 'friends', 'close_friends'];
            $privacy = in_array($privacy, $allowed_privacy, true) ? $privacy : 'friends';
            update_post_meta($story_id, 'privacy', $privacy);
            update_post_meta($story_id, 'expires_at', $expires_at_new);
        }

        // Create story item
        $item_id = wp_insert_post([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'post_title' => 'Item - ' . $attachment_id,
            'post_author' => $user_id,
        ], true);

        if ( is_wp_error($item_id) ) {
            return new WP_REST_Response([ 'error' => 'create_item_failed' ], 400);
        }

        update_post_meta($item_id, 'story_id', $story_id);
        update_post_meta($item_id, 'attachment_id', (int)$attachment_id);
        update_post_meta($item_id, 'media_type', $media_type);
        if ( $media_type === 'image' ) {
            update_post_meta($item_id, 'duration_ms', 5000);
        }

        // bump modified date of story
        wp_update_post([
            'ID' => $story_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);

        return new WP_REST_Response([
            'ok' => true,
            'story_id' => $story_id,
            'item_id' => $item_id,
        ], 200);
    }

    /**
     * Get current user's close friends list
     */
    public static function get_close_friends( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_REST_Response(['error' => 'unauthorized'], 401);
        }

        $friend_ids = Koopo_Stories_Close_Friends::get_close_friends($user_id);

        $friends = [];
        foreach ($friend_ids as $fid) {
            $user = get_user_by('id', $fid);
            if ($user) {
                $profile_url = '';
                if ( function_exists('bp_core_get_user_domain') ) {
                    $profile_url = bp_core_get_user_domain($fid);
                }

                $friends[] = [
                    'id' => $fid,
                    'name' => $user->display_name,
                    'avatar' => get_avatar_url($fid, ['size' => 96]),
                    'profile_url' => $profile_url,
                ];
            }
        }

        return new WP_REST_Response([
            'friends' => $friends,
            'count' => count($friends),
        ], 200);
    }

    /**
     * Add a user to close friends list
     */
    public static function add_close_friend( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_REST_Response(['error' => 'unauthorized'], 401);
        }

        $friend_id = (int) $req['friend_id'];

        if ($friend_id <= 0) {
            return new WP_REST_Response(['error' => 'invalid_user'], 400);
        }

        // Verify friend exists
        $friend = get_user_by('id', $friend_id);
        if (!$friend) {
            return new WP_REST_Response(['error' => 'user_not_found'], 404);
        }

        $success = Koopo_Stories_Close_Friends::add_friend($user_id, $friend_id);

        if ($success) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Friend added to close friends',
                'friend_id' => $friend_id,
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed_to_add'], 500);
    }

    /**
     * Remove a user from close friends list
     */
    public static function remove_close_friend( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_REST_Response(['error' => 'unauthorized'], 401);
        }

        $friend_id = (int) $req['friend_id'];

        if ($friend_id <= 0) {
            return new WP_REST_Response(['error' => 'invalid_user'], 400);
        }

        $success = Koopo_Stories_Close_Friends::remove_friend($user_id, $friend_id);

        if ($success) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Friend removed from close friends',
                'friend_id' => $friend_id,
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed_to_remove'], 500);
    }

    /**
     * Get reactions for a story
     */
    public static function get_reactions( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $reactions = Koopo_Stories_Reactions::get_reactions($story_id, $item_id);
        $counts = Koopo_Stories_Reactions::get_reaction_counts($story_id, $item_id);
        $user_reaction = Koopo_Stories_Reactions::get_user_reaction($story_id, $user_id, $item_id);

        return new WP_REST_Response([
            'reactions' => $reactions,
            'counts' => $counts,
            'total' => array_sum($counts),
            'user_reaction' => $user_reaction,
        ], 200);
    }

    /**
     * Add or update a reaction
     */
    public static function add_reaction( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;
        $reaction = $req->get_param('reaction');

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if ( empty($reaction) ) {
            return new WP_REST_Response(['error' => 'reaction_required'], 400);
        }

        $success = Koopo_Stories_Reactions::add_reaction($story_id, $user_id, $reaction, $item_id);

        if ( $success ) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Reaction added',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Remove a reaction
     */
    public static function remove_reaction( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;

        $success = Koopo_Stories_Reactions::remove_reaction($story_id, $user_id, $item_id);

        if ( $success ) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Reaction removed',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Get replies for a story
     */
    public static function get_replies( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;
        $limit = min(100, max(1, (int) $req->get_param('limit') ?: 50));

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        $replies = Koopo_Stories_Replies::get_replies($story_id, $user_id, $item_id, $limit);

        // Enhance replies with user info
        $replies_out = [];
        foreach ( $replies as $reply ) {
            $user = get_user_by('id', $reply['user_id']);
            $replies_out[] = [
                'id' => (int) $reply['id'],
                'message' => $reply['message'],
                'is_dm' => (bool) $reply['is_dm'],
                'created_at' => $reply['created_at'],
                'user' => [
                    'id' => (int) $reply['user_id'],
                    'name' => $user ? $user->display_name : 'User',
                    'avatar' => get_avatar_url($reply['user_id'], ['size' => 48]),
                ],
            ];
        }

        return new WP_REST_Response([
            'replies' => $replies_out,
            'count' => count($replies_out),
        ], 200);
    }

    /**
     * Add a reply
     */
    public static function add_reply( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];
        $item_id = $req->get_param('item_id') ? (int) $req->get_param('item_id') : null;
        $message = $req->get_param('message');
        $is_dm = $req->get_param('is_dm') !== '0'; // Default to true (DM)

        if ( ! Koopo_Stories_Permissions::can_view_story($story_id, $user_id) ) {
            return new WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if ( empty($message) ) {
            return new WP_REST_Response(['error' => 'message_required'], 400);
        }

        $reply_id = Koopo_Stories_Replies::add_reply($story_id, $user_id, $message, $item_id, $is_dm);

        if ( $reply_id > 0 ) {
            // Send BuddyBoss notification if it's a DM
            if ( $is_dm ) {
                self::send_reply_notification($story_id, $user_id, $message);
            }

            return new WP_REST_Response([
                'ok' => true,
                'reply_id' => $reply_id,
                'message' => 'Reply added',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Delete a reply
     */
    public static function delete_reply( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $reply_id = (int) $req['reply_id'];

        $success = Koopo_Stories_Replies::delete_reply($reply_id, $user_id);

        if ( $success ) {
            return new WP_REST_Response([
                'ok' => true,
                'message' => 'Reply deleted',
            ], 200);
        }

        return new WP_REST_Response(['error' => 'failed'], 500);
    }

    /**
     * Send BuddyBoss notification for story reply
     */
    private static function send_reply_notification( int $story_id, int $sender_id, string $message ) {
        // Get story author
        $author_id = (int) get_post_field('post_author', $story_id);

        // Don't notify if replying to own story
        if ( $author_id === $sender_id ) {
            return;
        }

        // BuddyBoss notifications integration
        if ( function_exists('bp_notifications_add_notification') ) {
            $sender = get_user_by('id', $sender_id);
            $sender_name = $sender ? $sender->display_name : 'Someone';

            bp_notifications_add_notification([
                'user_id' => $author_id,
                'item_id' => $story_id,
                'secondary_item_id' => $sender_id,
                'component_name' => 'koopo_stories',
                'component_action' => 'story_reply',
                'date_notified' => current_time('mysql'),
                'is_new' => 1,
            ]);
        }
    }

    /**
     * Get list of viewers for a story
     */
    public static function get_viewers( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];

        // Verify story exists and user can view it
        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $author_id = (int) $story->post_author;

        // Only story author can see viewer list
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        // Get all items for this story
        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
        ]);

        $item_ids = array_map('intval', $items);

        // Get viewer list
        $limit = max(1, min(200, (int) $req->get_param('limit') ?: 100));
        $viewers_data = Koopo_Stories_Views_Table::get_story_viewers($item_ids, $limit);

        $viewers = [];
        foreach ( $viewers_data as $row ) {
            $viewer_id = (int) $row['viewer_user_id'];
            $viewer = get_user_by('id', $viewer_id);

            if ( ! $viewer ) continue;

            $profile_url = '';
            if ( function_exists('bp_core_get_user_domain') ) {
                $profile_url = bp_core_get_user_domain($viewer_id);
            }

            $viewers[] = [
                'user_id' => $viewer_id,
                'name' => $viewer->display_name,
                'avatar' => get_avatar_url($viewer_id, [ 'size' => 64 ]),
                'profile_url' => $profile_url,
                'viewed_at' => mysql_to_rfc3339( get_gmt_from_date($row['last_viewed_at']) ),
            ];
        }

        return new WP_REST_Response([
            'viewers' => $viewers,
            'total_count' => Koopo_Stories_Views_Table::get_story_view_count($item_ids),
        ], 200);
    }

    /**
     * Get analytics for a story
     */
    public static function get_analytics( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $story_id = (int) $req['story_id'];

        // Verify story exists and user can view it
        $story = get_post($story_id);
        if ( ! $story || $story->post_type !== Koopo_Stories_Module::CPT_STORY ) {
            return new WP_REST_Response([ 'error' => 'not_found' ], 404);
        }

        $author_id = (int) $story->post_author;

        // Only story author can see analytics
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return new WP_REST_Response([ 'error' => 'forbidden' ], 403);
        }

        // Get all items for this story
        $items = get_posts([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => 'story_id',
            'meta_value' => $story_id,
        ]);

        $item_ids = array_map('intval', $items);

        // Get analytics data
        $analytics = Koopo_Stories_Views_Table::get_story_analytics($item_ids);

        // Get reaction counts
        $reaction_counts = Koopo_Stories_Reactions::get_reaction_counts($story_id);
        $total_reactions = array_sum($reaction_counts);

        // Get reply count
        $replies = Koopo_Stories_Replies::get_replies($story_id);
        $reply_count = count($replies);

        return new WP_REST_Response([
            'story_id' => $story_id,
            'total_views' => $analytics['total_views'],
            'unique_viewers' => $analytics['unique_viewers'],
            'views_by_item' => $analytics['views_by_item'],
            'reactions' => [
                'total' => $total_reactions,
                'by_type' => $reaction_counts,
            ],
            'replies' => [
                'total' => $reply_count,
            ],
        ], 200);
    }
}