<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Site-wide placement policy; creator payment eligibility still applies. */
class Koopo_Creator_Support_Settings {
    const OPTION = 'koopo_creator_support_placements';

    public static function labels() {
        return array( 'video_channels' => __( 'Video channels', 'koopo' ), 'videos' => __( 'Single video pages', 'koopo' ), 'live' => __( 'Live streams and paid chat', 'koopo' ), 'blogs' => __( 'Blog articles and blog profiles', 'koopo' ) );
    }
    public function hooks() {
        add_action( 'koopo_admin_register_submenus', array( $this, 'menu' ), 20, 2 );
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'add_option_' . self::OPTION, array( $this, 'purge' ) );
        add_action( 'update_option_' . self::OPTION, array( $this, 'purge' ) );
    }
    public function menu( $parent, $capability ) {
        add_submenu_page( $parent, __( 'Creator Support', 'koopo' ), __( 'Creator Support', 'koopo' ), 'manage_options', 'koopo-creator-support', array( $this, 'page' ) );
    }
    public function register() {
        register_setting( 'koopo_creator_support', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => array_fill_keys( array_keys( self::labels() ), 1 ) ) );
    }
    public function sanitize( $input ) {
        $result = array();
        foreach ( self::labels() as $key => $label ) { $result[ $key ] = is_array( $input ) && isset( $input[ $key ] ) && (string) $input[ $key ] === '1' ? 1 : 0; }
        return $result;
    }
    public function purge() { do_action( 'litespeed_purge_all' ); }
    public static function enabled( array $context ) {
        $surface = $context['surface'] ?? '';
        $module = $context['module'] ?? '';
        $type = ! empty( $context['context_post_id'] ) ? get_post_type( (int) $context['context_post_id'] ) : ( $context['context_post_type'] ?? '' );
        if ( $surface === 'live_paid_chat' ) { $key = 'live'; }
        elseif ( $surface === 'single_video' || $surface === 'legacy_video' || $type === 'koopo_video' ) { $key = 'videos'; }
        elseif ( $module === 'videos' ) { $key = 'video_channels'; }
        elseif ( $module === 'influencer_square' || $type === 'post' || in_array( $surface, array( 'single_post', 'buddyblog_tab' ), true ) ) { $key = 'blogs'; }
        else { return false; }
        $settings = get_option( self::OPTION, array() );
        return ! is_array( $settings ) || ! array_key_exists( $key, $settings ) || (int) $settings[ $key ] === 1;
    }
    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = get_option( self::OPTION, array() );
        echo '<div class="wrap"><h1>' . esc_html__( 'Creator Support', 'koopo' ) . '</h1><p>' . esc_html__( 'Choose where viewers can support creators. Each creator must also have support enabled and finish payment setup.', 'koopo' ) . '</p>';
        settings_errors();
        echo '<form method="post" action="options.php">';
        settings_fields( 'koopo_creator_support' );
        echo '<table class="form-table" role="presentation">';
        foreach ( self::labels() as $key => $label ) {
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="hidden" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="0"><input type="checkbox" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="1" ' . checked( $settings[ $key ] ?? 1, 1, false ) . '> ' . esc_html__( 'Enable support', 'koopo' ) . '</label></td></tr>';
        }
        echo '</table><p class="description">' . esc_html__( 'Disabling a location hides its support buttons and prevents new checkouts there. Payments already started can still finish, and existing orders and earnings are preserved.', 'koopo' ) . '</p>';
        submit_button();
        echo '</form></div>';
    }
}
