<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Replies
 * Manages text replies/comments on stories
 */
class Koopo_Stories_Replies {

    const TABLE_NAME = 'koopo_story_replies';

    /**
     * Install the replies table
     */
    public static function install() : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `story_id` BIGINT(20) UNSIGNED NOT NULL,
            `item_id` BIGINT(20) UNSIGNED DEFAULT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `message` TEXT NOT NULL,
            `is_dm` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `story_id_idx` (`story_id`),
            KEY `item_id_idx` (`item_id`),
            KEY `user_id_idx` (`user_id`),
            KEY `created_at_idx` (`created_at`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add a reply
     */
    public static function add_reply( int $story_id, int $user_id, string $message, int $item_id = null, bool $is_dm = true ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Sanitize message
        $message = sanitize_textarea_field($message);
        if ( empty($message) || strlen($message) > 500 ) {
            return 0;
        }

        $result = $wpdb->insert(
            $table,
            [
                'story_id' => $story_id,
                'item_id' => $item_id,
                'user_id' => $user_id,
                'message' => $message,
                'is_dm' => $is_dm ? 1 : 0,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%d', '%s']
        );

        if ( $result === false ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Delete a reply
     */
    public static function delete_reply( int $reply_id, int $user_id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Only allow user to delete their own replies
        $result = $wpdb->delete(
            $table,
            [
                'id' => $reply_id,
                'user_id' => $user_id,
            ],
            ['%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Get replies for a story (DMs only visible to story author)
     */
    public static function get_replies( int $story_id, int $viewer_id, int $item_id = null, int $limit = 50 ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get story author
        $author_id = (int) get_post_field('post_author', $story_id);
        $is_author = ($author_id === $viewer_id);

        if ( $item_id !== null ) {
            if ( $is_author ) {
                // Story author sees all replies (DMs + public)
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE story_id = %d AND item_id = %d ORDER BY created_at DESC LIMIT %d",
                    $story_id,
                    $item_id,
                    $limit
                ), ARRAY_A);
            } else {
                // Others only see public replies + their own DMs
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE story_id = %d AND item_id = %d AND (is_dm = 0 OR user_id = %d) ORDER BY created_at DESC LIMIT %d",
                    $story_id,
                    $item_id,
                    $viewer_id,
                    $limit
                ), ARRAY_A);
            }
        } else {
            if ( $is_author ) {
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE story_id = %d AND item_id IS NULL ORDER BY created_at DESC LIMIT %d",
                    $story_id,
                    $limit
                ), ARRAY_A);
            } else {
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE story_id = %d AND item_id IS NULL AND (is_dm = 0 OR user_id = %d) ORDER BY created_at DESC LIMIT %d",
                    $story_id,
                    $viewer_id,
                    $limit
                ), ARRAY_A);
            }
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Get reply count for a story
     */
    public static function get_reply_count( int $story_id, int $item_id = null ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $item_id !== null ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE story_id = %d AND item_id = %d",
                $story_id,
                $item_id
            ));
        } else {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE story_id = %d AND item_id IS NULL",
                $story_id
            ));
        }

        return $count;
    }

    /**
     * Get unread reply count for story author (DMs only)
     */
    public static function get_unread_count( int $story_id, int $author_id ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $views_table = $wpdb->prefix . 'koopo_story_reply_views';

        // This would require a separate views table for replies
        // For now, return total count - can be enhanced later
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE story_id = %d AND is_dm = 1 AND user_id != %d",
            $story_id,
            $author_id
        ));

        return $count;
    }
}
