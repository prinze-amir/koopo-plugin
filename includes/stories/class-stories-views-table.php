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
}
