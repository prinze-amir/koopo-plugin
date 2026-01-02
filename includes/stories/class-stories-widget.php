<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_Widget extends WP_Widget {

    public static function register() : void {
        register_widget( __CLASS__ );
    }

    public function __construct() {
        parent::__construct(
            'koopo_stories_widget',
            __('Koopo Stories', 'koopo'),
            [ 'description' => __('Displays a stories tray (friends/following/all).', 'koopo') ]
        );
    }

    public function form( $instance ) {
        $title      = isset($instance['title']) ? $instance['title'] : '';
        $limit_default = intval(get_option('koopo_stories_default_limit', 10));
        $scope_default = get_option('koopo_stories_default_scope', 'friends');
        $layout_default = get_option('koopo_stories_default_layout', 'horizontal');
        $order_default = get_option('koopo_stories_default_order', 'unseen_first');
        $exclude_default = (get_option('koopo_stories_default_exclude_me', '0') === '1');
        $show_uploader_default = (get_option('koopo_stories_default_show_uploader', '1') === '1');
        $show_badge_default = (get_option('koopo_stories_default_show_unseen_badge', '1') === '1');
        $limit      = isset($instance['limit']) ? intval($instance['limit']) : $limit_default;
        $scope      = isset($instance['scope']) ? $instance['scope'] : $scope_default;
        $layout     = isset($instance['layout']) ? $instance['layout'] : $layout_default;
        $exclude_me = isset($instance['exclude_me']) ? ! empty($instance['exclude_me']) : $exclude_default;
        $order      = isset($instance['order']) ? $instance['order'] : $order_default;
        $show_uploader = isset($instance['show_uploader']) ? ! empty($instance['show_uploader']) : $show_uploader_default;
        $show_unseen_badge = isset($instance['show_unseen_badge']) ? ! empty($instance['show_unseen_badge']) : $show_badge_default;
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'koopo'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>"><?php esc_html_e('Limit:', 'koopo'); ?></label>
            <input class="small-text" id="<?php echo esc_attr($this->get_field_id('limit')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('limit')); ?>" type="number" min="1" max="50"
                   value="<?php echo esc_attr($limit); ?>">
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('scope')); ?>"><?php esc_html_e('Scope:', 'koopo'); ?></label>
            <select id="<?php echo esc_attr($this->get_field_id('scope')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('scope')); ?>">
                <option value="friends" <?php selected($scope, 'friends'); ?>><?php esc_html_e('Friends / Connections', 'koopo'); ?></option>
                <option value="following" <?php selected($scope, 'following'); ?>><?php esc_html_e('Following', 'koopo'); ?></option>
                <option value="all" <?php selected($scope, 'all'); ?>><?php esc_html_e('All users', 'koopo'); ?></option>
            </select>
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('order')); ?>"><?php esc_html_e('Order:', 'koopo'); ?></label>
            <select id="<?php echo esc_attr($this->get_field_id('order')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('order')); ?>">
                <option value="unseen_first" <?php selected($order, 'unseen_first'); ?>><?php esc_html_e('Unseen first', 'koopo'); ?></option>
                <option value="recent_activity" <?php selected($order, 'recent_activity'); ?>><?php esc_html_e('Recent activity', 'koopo'); ?></option>
            </select>
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('layout')); ?>"><?php esc_html_e('Layout:', 'koopo'); ?></label>
            <select id="<?php echo esc_attr($this->get_field_id('layout')); ?>"
                    name="<?php echo esc_attr($this->get_field_name('layout')); ?>">
                <option value="horizontal" <?php selected($layout, 'horizontal'); ?>><?php esc_html_e('Horizontal tray', 'koopo'); ?></option>
                <option value="vertical" <?php selected($layout, 'vertical'); ?>><?php esc_html_e('Vertical list (sidebar)', 'koopo'); ?></option>
            </select>
        </p>

        <p>
            <input class="checkbox" type="checkbox"
                   <?php checked($exclude_me, true); ?>
                   id="<?php echo esc_attr($this->get_field_id('exclude_me')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('exclude_me')); ?>" />
            <label for="<?php echo esc_attr($this->get_field_id('exclude_me')); ?>"><?php esc_html_e('Exclude my stories', 'koopo'); ?></label>
        </p>

        <p style="font-size:12px;color:#666;margin-top:8px;">
            <?php esc_html_e('Note: Stories display for logged-in users only.', 'koopo'); ?>
        </p>

        <p>
            <input class="checkbox" type="checkbox" <?php checked( $show_uploader ); ?> id="<?php echo esc_attr($this->get_field_id('show_uploader')); ?>" name="<?php echo esc_attr($this->get_field_name('show_uploader')); ?>" value="1" />
            <label for="<?php echo esc_attr($this->get_field_id('show_uploader')); ?>"><?php esc_html_e('Show "Your Story" uploader bubble', 'koopo'); ?></label>
        </p>
        <p>
            <input class="checkbox" type="checkbox" <?php checked( $show_unseen_badge ); ?> id="<?php echo esc_attr($this->get_field_id('show_unseen_badge')); ?>" name="<?php echo esc_attr($this->get_field_name('show_unseen_badge')); ?>" value="1" />
            <label for="<?php echo esc_attr($this->get_field_id('show_unseen_badge')); ?>"><?php esc_html_e('Show unseen count badge', 'koopo'); ?></label>
        </p>
        <?php
    }

