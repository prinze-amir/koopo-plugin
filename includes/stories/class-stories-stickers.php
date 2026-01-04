<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Stickers
 * Manages interactive stickers: mentions, links, locations, and polls
 */
class Koopo_Stories_Stickers {

    const TABLE_NAME = 'koopo_story_stickers';

    /**
     * Install the stickers table
     */
    public static function install() : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `story_id` BIGINT(20) UNSIGNED NOT NULL,
            `item_id` BIGINT(20) UNSIGNED NOT NULL,
            `sticker_type` VARCHAR(20) NOT NULL,
            `sticker_data` LONGTEXT NOT NULL,
            `position_x` DECIMAL(5,2) DEFAULT 50.00,
            `position_y` DECIMAL(5,2) DEFAULT 50.00,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `story_id_idx` (`story_id`),
            KEY `item_id_idx` (`item_id`),
            KEY `type_idx` (`sticker_type`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add a sticker to a story item
     */
    public static function add_sticker( int $story_id, int $item_id, string $type, array $data, float $x = 50.0, float $y = 50.0 ) : int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Validate sticker type
        $allowed_types = ['mention', 'link', 'location', 'poll'];
        if ( ! in_array($type, $allowed_types, true) ) {
            return 0;
        }

        // Validate and sanitize data based on type
        $sanitized_data = self::sanitize_sticker_data($type, $data);
        if ( empty($sanitized_data) ) {
            return 0;
        }

        $result = $wpdb->insert(
            $table,
            [
                'story_id' => $story_id,
                'item_id' => $item_id,
                'sticker_type' => $type,
                'sticker_data' => wp_json_encode($sanitized_data),
                'position_x' => max(0, min(100, $x)),
                'position_y' => max(0, min(100, $y)),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%f', '%f', '%s']
        );

        if ( $result === false ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Get all stickers for a story item
     */
    public static function get_stickers( int $item_id ) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE item_id = %d ORDER BY created_at ASC",
            $item_id
        ), ARRAY_A);

        if ( ! is_array($results) ) {
            return [];
        }

        // Decode JSON data for each sticker
        $stickers = [];
        foreach ( $results as $row ) {
            $data = json_decode($row['sticker_data'], true);
            if ( is_array($data) ) {
                $stickers[] = [
                    'id' => (int) $row['id'],
                    'type' => $row['sticker_type'],
                    'data' => $data,
                    'position' => [
                        'x' => (float) $row['position_x'],
                        'y' => (float) $row['position_y'],
                    ],
                    'created_at' => $row['created_at'],
                ];
            }
        }

        return $stickers;
    }

