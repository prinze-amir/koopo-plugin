<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Close Friends Management
 */
class Koopo_Stories_Close_Friends {

    const TABLE_NAME = 'koopo_story_close_friends';

    /**
     * Install the close friends table
     */
    public static function install() : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `friend_id` BIGINT(20) UNSIGNED NOT NULL,
            `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_friend_unique` (`user_id`, `friend_id`),
            KEY `user_id_idx` (`user_id`),
            KEY `friend_id_idx` (`friend_id`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add a user to close friends list
     */
    public static function add_friend( int $user_id, int $friend_id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $user_id === $friend_id ) {
            return false; // Can't add yourself
        }

        $result = $wpdb->replace(
            $table,
            [
                'user_id' => $user_id,
                'friend_id' => $friend_id,
                'added_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s']
        );

        return $result !== false;
    }

    /**
     * Remove a user from close friends list
     */
    public static function remove_friend( int $user_id, int $friend_id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->delete(
            $table,
            [
                'user_id' => $user_id,
                'friend_id' => $friend_id,
            ],
            ['%d', '%d']
        );

        return $result !== false;
    }

    /**
     * Check if a user is in close friends list
     */
    public static function is_close_friend( int $user_id, int $friend_id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND friend_id = %d",
            $user_id,
            $friend_id
        ));

        return $count > 0;
    }

    /**
     * Get all close friends for a user
     */
    public static function get_close_friends( int $user_id ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $friend_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT friend_id FROM `{$table}` WHERE user_id = %d ORDER BY added_at DESC",
            $user_id
        ));

        return array_map('intval', $friend_ids);
    }

    /**
     * Get count of close friends for a user
     */
    public static function get_count( int $user_id ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Check if multiple users are close friends (bulk check)
     */
    public static function are_close_friends( int $user_id, array $friend_ids ) : array {
        if ( empty($friend_ids) ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $placeholders = implode(',', array_fill(0, count($friend_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT friend_id FROM `{$table}` WHERE user_id = %d AND friend_id IN ({$placeholders})",
            array_merge([$user_id], $friend_ids)
        );

        $close_friend_ids = $wpdb->get_col($query);
        return array_map('intval', $close_friend_ids);
    }
}
