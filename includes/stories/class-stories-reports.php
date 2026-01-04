<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Reports
 * Manages user-reported stories for moderation
 */
class Koopo_Stories_Reports {

    const TABLE_NAME = 'koopo_story_reports';

    /**
     * Install the reports table
     */
    public static function install() : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `story_id` BIGINT(20) UNSIGNED NOT NULL,
            `reporter_user_id` BIGINT(20) UNSIGNED NOT NULL,
            `reason` VARCHAR(50) NOT NULL,
            `description` TEXT,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `reviewed_by` BIGINT(20) UNSIGNED DEFAULT NULL,
            `reviewed_at` datetime DEFAULT NULL,
            `action_taken` VARCHAR(50) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_story_report` (`reporter_user_id`, `story_id`),
            KEY `story_id_idx` (`story_id`),
            KEY `status_idx` (`status`),
            KEY `created_at_idx` (`created_at`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Submit a report for a story
     */
    public static function submit_report( int $story_id, int $reporter_id, string $reason, string $description = '' ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Validate reason
        $allowed_reasons = ['spam', 'inappropriate', 'harassment', 'violence', 'hate_speech', 'false_info', 'other'];
        if ( ! in_array($reason, $allowed_reasons, true) ) {
            return false;
        }

        // Check if user already reported this story
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE story_id = %d AND reporter_user_id = %d",
            $story_id,
            $reporter_id
        ));

        if ( $existing ) {
            // Update existing report
            $result = $wpdb->update(
                $table,
                [
                    'reason' => $reason,
                    'description' => sanitize_textarea_field($description),
                    'created_at' => current_time('mysql'),
                ],
                [
                    'id' => $existing,
                ],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Create new report
            $result = $wpdb->insert(
                $table,
                [
                    'story_id' => $story_id,
                    'reporter_user_id' => $reporter_id,
                    'reason' => $reason,
                    'description' => sanitize_textarea_field($description),
                    'status' => 'pending',
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s']
            );
        }

        // Check if auto-hide threshold reached
        if ( $result !== false ) {
            self::check_auto_hide_threshold($story_id);
        }

        return $result !== false;
    }

    /**
     * Get report count for a story
     */
    public static function get_report_count( int $story_id, string $status = 'pending' ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE story_id = %d AND status = %s",
            $story_id,
            $status
        ));

        return $count;
    }

    /**
     * Get all reports for a story
     */
    public static function get_story_reports( int $story_id, string $status = null ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( $status !== null ) {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE story_id = %d AND status = %s ORDER BY created_at DESC",
                $story_id,
                $status
            ), ARRAY_A);
        } else {
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE story_id = %d ORDER BY created_at DESC",
                $story_id
            ), ARRAY_A);
        }

        return is_array($results) ? $results : [];
    }

    /**
     * Get all pending reports (for admin dashboard)
     */
    public static function get_pending_reports( int $limit = 50, int $offset = 0 ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, COUNT(*) as report_count
             FROM `{$table}` r
             WHERE r.status = 'pending'
             GROUP BY r.story_id
             ORDER BY report_count DESC, r.created_at DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        return is_array($results) ? $results : [];
    }

    /**
     * Update report status
     */
    public static function update_report_status( int $report_id, string $status, int $reviewer_id, string $action_taken = null ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $allowed_statuses = ['pending', 'reviewed', 'dismissed', 'actioned'];
        if ( ! in_array($status, $allowed_statuses, true) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            [
                'status' => $status,
                'reviewed_by' => $reviewer_id,
                'reviewed_at' => current_time('mysql'),
                'action_taken' => $action_taken,
            ],
            ['id' => $report_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Update all reports for a story
     */
    public static function update_story_reports( int $story_id, string $status, int $reviewer_id, string $action_taken = null ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET status = %s, reviewed_by = %d, reviewed_at = %s, action_taken = %s
             WHERE story_id = %d AND status = 'pending'",
            $status,
            $reviewer_id,
            current_time('mysql'),
            $action_taken,
            $story_id
        ));

        return $result !== false;
    }

    /**
     * Check if auto-hide threshold is reached and hide story if needed
     */
    public static function check_auto_hide_threshold( int $story_id ) : bool {
        $threshold = (int) get_option('koopo_stories_auto_hide_threshold', 5);

        // 0 means disabled
        if ( $threshold <= 0 ) {
            return false;
        }

        $report_count = self::get_report_count($story_id, 'pending');

        if ( $report_count >= $threshold ) {
            // Auto-hide the story
            $result = wp_update_post([
                'ID' => $story_id,
                'post_status' => 'pending',
            ], true);

            if ( ! is_wp_error($result) ) {
                // Mark all reports as actioned
                self::update_story_reports($story_id, 'actioned', 0, 'auto_hidden');

                // Add admin notice meta
                update_post_meta($story_id, '_auto_hidden', 1);
                update_post_meta($story_id, '_auto_hidden_at', current_time('mysql'));
                update_post_meta($story_id, '_report_count_at_hide', $report_count);

                return true;
            }
        }

        return false;
    }

    /**
     * Get moderation statistics
     */
    public static function get_stats() : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_reports,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_count,
                SUM(CASE WHEN status = 'actioned' THEN 1 ELSE 0 END) as actioned_count,
                COUNT(DISTINCT story_id) as unique_stories_reported
             FROM `{$table}`",
            ARRAY_A
        );

        if ( ! is_array($stats) ) {
            return [
                'total_reports' => 0,
                'pending_count' => 0,
                'reviewed_count' => 0,
                'actioned_count' => 0,
                'unique_stories_reported' => 0,
            ];
        }

        return [
            'total_reports' => (int) $stats['total_reports'],
            'pending_count' => (int) $stats['pending_count'],
            'reviewed_count' => (int) $stats['reviewed_count'],
            'actioned_count' => (int) $stats['actioned_count'],
            'unique_stories_reported' => (int) $stats['unique_stories_reported'],
        ];
    }
}
