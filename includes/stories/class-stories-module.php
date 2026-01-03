<?php
/**
 * Koopo Stories Module (Bootstrap)
 */
if ( ! defined('ABSPATH') ) exit;

final class Koopo_Stories_Module {

    const OPTION_ENABLE = 'koopo_enable_stories';
    const CPT_STORY = 'koopo_story';
    const CPT_ITEM  = 'koopo_story_item';
    const VIEWS_TABLE = 'koopo_story_views';
    const REST_NS = 'koopo/v1';

    private static $instance = null;
    private $assets_enqueued = false;

    public static function instance() : self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){}

    public function init() : void {
        $enabled = (get_option(self::OPTION_ENABLE, '1') === '1');
        require_once KOOPO_PATH . 'includes/stories/class-stories-cpt.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-views-table.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-close-friends.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-close-friends-ui.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-reactions.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-replies.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-permissions.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-rest.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-cleanup.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-widget.php';
        require_once KOOPO_PATH . 'includes/stories/class-stories-admin.php';

        // Admin UI (menu + settings)
        if ( is_admin() ) {
            Koopo_Stories_Admin::init();
        }

        // CPTs + meta
        add_action('init', [ 'Koopo_Stories_CPT', 'register' ]);

        // REST (only when enabled)
        if ( $enabled ) {
            add_action('rest_api_init', [ 'Koopo_Stories_REST', 'register_routes' ]);
        }

        // Views table installer
        register_activation_hook( KOOPO_PATH . 'koopo.php', [ 'Koopo_Stories_Views_Table', 'install' ] );

        // Close friends table installer
        register_activation_hook( KOOPO_PATH . 'koopo.php', [ 'Koopo_Stories_Close_Friends', 'install' ] );

        // Reactions table installer
        register_activation_hook( KOOPO_PATH . 'koopo.php', [ 'Koopo_Stories_Reactions', 'install' ] );

        // Replies table installer
        register_activation_hook( KOOPO_PATH . 'koopo.php', [ 'Koopo_Stories_Replies', 'install' ] );

        // Cron cleanup (only when enabled)
        if ( $enabled ) {
            add_action('koopo_stories_cleanup', [ 'Koopo_Stories_Cleanup', 'run' ]);
            add_action('wp', [ $this, 'maybe_schedule_cleanup' ]);
        }

        // BuddyBoss activity tray (only when enabled)
        if ( $enabled ) {
            add_action('bp_after_activity_post_form', [ $this, 'render_activity_tray' ]);
        }

        // Widget + shortcode
        add_action('widgets_init', [ 'Koopo_Stories_Widget', 'register' ]);
        add_shortcode('koopo_stories_widget', [ $this, 'shortcode_widget' ]);

        // Close Friends UI
        Koopo_Stories_Close_Friends_UI::init();

        // Assets registration
        add_action('wp_enqueue_scripts', [ $this, 'register_assets' ]);
    }

    public function maybe_schedule_cleanup() : void {
        if ( ! wp_next_scheduled('koopo_stories_cleanup') ) {
            wp_schedule_event( time() + 300, 'hourly', 'koopo_stories_cleanup' );
        }
    }

    public function register_assets() : void {
        $ver = defined('KOOPO_STORIES_VER') ? KOOPO_STORIES_VER : '1.0.0';
        wp_register_style(
            'koopo-stories',
            plugins_url('assets/stories/stories.css', KOOPO_PATH . 'koopo.php'),
            [],
            $ver
        );
        wp_register_script(
            'koopo-stories',
            plugins_url('assets/stories/stories.js', KOOPO_PATH . 'koopo.php'),
            [],
            $ver,
            true
        );
    }

    public function enqueue_assets() : void {
        if ( $this->assets_enqueued ) return;
        $this->assets_enqueued = true;

        wp_enqueue_style('koopo-stories');
        wp_enqueue_script('koopo-stories');

        $current_user_id = get_current_user_id();
        wp_localize_script('koopo-stories', 'KoopoStories', [
            'restUrl' => esc_url_raw( rest_url( self::REST_NS . '/stories' ) ),
            'nonce'   => wp_create_nonce('wp_rest'),
            'me'      => $current_user_id,
            'meAvatar' => get_avatar_url($current_user_id, [ 'size' => 96 ]),
        ]);
    }

    public function render_activity_tray() : void {
        if ( ! is_user_logged_in() ) return;
        $this->enqueue_assets();
        echo '<div class="koopo-stories koopo-stories--tray" data-scope="friends" data-limit="20"></div>';
    }

    public function shortcode_widget( $atts = [] ) : string {
        if ( ! is_user_logged_in() ) return '';
        if ( get_option(self::OPTION_ENABLE, '1') !== '1' ) return '';

        $atts = shortcode_atts([
            'title' => '',
            'limit' => intval(get_option('koopo_stories_default_limit', 10)),
            'scope' => get_option('koopo_stories_default_scope', 'friends'), // friends|following|all
            'layout' => get_option('koopo_stories_default_layout', 'horizontal'), // horizontal|vertical
            'exclude_me' => get_option('koopo_stories_default_exclude_me', '0'),
            'order' => get_option('koopo_stories_default_order', 'unseen_first'), // unseen_first|recent_activity
            'show_uploader' => get_option('koopo_stories_default_show_uploader', '1'),
            'show_unseen_badge' => get_option('koopo_stories_default_show_unseen_badge', '1'),
        ], $atts, 'koopo_stories_widget');

        $limit = max(1, min(50, intval($atts['limit'])));
        $scope = in_array($atts['scope'], ['friends','following','all'], true) ? $atts['scope'] : 'friends';
        $layout = in_array($atts['layout'], ['horizontal','vertical'], true) ? $atts['layout'] : 'horizontal';
        $exclude_me = ($atts['exclude_me'] === '1' || $atts['exclude_me'] === 'true');
        $order = in_array($atts['order'], ['unseen_first','recent_activity'], true) ? $atts['order'] : 'unseen_first';

        $show_uploader = ($atts['show_uploader'] === '1' || $atts['show_uploader'] === 'true');
        $show_badge = ($atts['show_unseen_badge'] === '1' || $atts['show_unseen_badge'] === 'true');

        $classes = 'koopo-stories';
        if ( $layout === 'vertical' ) $classes .= ' koopo-stories--vertical';

        $this->enqueue_assets();

        ob_start();
        if ( ! empty($atts['title']) ) {
            echo '<h3 class="koopo-stories__title">' . esc_html($atts['title']) . '</h3>';
        }
        printf(
            '<div class="%s" data-limit="%d" data-scope="%s" data-layout="%s" data-exclude-me="%s" data-order="%s" data-show-uploader="%s" data-show-unseen-badge="%s"></div>',
            esc_attr($classes),
            esc_attr($limit),
            esc_attr($scope),
            esc_attr($layout),
            esc_attr($exclude_me ? '1' : '0'),
            esc_attr($order),
            esc_attr($show_uploader ? '1' : '0'),
            esc_attr($show_badge ? '1' : '0')
        );
        return ob_get_clean();
    }

}