public function update( $new_instance, $old_instance ) {
        $instance = [];
        $instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );

        $limit = intval( $new_instance['limit'] ?? 10 );
        $instance['limit'] = max(1, min(50, $limit));

        $scope = $new_instance['scope'] ?? 'friends';
        $instance['scope'] = in_array($scope, ['friends','following','all'], true) ? $scope : 'friends';

        $order = $new_instance['order'] ?? 'unseen_first';
        $instance['order'] = in_array($order, ['unseen_first','recent_activity'], true) ? $order : 'unseen_first';

        $layout = $new_instance['layout'] ?? 'horizontal';
        $instance['layout'] = in_array($layout, ['horizontal','vertical'], true) ? $layout : 'horizontal';

        $instance['exclude_me'] = ! empty($new_instance['exclude_me']) ? 1 : 0;

        return $instance;
    }

    public function widget( $args, $instance ) {
        if ( ! is_user_logged_in() ) return;
        if ( get_option( Koopo_Stories_Module::OPTION_ENABLE, '0' ) !== '1' ) return;

        $limit_default = intval(get_option('koopo_stories_default_limit', 10));
        $scope_default = get_option('koopo_stories_default_scope', 'friends');
        $layout_default = get_option('koopo_stories_default_layout', 'horizontal');
        $order_default = get_option('koopo_stories_default_order', 'unseen_first');
        $exclude_default = (get_option('koopo_stories_default_exclude_me', '0') === '1');
        $show_uploader_default = (get_option('koopo_stories_default_show_uploader', '1') === '1');
        $show_badge_default = (get_option('koopo_stories_default_show_unseen_badge', '1') === '1');

        $title      = $instance['title'] ?? '';
        $limit      = isset($instance['limit']) ? intval($instance['limit']) : $limit_default;
        $scope      = $instance['scope'] ?? $scope_default;
        $layout     = $instance['layout'] ?? $layout_default;
        $exclude_me = isset($instance['exclude_me']) ? ! empty($instance['exclude_me']) : $exclude_default;
        $order      = $instance['order'] ?? $order_default;
        $show_uploader = isset($instance['show_uploader']) ? ! empty($instance['show_uploader']) : $show_uploader_default;
        $show_unseen_badge = isset($instance['show_unseen_badge']) ? ! empty($instance['show_unseen_badge']) : $show_badge_default;

        echo $args['before_widget'];

        if ( ! empty($title) ) {
            echo $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'];
        }

        $classes = 'koopo-stories';
        if ( $layout === 'vertical' ) $classes .= ' koopo-stories--vertical';

        printf(
            '<div class="%s" data-limit="%d" data-scope="%s" data-layout="%s" data-exclude-me="%s" data-order="%s" data-show-uploader="%s" data-show-unseen-badge="%s"></div>',
            esc_attr($classes),
            esc_attr(max(1, min(50, $limit))),
            esc_attr($scope),
            esc_attr($layout),
            esc_attr($exclude_me ? '1' : '0'),
            esc_attr($order),
            esc_attr($show_uploader ? '1' : '0'),
            esc_attr($show_unseen_badge ? '1' : '0')
        );

        // Ensure scripts/styles load when widget is used
        if ( class_exists('Koopo_Stories_Module') ) {
            Koopo_Stories_Module::instance()->enqueue_assets();
        }

        echo $args['after_widget'];
    }
}
