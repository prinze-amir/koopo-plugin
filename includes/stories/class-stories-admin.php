<?php
if ( ! defined('ABSPATH') ) exit;

final class Koopo_Stories_Admin {

    const MENU_SLUG = 'koopo-stories';
    const SETTINGS_SLUG = 'koopo-stories-settings';
    const SETTINGS_GROUP = 'koopo_stories_settings_group';

    public static function init() : void {
        add_action('admin_menu', [ __CLASS__, 'register_menu' ], 30);
        add_action('admin_init', [ __CLASS__, 'register_settings' ]);
    }

    public static function register_menu() : void {
        // Attach to the existing CPT menu for koopo_story.
        // Parent slug for CPT menus is: edit.php?post_type={post_type}
        $parent_slug = 'edit.php?post_type=koopo_story';

        add_submenu_page(
            $parent_slug,
            __('Stories Dashboard', 'koopo'),
            __('Dashboard', 'koopo'),
            'manage_options',
            'koopo-stories-dashboard',
            [ __CLASS__, 'render_dashboard' ]
        );

        add_submenu_page(
            $parent_slug,
            __('Stories Settings', 'koopo'),
            __('Settings', 'koopo'),
            'manage_options',
            self::SETTINGS_SLUG,
            [ __CLASS__, 'render_settings' ]
        );

        add_submenu_page(
            $parent_slug,
            __('Moderation Queue', 'koopo'),
            __('Moderation', 'koopo'),
            'manage_options',
            'koopo-stories-moderation',
            [ __CLASS__, 'render_moderation' ]
        );
    }

    public static function register_settings() : void {
        // Core
        register_setting(self::SETTINGS_GROUP, Koopo_Stories_Module::OPTION_ENABLE, [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ($v === '1') ? '1' : '0'; },
            'default' => '1',
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_privacy', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return in_array($v, ['public','connections'], true) ? $v : 'connections'; },
            'default' => 'connections',
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_duration_hours', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ $v = intval($v); return max(1, min(168, $v)); },
            'default' => 24,
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_max_uploads_per_day', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ $v = intval($v); return max(0, min(500, $v)); },
            'default' => 20,
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_max_items_per_story', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ $v = intval($v); return max(1, min(50, $v)); },
            'default' => 10,
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_max_upload_size_mb', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ $v = intval($v); return max(1, min(1024, $v)); },
            'default' => 50,
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_allowed_image_mimes', [
            'type' => 'array',
            'sanitize_callback' => function($v){
                $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                if (!is_array($v)) return ['image/jpeg','image/png','image/webp'];
                $out = array_values(array_intersect($allowed, $v));
                return !empty($out) ? $out : ['image/jpeg','image/png','image/webp'];
            },
            'default' => ['image/jpeg','image/png','image/webp'],
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_allowed_video_mimes', [
            'type' => 'array',
            'sanitize_callback' => function($v){
                $allowed = ['video/mp4','video/webm','video/quicktime'];
                if (!is_array($v)) return ['video/mp4','video/webm'];
                $out = array_values(array_intersect($allowed, $v));
                return !empty($out) ? $out : ['video/mp4','video/webm'];
            },
            'default' => ['video/mp4','video/webm'],
        ]);

