<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_Views_Table {

    public static function table_name() : string {
        global $wpdb;
        return $wpdb->prefix . Koopo_Stories_Module::VIEWS_TABLE;
    }

    public static function install() : void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            story_item_id BIGINT UNSIGNED NOT NULL,
            viewer_user_id BIGINT UNSIGNED NOT NULL,
            viewed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_view (story_item_id, viewer_user_id),
            KEY idx_viewer (viewer_user_id),
            KEY idx_item (story_item_id)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function mark_seen( int $item_id, int $viewer_id ) : void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->replace(
            $table,
            [
                'story_item_id' => $item_id,
                'viewer_user_id' => $viewer_id,
                'viewed_at' => current_time('mysql'),
            ],
            [ '%d', '%d', '%s' ]
        );
    }

    public static function has_seen_any( array $item_ids, int $viewer_id ) : array {
        // Returns associative array item_id => true
        global $wpdb;
        $table = self::table_name();
        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));
        if ( empty($item_ids) ) return [];
        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT story_item_id FROM {$table} WHERE viewer_user_id = %d AND story_item_id IN ({$placeholders})",
            array_merge([ $viewer_id ], $item_ids)
        );
        $rows = $wpdb->get_col($sql);
        $out = [];
        foreach ($rows as $id) $out[intval($id)] = true;
        return $out;
    }

    /**
     * Get total view count for a story item
     */
    public static function get_view_count( int $item_id ) : int {
        global $wpdb;
        $table = self::table_name();

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT viewer_user_id) FROM {$table} WHERE story_item_id = %d",
            $item_id
        ));

        return $count;
    }

    /**
     * Get total view count for all items in a story
     */
    public static function get_story_view_count( array $item_ids ) : int {
        if ( empty($item_ids) ) return 0;

        global $wpdb;
        $table = self::table_name();
        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));
        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT viewer_user_id) FROM {$table} WHERE story_item_id IN ({$placeholders})",
            $item_ids
        ));

        return $count;
    }

    /**
     * Get list of viewers for a story item
     */
    public static function get_viewers( int $item_id, int $limit = 100 ) : array {
        global $wpdb;
        $table = self::table_name();

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT viewer_user_id, viewed_at FROM {$table} WHERE story_item_id = %d ORDER BY viewed_at DESC LIMIT %d",
            $item_id,
            $limit
        ), ARRAY_A);

        return is_array($results) ? $results : [];
    }

    /**
     * Get list of all viewers for a story (across all items)
     */
    public static function get_story_viewers( array $item_ids, int $limit = 100 ) : array {
        if ( empty($item_ids) ) return [];

        global $wpdb;
        $table = self::table_name();
        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));
        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT viewer_user_id, MAX(viewed_at) as last_viewed_at
             FROM {$table}
             WHERE story_item_id IN ({$placeholders})
             GROUP BY viewer_user_id
             ORDER BY last_viewed_at DESC
             LIMIT %d",
            array_merge($item_ids, [$limit])
        ), ARRAY_A);

        return is_array($results) ? $results : [];
    }

    /**
     * Get view analytics for a story
     */
    public static function get_story_analytics( array $item_ids ) : array {
        if ( empty($item_ids) ) {
            return [
                'total_views' => 0,
                'unique_viewers' => 0,
                'views_by_item' => [],
            ];
        }

        global $wpdb;
        $table = self::table_name();
        $item_ids = array_values(array_filter(array_map('intval', $item_ids)));
        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));

        // Get total views and unique viewers
        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_views,
                COUNT(DISTINCT viewer_user_id) as unique_viewers
             FROM {$table}
             WHERE story_item_id IN ({$placeholders})",
            $item_ids
        ), ARRAY_A);

        // Get views per item
        $views_by_item = $wpdb->get_results( $wpdb->prepare(
            "SELECT story_item_id, COUNT(DISTINCT viewer_user_id) as view_count
             FROM {$table}
             WHERE story_item_id IN ({$placeholders})
             GROUP BY story_item_id",
            $item_ids
        ), ARRAY_A);

        $views_map = [];
        if ( is_array($views_by_item) ) {
            foreach ( $views_by_item as $row ) {
                $views_map[(int)$row['story_item_id']] = (int)$row['view_count'];
            }
        }

        return [
            'total_views' => (int)($stats['total_views'] ?? 0),
            'unique_viewers' => (int)($stats['unique_viewers'] ?? 0),
            'views_by_item' => $views_map,
        ];
    }
}