    /**
     * Delete a sticker
     */
    public static function delete_sticker( int $sticker_id, int $user_id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get sticker to verify ownership
        $sticker = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.* FROM `{$table}` s
             INNER JOIN {$wpdb->posts} p ON s.story_id = p.ID
             WHERE s.id = %d",
            $sticker_id
        ), ARRAY_A);

        if ( ! $sticker ) {
            return false;
        }

        // Only story author or admin can delete
        $author_id = (int) get_post_field('post_author', $sticker['story_id']);
        if ( $author_id !== $user_id && ! user_can($user_id, 'manage_options') ) {
            return false;
        }

        $result = $wpdb->delete(
            $table,
            ['id' => $sticker_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Sanitize sticker data based on type
     */
    private static function sanitize_sticker_data( string $type, array $data ) : array {
        switch ( $type ) {
            case 'mention':
                // Mention: { user_id, username, display_name }
                if ( empty($data['user_id']) || ! is_numeric($data['user_id']) ) {
                    return [];
                }
                $user_id = (int) $data['user_id'];
                $user = get_user_by('id', $user_id);
                if ( ! $user ) {
                    return [];
                }
                return [
                    'user_id' => $user_id,
                    'username' => $user->user_login,
                    'display_name' => $user->display_name,
                ];

            case 'link':
                // Link: { url, title }
                if ( empty($data['url']) ) {
                    return [];
                }
                $url = esc_url_raw($data['url']);
                if ( empty($url) ) {
                    return [];
                }
                return [
                    'url' => $url,
                    'title' => isset($data['title']) ? sanitize_text_field($data['title']) : parse_url($url, PHP_URL_HOST),
                ];

            case 'location':
                // Location: { name, lat, lng, address }
                if ( empty($data['name']) ) {
                    return [];
                }
                return [
                    'name' => sanitize_text_field($data['name']),
                    'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                    'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
                    'address' => isset($data['address']) ? sanitize_text_field($data['address']) : '',
                ];

            case 'poll':
                // Poll: { question, options: [text, votes] }
                if ( empty($data['question']) || empty($data['options']) || ! is_array($data['options']) ) {
                    return [];
                }
                $options = [];
                foreach ( $data['options'] as $idx => $opt ) {
                    if ( is_string($opt) ) {
                        $options[] = [
                            'text' => sanitize_text_field($opt),
                            'votes' => 0,
                        ];
                    } elseif ( is_array($opt) && isset($opt['text']) ) {
                        $options[] = [
                            'text' => sanitize_text_field($opt['text']),
                            'votes' => isset($opt['votes']) ? (int) $opt['votes'] : 0,
                        ];
                    }
                }
                if ( count($options) < 2 || count($options) > 4 ) {
                    return [];
                }
                return [
                    'question' => sanitize_text_field($data['question']),
                    'options' => $options,
                ];

            default:
                return [];
        }
    }

    /**
     * Record a poll vote
     */
    public static function vote_poll( int $sticker_id, int $user_id, int $option_index ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $votes_table = $wpdb->prefix . 'koopo_story_poll_votes';

        // Get the sticker
        $sticker = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND sticker_type = 'poll'",
            $sticker_id
        ), ARRAY_A);

        if ( ! $sticker ) {
            return false;
        }

        $data = json_decode($sticker['sticker_data'], true);
        if ( ! is_array($data) || ! isset($data['options']) ) {
            return false;
        }

        // Validate option index
        if ( $option_index < 0 || $option_index >= count($data['options']) ) {
            return false;
        }

        // Check if user already voted
        $existing_vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$votes_table}` WHERE sticker_id = %d AND user_id = %d",
            $sticker_id,
            $user_id
        ));

        if ( $existing_vote ) {
            // Update existing vote
            $wpdb->update(
                $votes_table,
                ['option_index' => $option_index],
                ['id' => $existing_vote],
                ['%d'],
                ['%d']
            );
        } else {
            // Record new vote
            $wpdb->insert(
                $votes_table,
                [
                    'sticker_id' => $sticker_id,
                    'user_id' => $user_id,
                    'option_index' => $option_index,
                    'voted_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%s']
            );
        }

        // Update vote counts in sticker data
        $vote_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_index, COUNT(*) as count FROM `{$votes_table}` WHERE sticker_id = %d GROUP BY option_index",
            $sticker_id
        ), ARRAY_A);

        // Reset all vote counts
        foreach ( $data['options'] as $idx => $opt ) {
            $data['options'][$idx]['votes'] = 0;
        }

        // Update with actual counts
        foreach ( $vote_counts as $count_row ) {
            $idx = (int) $count_row['option_index'];
            if ( isset($data['options'][$idx]) ) {
                $data['options'][$idx]['votes'] = (int) $count_row['count'];
            }
        }

        // Save updated data
        $wpdb->update(
            $table,
            ['sticker_data' => wp_json_encode($data)],
            ['id' => $sticker_id],
            ['%s'],
            ['%d']
        );

        return true;
    }

    /**
     * Install poll votes table
     */
    public static function install_poll_votes_table() : void {
        global $wpdb;
        $table = $wpdb->prefix . 'koopo_story_poll_votes';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `sticker_id` BIGINT(20) UNSIGNED NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL,
            `option_index` INT NOT NULL,
            `voted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_sticker_vote` (`sticker_id`, `user_id`),
            KEY `user_id_idx` (`user_id`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get user's vote for a poll
     */
    public static function get_user_vote( int $sticker_id, int $user_id ) : ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'koopo_story_poll_votes';

        $vote = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_index FROM `{$table}` WHERE sticker_id = %d AND user_id = %d",
            $sticker_id,
            $user_id
        ));

        return $vote !== null ? (int) $vote : null;
    }
}
