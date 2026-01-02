<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_CPT {

    public static function register() : void {
        self::register_story();
        self::register_item();

        // Ensure required meta defaults exist for stories created/edited outside the REST uploader.
        add_action('save_post_' . Koopo_Stories_Module::CPT_STORY, [ __CLASS__, 'ensure_defaults' ], 10, 3);
    }

    private static function register_story() : void {
        register_post_type( Koopo_Stories_Module::CPT_STORY, [
            'label' => __('Stories', 'koopo'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'supports' => [ 'title', 'author' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }

    private static function register_item() : void {
        register_post_type( Koopo_Stories_Module::CPT_ITEM, [
            'label' => __('Story Items', 'koopo'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=' . Koopo_Stories_Module::CPT_STORY,
            'supports' => [ 'title', 'author' ],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ] );
    }


public static function ensure_defaults( int $post_id, $post, bool $update ) : void {
    if ( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) return;
    if ( ! $post || $post->post_type !== Koopo_Stories_Module::CPT_STORY ) return;

    // Only enforce for published stories.
    if ( $post->post_status !== 'publish' ) return;

    $duration_hours = (int) get_option('koopo_stories_duration_hours', 24);
    if ( $duration_hours < 1 ) $duration_hours = 24;
    $expires_at_new = time() + ( $duration_hours * HOUR_IN_SECONDS );

    $expires_at = (int) get_post_meta($post_id, 'expires_at', true);
    if ( $expires_at <= 0 ) {
        update_post_meta($post_id, 'expires_at', $expires_at_new);
    }

    $privacy = get_post_meta($post_id, 'privacy', true);
    if ( ! $privacy ) {
        update_post_meta($post_id, 'privacy', 'friends');
    }
}

}
