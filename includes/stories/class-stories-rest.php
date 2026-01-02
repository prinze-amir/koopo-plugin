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
    }

    public static function must_be_logged_in() : bool {
        return is_user_logged_in();
    }

    public static function get_feed( WP_REST_Request $req ) {
        $user_id = get_current_user_id();

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
            $out[] = [
                'story_id' => $sid,
                'author' => [
                    'id' => $author_id,
                    'name' => get_the_author_meta('display_name', $author_id),
                    'avatar' => get_avatar_url($author_id, [ 'size' => 96 ]),
                ],
                'cover_thumb' => $cover_thumb,
                'last_updated' => get_post_modified_time(DATE_ATOM, true, $sid),
                'has_unseen' => $has_unseen,
                'unseen_count' => $unseen_count,
                'items_count' => $items_count,
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

        return new WP_REST_Response([
            'story_id' => $story_id,
            'author' => [
                'id' => $author_id,
                'name' => $author ? $author->display_name : ('User #' . $author_id),
                'avatar' => get_avatar_url($author_id, [ 'size' => 96 ]),
            ],
            'items' => $items_out,
        ], 200);
    }

    public static function mark_seen( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
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

        // Find existing active story for this user (within 24h)
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

        if ( ! empty($existing) ) {
            $story_id = (int) $existing[0]->ID;
        } else {
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
            $privacy = ($privacy === 'public') ? 'public' : 'friends';
            update_post_meta($story_id, 'privacy', $privacy);
            update_post_meta($story_id, 'expires_at', time() + DAY_IN_SECONDS);
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
}