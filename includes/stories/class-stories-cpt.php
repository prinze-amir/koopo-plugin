<?php
if ( ! defined('ABSPATH') ) exit;

class Koopo_Stories_CPT {

    public static function register() : void {
        self::register_story();
        self::register_item();
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
}
