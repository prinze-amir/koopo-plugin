<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_Permissions {

    /**
     * Story privacy values:
     * - public: any logged-in user
     * - friends: connections/friends (and optionally follow)
     */
    public static function can_view_story( int $story_id, int $viewer_id ) : bool {
        if ( $viewer_id <= 0 ) return false;

        $author_id = (int) get_post_field('post_author', $story_id);
        if ( $author_id === $viewer_id ) return true;
        if ( user_can($viewer_id, 'manage_options') ) return true;

        $privacy = get_post_meta($story_id, 'privacy', true);
        if ( empty($privacy) ) $privacy = 'friends';

        if ( $privacy === 'public' ) {
            return true;
        }

        // friends/connections
        $friends = self::friend_ids($viewer_id);
        if ( in_array($author_id, $friends, true) ) {
            return true;
        }

        // follow relationship (BuddyBoss Follow or BuddyPress followers plugin)
        if ( self::is_following($viewer_id, $author_id) ) {
            return true;
        }

        return false;
    }

    public static function friend_ids( int $user_id ) : array {
        if ( $user_id <= 0 ) return [];
        if ( function_exists('friends_get_friend_user_ids') ) {
            $ids = friends_get_friend_user_ids($user_id);
            return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        }
        return [];
    }

    public static function following_ids( int $user_id ) : array {
        if ( $user_id <= 0 ) return [];

        // BuddyBoss Platform (bp_get_following returns array of IDs; bp_get_following_ids returns CSV)
        if ( function_exists('bp_get_following') ) {
            $ids = bp_get_following([ 'user_id' => $user_id ]);
            if ( is_string($ids) ) {
                $ids = array_filter(array_map('intval', explode(',', $ids)));
            }
            return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        }
        if ( function_exists('bp_get_following_ids') ) {
            $csv = bp_get_following_ids([ 'user_id' => $user_id ]);
            $ids = array_filter(array_map('intval', explode(',', (string)$csv)));
            return array_values(array_unique($ids));
        }

        // BuddyPress followers plugin
        if ( function_exists('bp_follow_get_following') ) {
            $ids = bp_follow_get_following([
                'user_id' => $user_id,
                'per_page' => 9999,
            ]);
            return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        }

        return [];
    }

    public static function is_following( int $follower_id, int $leader_id ) : bool {
        if ( $follower_id <= 0 || $leader_id <= 0 ) return false;

        // BuddyBoss Platform
        if ( function_exists('bp_is_following') ) {
            return (bool) bp_is_following($leader_id, $follower_id);
        }

        // BuddyPress followers plugin
        if ( function_exists('bp_follow_is_following') ) {
            return (bool) bp_follow_is_following([
                'leader_id' => $leader_id,
                'follower_id' => $follower_id,
            ]);
        }

        return false;
    }
}
