<?php
defined( 'ABSPATH' ) || exit;

class Koopo_Login_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'koopo-login'; }
    public function get_title() { return __( 'Koopo Login', 'koopo' ); }
    public function get_icon() { return 'eicon-lock-user'; }
    public function get_categories() { return array( 'general' ); }
    public function get_keywords() { return array( 'koopo', 'login', 'sign in', 'authentication' ); }
    public function get_style_depends() { return array( 'koopo-login' ); }
    public function get_script_depends() { return array( 'koopo-login' ); }
    protected function is_dynamic_content(): bool { return true; }
    protected function register_controls() {
        $this->start_controls_section( 'form_content', array( 'label' => __( 'Login form', 'koopo' ) ) );
        foreach ( array( 'user_label' => 'Email or username', 'user_placeholder' => 'Enter your email or username', 'password_label' => 'Password', 'password_placeholder' => 'Enter your password', 'button_text' => 'Sign in' ) as $name => $default ) {
            $this->add_control( $name, array( 'label' => ucwords( str_replace( '_', ' ', $name ) ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $default ) );
        }
        $this->add_control( 'show_social', array( 'label' => __( 'Show enabled social providers', 'koopo' ), 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => 'yes', 'return_value' => 'yes' ) );
        $this->end_controls_section();
        $this->start_controls_section( 'form_style', array( 'label' => __( 'Form style', 'koopo' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
        $this->add_control( 'button_color', array( 'label' => __( 'Button color', 'koopo' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#FFBA12', 'selectors' => array( '{{WRAPPER}} .kl-submit' => 'background-color: {{VALUE}};' ) ) );
        $this->add_control( 'button_text_color', array( 'label' => __( 'Button text color', 'koopo' ), 'type' => \Elementor\Controls_Manager::COLOR, 'default' => '#171717', 'selectors' => array( '{{WRAPPER}} .kl-submit' => 'color: {{VALUE}};' ) ) );
        $this->end_controls_section();
    }
    protected function render() { echo Koopo_Login::boot()->form( $this->get_settings_for_display() ); }
}