        // Defaults for widgets/shortcode/activity tray
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_scope', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return in_array($v, ['friends','following','all'], true) ? $v : 'friends'; },
            'default' => 'friends',
        ]);
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_order', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return in_array($v, ['unseen_first','recent_activity'], true) ? $v : 'unseen_first'; },
            'default' => 'unseen_first',
        ]);
        // Moderation
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_auto_hide_threshold', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ $v = intval($v); return max(0, min(100, $v)); },
            'default' => 5,
        ]);

        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_layout', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return in_array($v, ['horizontal','vertical'], true) ? $v : 'horizontal'; },
            'default' => 'horizontal',
        ]);
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_limit', [
            'type' => 'integer',
            'sanitize_callback' => function($v){ $v=intval($v); return max(1, min(50, $v)); },
            'default' => 10,
        ]);
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_exclude_me', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ($v === '1') ? '1' : '0'; },
            'default' => '0',
        ]);
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_show_uploader', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ($v === '1') ? '1' : '0'; },
            'default' => '1',
        ]);
        register_setting(self::SETTINGS_GROUP, 'koopo_stories_default_show_unseen_badge', [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ($v === '1') ? '1' : '0'; },
            'default' => '1',
        ]);

        add_settings_section('koopo_stories_core', __('Core', 'koopo'), function(){
            echo '<p>' . esc_html__('Configure Stories behavior and limits.', 'koopo') . '</p>';
        }, self::SETTINGS_SLUG);

        add_settings_field('koopo_enable_stories', __('Enable Stories', 'koopo'), [ __CLASS__, 'field_enable' ], self::SETTINGS_SLUG, 'koopo_stories_core');
        add_settings_field('koopo_stories_default_privacy', __('Default Privacy', 'koopo'), [ __CLASS__, 'field_privacy' ], self::SETTINGS_SLUG, 'koopo_stories_core');
        add_settings_field('koopo_stories_duration_hours', __('Story Duration (hours)', 'koopo'), [ __CLASS__, 'field_duration' ], self::SETTINGS_SLUG, 'koopo_stories_core');
        add_settings_field('koopo_stories_max_uploads_per_day', __('Max Uploads Per Day (per user)', 'koopo'), [ __CLASS__, 'field_max_uploads' ], self::SETTINGS_SLUG, 'koopo_stories_core');
        add_settings_field('koopo_stories_max_items_per_story', __('Max Items Per Story', 'koopo'), [ __CLASS__, 'field_max_items' ], self::SETTINGS_SLUG, 'koopo_stories_core');
        add_settings_field('koopo_stories_max_upload_size_mb', __('Max Upload Size (MB)', 'koopo'), [ __CLASS__, 'field_max_size' ], self::SETTINGS_SLUG, 'koopo_stories_core');
        add_settings_field('koopo_stories_allowed_mimes', __('Allowed File Types', 'koopo'), [ __CLASS__, 'field_mimes' ], self::SETTINGS_SLUG, 'koopo_stories_core');

        add_settings_section('koopo_stories_defaults', __('Defaults (Widget / Shortcode / Tray)', 'koopo'), function(){
            echo '<p>' . esc_html__('These defaults apply when a widget/shortcode does not specify a value.', 'koopo') . '</p>';
        }, self::SETTINGS_SLUG);

        add_settings_field('koopo_stories_default_scope', __('Default Scope', 'koopo'), [ __CLASS__, 'field_scope' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
        add_settings_field('koopo_stories_default_order', __('Default Ordering', 'koopo'), [ __CLASS__, 'field_order' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
        add_settings_field('koopo_stories_default_layout', __('Default Layout', 'koopo'), [ __CLASS__, 'field_layout' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
        add_settings_field('koopo_stories_default_limit', __('Default Limit', 'koopo'), [ __CLASS__, 'field_limit' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
        add_settings_field('koopo_stories_default_exclude_me', __('Exclude My Stories by Default', 'koopo'), [ __CLASS__, 'field_exclude_me' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
        add_settings_field('koopo_stories_default_show_uploader', __('Show Uploader Bubble', 'koopo'), [ __CLASS__, 'field_show_uploader' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
        add_settings_field('koopo_stories_default_show_unseen_badge', __('Show Unseen Badge', 'koopo'), [ __CLASS__, 'field_show_unseen_badge' ], self::SETTINGS_SLUG, 'koopo_stories_defaults');
    }

    public static function render_dashboard() : void {
        if ( ! current_user_can('manage_options') ) return;

        $enabled = get_option(Koopo_Stories_Module::OPTION_ENABLE, '0') === '1';

        $active = self::count_active_stories();
        $items = self::count_active_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Stories', 'koopo') . '</h1>';
        echo '<p>' . ($enabled ? '<span style="color:green;font-weight:600;">Enabled</span>' : '<span style="color:#b32d2e;font-weight:600;">Disabled</span>') . '</p>';

        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin:16px 0;">';
        echo '<div style="padding:16px;background:#fff;border:1px solid #ddd;border-radius:8px;min-width:220px;"><strong>' . esc_html__('Active Stories (last 24h)', 'koopo') . ':</strong><div style="font-size:28px;margin-top:6px;">' . esc_html($active) . '</div></div>';
        echo '<div style="padding:16px;background:#fff;border:1px solid #ddd;border-radius:8px;min-width:220px;"><strong>' . esc_html__('Active Story Items', 'koopo') . ':</strong><div style="font-size:28px;margin-top:6px;">' . esc_html($items) . '</div></div>';
        echo '</div>';

        echo '<p><a class="button button-primary" href="' . esc_url( admin_url('admin.php?page=' . self::SETTINGS_SLUG) ) . '">' . esc_html__('Open Settings', 'koopo') . '</a></p>';

        echo '<h2>' . esc_html__('Shortcode Examples', 'koopo') . '</h2>';
        echo '<code>[koopo_stories_widget title="Friends Stories" limit="12" scope="friends" order="unseen_first" layout="horizontal" exclude_me="1" show_uploader="1" show_unseen_badge="1"]</code><br/><br/>';
        echo '<code>[koopo_stories_widget title="Following" limit="12" scope="following" order="recent_activity" layout="vertical" exclude_me="0"]</code>';

        echo '</div>';
    }

    public static function render_settings() : void {
        if ( ! current_user_can('manage_options') ) return;
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Stories Settings', 'koopo') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections(self::SETTINGS_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function render_moderation() : void {
        if ( ! current_user_can('manage_options') ) return;

        // Handle actions
        if ( isset($_POST['action']) && isset($_POST['report_id']) && check_admin_referer('koopo_moderation_action') ) {
            $report_id = intval($_POST['report_id']);
            $action = sanitize_text_field($_POST['action']);
            $user_id = get_current_user_id();

            if ( $action === 'dismiss' ) {
                Koopo_Stories_Reports::update_report_status($report_id, 'dismissed', $user_id, 'dismissed_by_admin');
                echo '<div class="notice notice-success"><p>Report dismissed.</p></div>';
            } elseif ( $action === 'delete_story' ) {
                $report = Koopo_Stories_Reports::get_story_reports( intval($_POST['story_id']) );
                if ( !empty($report) ) {
                    wp_trash_post( intval($_POST['story_id']) );
                    Koopo_Stories_Reports::update_story_reports( intval($_POST['story_id']), 'actioned', $user_id, 'story_deleted' );
                    echo '<div class="notice notice-success"><p>Story deleted and all reports marked as actioned.</p></div>';
                }
            }
        }

        $stats = Koopo_Stories_Reports::get_stats();
        $reports = Koopo_Stories_Reports::get_pending_reports(50);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Story Moderation Queue', 'koopo') . '</h1>';

        // Stats
        echo '<div class="koopo-moderation-stats" style="display:flex;gap:20px;margin:20px 0;">';
        echo '<div class="stat-box" style="background:#fff;padding:15px 20px;border-left:4px solid #d63638;box-shadow:0 1px 1px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin:0;font-size:32px;color:#d63638;">' . esc_html($stats['pending_count']) . '</h3>';
        echo '<p style="margin:5px 0 0;color:#646970;">Pending Reports</p>';
        echo '</div>';
        echo '<div class="stat-box" style="background:#fff;padding:15px 20px;border-left:4px solid#00a32a;box-shadow:0 1px 1px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin:0;font-size:32px;color:#00a32a;">' . esc_html($stats['reviewed_count']) . '</h3>';
        echo '<p style="margin:5px 0 0;color:#646970;">Reviewed</p>';
        echo '</div>';
        echo '<div class="stat-box" style="background:#fff;padding:15px 20px;border-left:4px solid #2271b1;box-shadow:0 1px 1px rgba(0,0,0,0.04);">';
        echo '<h3 style="margin:0;font-size:32px;color:#2271b1;">' . esc_html($stats['unique_stories_reported']) . '</h3>';
        echo '<p style="margin:5px 0 0;color:#646970;">Stories Reported</p>';
        echo '</div>';
        echo '</div>';

        // Reports table
        if ( empty($reports) ) {
            echo '<div class="notice notice-info"><p>No pending reports. Great job keeping the community safe!</p></div>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Story</th>';
            echo '<th>Author</th>';
            echo '<th>Reports</th>';
            echo '<th>Latest Reason</th>';
            echo '<th>Latest Reporter</th>';
            echo '<th>Date</th>';
            echo '<th>Actions</th>';
            echo '</tr></thead><tbody>';

            foreach ( $reports as $report ) {
                $story_id = intval($report['story_id']);
                $story = get_post($story_id);
                if ( ! $story ) continue;

                $author = get_user_by('id', intval($story->post_author));
                $reporter = get_user_by('id', intval($report['reporter_user_id']));
                $report_count = intval($report['report_count'] ?? 1);

                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link($story_id)) . '">Story #' . $story_id . '</a></td>';
                echo '<td>' . esc_html($author ? $author->display_name : 'Unknown') . '</td>';
                echo '<td><strong style="color:#d63638;">' . $report_count . '</strong></td>';
                echo '<td>' . esc_html(ucfirst(str_replace('_', ' ', $report['reason']))) . '</td>';
                echo '<td>' . esc_html($reporter ? $reporter->display_name : 'Unknown') . '</td>';
                echo '<td>' . esc_html(human_time_diff(strtotime($report['created_at']), current_time('timestamp'))) . ' ago</td>';
                echo '<td>';
                echo '<form method="post" style="display:inline-block;margin-right:5px;">';
                wp_nonce_field('koopo_moderation_action');
                echo '<input type="hidden" name="report_id" value="' . intval($report['id']) . '">';
                echo '<input type="hidden" name="story_id" value="' . $story_id . '">';
                echo '<button type="submit" name="action" value="dismiss" class="button button-small">Dismiss</button>';
                echo '</form>';
                echo '<form method="post" style="display:inline-block;">';
                wp_nonce_field('koopo_moderation_action');
                echo '<input type="hidden" name="report_id" value="' . intval($report['id']) . '">';
                echo '<input type="hidden" name="story_id" value="' . $story_id . '">';
                echo '<button type="submit" name="action" value="delete_story" class="button button-small button-link-delete" onclick="return confirm(\'Are you sure you want to delete this story?\');">Delete Story</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // Settings
        echo '<hr style="margin:40px 0 20px;">';
        echo '<h2>Moderation Settings</h2>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_GROUP);
        echo '<table class="form-table">';
        echo '<tr><th scope="row">Auto-Hide Threshold</th><td>';
        $threshold = intval(get_option('koopo_stories_auto_hide_threshold', 5));
        echo '<input type="number" name="koopo_stories_auto_hide_threshold" value="' . $threshold . '" min="0" max="100" class="small-text">';
        echo '<p class="description">Number of reports before a story is automatically hidden (0 = disabled)</p>';
        echo '</td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '</div>';
    }

    private static function count_active_stories() : int {
        $hours = intval(get_option('koopo_stories_duration_hours', 24));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
        $q = new WP_Query([
            'post_type' => Koopo_Stories_Module::CPT_STORY,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'date_query' => [[ 'after' => $cutoff, 'inclusive' => true ]],
        ]);
        return intval($q->found_posts);
    }

    private static function count_active_items() : int {
        $hours = intval(get_option('koopo_stories_duration_hours', 24));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));
        $q = new WP_Query([
            'post_type' => Koopo_Stories_Module::CPT_ITEM,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'date_query' => [[ 'after' => $cutoff, 'inclusive' => true ]],
        ]);
        return intval($q->found_posts);
    }

    // Field renderers
    public static function field_enable() : void {
        $v = get_option(Koopo_Stories_Module::OPTION_ENABLE, '0');
        printf('<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
            esc_attr(Koopo_Stories_Module::OPTION_ENABLE),
            checked('1', $v, false),
            esc_html__('Enable Stories across the site.', 'koopo')
        );
    }

    public static function field_privacy() : void {
        $v = get_option('koopo_stories_default_privacy', 'connections');
        echo '<select name="koopo_stories_default_privacy">';
        echo '<option value="connections"' . selected($v, 'connections', false) . '>' . esc_html__('Connections only', 'koopo') . '</option>';
        echo '<option value="public"' . selected($v, 'public', false) . '>' . esc_html__('All members', 'koopo') . '</option>';
        echo '</select>';
    }

    public static function field_duration() : void {
        $v = intval(get_option('koopo_stories_duration_hours', 24));
        printf('<input type="number" min="1" max="168" name="koopo_stories_duration_hours" value="%d" />', esc_attr($v));
    }

    public static function field_max_uploads() : void {
        $v = intval(get_option('koopo_stories_max_uploads_per_day', 20));
        printf('<input type="number" min="0" max="500" name="koopo_stories_max_uploads_per_day" value="%d" />', esc_attr($v));
        echo '<p class="description">' . esc_html__('Set to 0 for unlimited (not recommended).', 'koopo') . '</p>';
    }

    public static function field_max_items() : void {
        $v = intval(get_option('koopo_stories_max_items_per_story', 10));
        printf('<input type="number" min="1" max="50" name="koopo_stories_max_items_per_story" value="%d" />', esc_attr($v));
    }

    public static function field_max_size() : void {
        $v = intval(get_option('koopo_stories_max_upload_size_mb', 50));
        printf('<input type="number" min="1" max="1024" name="koopo_stories_max_upload_size_mb" value="%d" />', esc_attr($v));
    }

    public static function field_mimes() : void {
        $img = (array) get_option('koopo_stories_allowed_image_mimes', ['image/jpeg','image/png','image/webp']);
        $vid = (array) get_option('koopo_stories_allowed_video_mimes', ['video/mp4','video/webm']);

        $img_opts = [
            'image/jpeg' => 'JPG / JPEG',
            'image/png'  => 'PNG',
            'image/webp' => 'WebP',
            'image/gif'  => 'GIF',
        ];
        $vid_opts = [
            'video/mp4' => 'MP4',
            'video/webm' => 'WebM',
            'video/quicktime' => 'MOV (QuickTime)',
        ];

        echo '<strong>' . esc_html__('Images', 'koopo') . '</strong><br/>';
        foreach ($img_opts as $k => $label) {
            printf('<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="koopo_stories_allowed_image_mimes[]" value="%s" %s /> %s</label>',
                esc_attr($k),
                checked(true, in_array($k, $img, true), false),
                esc_html($label)
            );
        }

        echo '<br/><br/><strong>' . esc_html__('Videos', 'koopo') . '</strong><br/>';
        foreach ($vid_opts as $k => $label) {
            printf('<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="koopo_stories_allowed_video_mimes[]" value="%s" %s /> %s</label>',
                esc_attr($k),
                checked(true, in_array($k, $vid, true), false),
                esc_html($label)
            );
        }
    }

    public static function field_scope() : void {
        $v = get_option('koopo_stories_default_scope', 'friends');
        echo '<select name="koopo_stories_default_scope">';
        echo '<option value="friends"' . selected($v, 'friends', false) . '>' . esc_html__('Connections (Friends)', 'koopo') . '</option>';
        echo '<option value="following"' . selected($v, 'following', false) . '>' . esc_html__('Following', 'koopo') . '</option>';
        echo '<option value="all"' . selected($v, 'all', false) . '>' . esc_html__('All Members', 'koopo') . '</option>';
        echo '</select>';
    }

    public static function field_order() : void {
        $v = get_option('koopo_stories_default_order', 'unseen_first');
        echo '<select name="koopo_stories_default_order">';
        echo '<option value="unseen_first"' . selected($v, 'unseen_first', false) . '>' . esc_html__('Unseen first', 'koopo') . '</option>';
        echo '<option value="recent_activity"' . selected($v, 'recent_activity', false) . '>' . esc_html__('Recent activity', 'koopo') . '</option>';
        echo '</select>';
    }

    public static function field_layout() : void {
        $v = get_option('koopo_stories_default_layout', 'horizontal');
        echo '<select name="koopo_stories_default_layout">';
        echo '<option value="horizontal"' . selected($v, 'horizontal', false) . '>' . esc_html__('Horizontal tray', 'koopo') . '</option>';
        echo '<option value="vertical"' . selected($v, 'vertical', false) . '>' . esc_html__('Vertical list', 'koopo') . '</option>';
        echo '</select>';
    }

    public static function field_limit() : void {
        $v = intval(get_option('koopo_stories_default_limit', 10));
        printf('<input type="number" min="1" max="50" name="koopo_stories_default_limit" value="%d" />', esc_attr($v));
    }

    public static function field_exclude_me() : void {
        $v = get_option('koopo_stories_default_exclude_me', '0');
        printf('<label><input type="checkbox" name="koopo_stories_default_exclude_me" value="1" %s /> %s</label>',
            checked('1', $v, false),
            esc_html__('Exclude the current user by default in widgets/shortcodes.', 'koopo')
        );
    }

    public static function field_show_uploader() : void {
        $v = get_option('koopo_stories_default_show_uploader', '1');
        printf('<label><input type="checkbox" name="koopo_stories_default_show_uploader" value="1" %s /> %s</label>',
            checked('1', $v, false),
            esc_html__('Show the "Your Story" uploader bubble.', 'koopo')
        );
    }

    public static function field_show_unseen_badge() : void {
        $v = get_option('koopo_stories_default_show_unseen_badge', '1');
        printf('<label><input type="checkbox" name="koopo_stories_default_show_unseen_badge" value="1" %s /> %s</label>',
            checked('1', $v, false),
            esc_html__('Show an unseen count badge on story bubbles.', 'koopo')
        );
    }
}
