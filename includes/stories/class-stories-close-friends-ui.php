<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Koopo Stories Close Friends UI
 * Provides user-facing interface for managing close friends lists
 */
class Koopo_Stories_Close_Friends_UI {

    /**
     * Initialize UI components
     */
    public static function init() : void {
        // Shortcode for close friends manager
        add_shortcode('koopo_close_friends_manager', [ __CLASS__, 'shortcode_manager' ]);

        // Enqueue assets when shortcode is present
        add_action('wp_enqueue_scripts', [ __CLASS__, 'register_assets' ]);
    }

    /**
     * Register CSS/JS assets
     */
    public static function register_assets() : void {
        $ver = defined('KOOPO_STORIES_VER') ? KOOPO_STORIES_VER : '1.0.0';

        wp_register_style(
            'koopo-close-friends-ui',
            plugins_url('assets/stories/close-friends-ui.css', KOOPO_PATH . 'koopo.php'),
            [],
            $ver
        );

        wp_register_script(
            'koopo-close-friends-ui',
            plugins_url('assets/stories/close-friends-ui.js', KOOPO_PATH . 'koopo.php'),
            [],
            $ver,
            true
        );
    }

    /**
     * Enqueue assets
     */
    public static function enqueue_assets() : void {
        wp_enqueue_style('koopo-close-friends-ui');
        wp_enqueue_script('koopo-close-friends-ui');

        wp_localize_script('kooop-close-friends-ui', 'KoopoCloseFriends', [
            'restUrl' => esc_url_raw( rest_url( Koopo_Stories_Module::REST_NS . '/stories/close-friends' ) ),
            'nonce'   => wp_create_nonce('wp_rest'),
            'userId'  => get_current_user_id(),
        ]);
    }

    /**
     * Shortcode: [koopo_close_friends_manager]
     * Renders close friends management interface
     */
    public static function shortcode_manager( $atts = [] ) : string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__('Please log in to manage your close friends list.', 'koopo') . '</p>';
        }

        self::enqueue_assets();

        $user_id = get_current_user_id();
        $close_friends = Koopo_Stories_Close_Friends::get_close_friends($user_id);
        $all_friends = Koopo_Stories_Permissions::friend_ids($user_id);

        ob_start();
        ?>
        <div class="koopo-close-friends-manager">
            <div class="koopo-close-friends-header">
                <h3><?php esc_html_e('Close Friends', 'koopo'); ?></h3>
                <p class="koopo-close-friends-description">
                    <?php esc_html_e('Choose friends who can see your stories marked as "Close Friends". These people will see a special badge when viewing your stories.', 'koopo'); ?>
                </p>
            </div>

            <div class="koopo-close-friends-count">
                <strong><?php echo count($close_friends); ?></strong> <?php esc_html_e('close friends', 'koopo'); ?>
            </div>

            <?php if ( empty($all_friends) ) : ?>
                <div class="koopo-close-friends-empty">
                    <p><?php esc_html_e('You don\'t have any friends yet. Connect with people to add them to your close friends list.', 'koopo'); ?></p>
                </div>
            <?php else : ?>
                <div class="koopo-close-friends-list">
                    <?php foreach ( $all_friends as $friend_id ) :
                        $user = get_user_by('id', $friend_id);
                        if ( ! $user ) continue;

                        $is_close = in_array($friend_id, $close_friends, true);
                        $avatar_url = get_avatar_url($friend_id, [ 'size' => 64 ]);
                        $profile_url = function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($friend_id) : '';
                    ?>
                        <div class="koopo-close-friend-item" data-user-id="<?php echo esc_attr($friend_id); ?>">
                            <div class="koopo-close-friend-avatar">
                                <?php if ( $profile_url ) : ?>
                                    <a href="<?php echo esc_url($profile_url); ?>" target="_blank">
                                        <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                                    </a>
                                <?php else : ?>
                                    <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                                <?php endif; ?>
                            </div>
                            <div class="koopo-close-friend-info">
                                <div class="koopo-close-friend-name">
                                    <?php if ( $profile_url ) : ?>
                                        <a href="<?php echo esc_url($profile_url); ?>" target="_blank">
                                            <?php echo esc_html($user->display_name); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html($user->display_name); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="koopo-close-friend-username">
                                    @<?php echo esc_html($user->user_login); ?>
                                </div>
                            </div>
                            <div class="koopo-close-friend-actions">
                                <button
                                    type="button"
                                    class="koopo-close-friend-toggle <?php echo $is_close ? 'is-close' : ''; ?>"
                                    data-friend-id="<?php echo esc_attr($friend_id); ?>"
                                    data-action="<?php echo $is_close ? 'remove' : 'add'; ?>"
                                >
                                    <?php echo $is_close ? esc_html__('Remove', 'koopo') : esc_html__('Add', 'koopo'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
