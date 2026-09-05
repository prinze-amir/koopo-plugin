<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Koopo_BuddyBoss_Display_Name' ) ) {

    class Koopo_BuddyBoss_Display_Name {

        const OPTION_CHOICE_FIELD_ID  = 'koopo_profile_display_name_field_id';
        const OPTION_COMPANY_FIELD_ID = 'koopo_company_name_field_id';
        const OPTION_ALIAS_FIELD_ID   = 'koopo_alias_name_field_id';
        const DEFAULT_CHOICE          = 'first_and_lastname';

        private static $resolving = false;

        public function init() {
            add_filter( 'bp_core_get_user_displayname', [ $this, 'filter_bp_display_name' ], 99, 3 );
            add_filter( 'bp_xprofile_get_member_display_name', [ $this, 'filter_xprofile_display_name' ], 99, 2 );
            add_filter( 'bp_displayed_user_fullname', [ $this, 'filter_displayed_user_fullname' ], 99 );
            add_filter( 'bp_get_member_name', [ $this, 'filter_member_loop_name' ], 99 );
            add_filter( 'get_the_author_display_name', [ $this, 'filter_author_display_name' ], 99, 2 );
        }

        public function filter_bp_display_name( $full_name, $user_id, $current_user_id = 0 ) {
            unset( $current_user_id );
            return self::get_user_display_name( $user_id, $full_name );
        }

        public function filter_xprofile_display_name( $display_name, $user_id ) {
            return self::get_user_display_name( $user_id, $display_name );
        }

        public function filter_displayed_user_fullname( $full_name ) {
            if ( ! function_exists( 'bp_displayed_user_id' ) ) {
                return $full_name;
            }

            return self::get_user_display_name( bp_displayed_user_id(), $full_name );
        }

        public function filter_member_loop_name( $full_name ) {
            global $members_template;

            if ( empty( $members_template->member->ID ) ) {
                return $full_name;
            }

            return self::get_user_display_name( (int) $members_template->member->ID, $full_name );
        }

        public function filter_author_display_name( $display_name, $user_id ) {
            return self::get_user_display_name( $user_id, $display_name );
        }

        public static function get_user_display_name( $user_id, $fallback = '' ) {
            $user_id = absint( (int) $user_id );
            if ( $user_id <= 0 || self::$resolving ) {
                return self::clean_name( $fallback );
            }

            self::$resolving = true;

            $choice = self::get_user_display_choice( $user_id );
            $name   = '';

            switch ( $choice ) {
                case 'company_name':
                    $name = self::get_xprofile_field_value( self::get_company_field_id(), $user_id );
                    break;

                case 'first_name':
                    $name = self::get_first_name( $user_id );
                    break;

                case 'username':
                    $user = get_userdata( $user_id );
                    $name = $user instanceof WP_User ? (string) $user->user_login : '';
                    break;

                case 'nickname':
                    $name = self::get_nickname( $user_id );
                    break;

                case 'first_and_lastname':
                default:
                    $name = trim( self::get_first_name( $user_id ) . ' ' . self::get_last_name( $user_id ) );
                    break;
            }

            if ( '' === self::clean_name( $name ) ) {
                $name = self::get_fallback_display_name( $user_id, $fallback );
            }

            self::$resolving = false;

            return self::clean_name( $name );
        }

        public static function get_user_display_choice( $user_id ) {
            $choice_field_id = self::get_choice_field_id();
            $choice          = self::get_xprofile_field_value( $choice_field_id, $user_id );
            $choice          = self::normalize_display_choice( $choice );

            if ( in_array( $choice, self::get_allowed_choices(), true ) ) {
                return $choice;
            }

            return self::DEFAULT_CHOICE;
        }

        public static function normalize_display_choice( $choice ) {
            $choice = strtolower( self::clean_name( $choice ) );
            $choice = str_replace( [ '&', '+' ], ' and ', $choice );
            $choice = preg_replace( '/[^a-z0-9]+/', '_', $choice );
            $choice = trim( (string) $choice, '_' );

            $aliases = [
                'first_and_lastname' => 'first_and_lastname',
                'first_and_last_name' => 'first_and_lastname',
                'first_last_name' => 'first_and_lastname',
                'first_lastname' => 'first_and_lastname',
                'full_name' => 'first_and_lastname',
                'first_name_last_name' => 'first_and_lastname',
                'first_name' => 'first_name',
                'firstname' => 'first_name',
                'username' => 'username',
                'user_name' => 'username',
                'login' => 'username',
                'company_name' => 'company_name',
                'company' => 'company_name',
                'business_name' => 'company_name',
                'business' => 'company_name',
                'nickname' => 'nickname',
                'nick_name' => 'nickname',
                'alias' => 'nickname',
                'alias_name' => 'nickname',
                'display_name' => 'nickname',
            ];

            return isset( $aliases[ $choice ] ) ? $aliases[ $choice ] : $choice;
        }

        public static function get_choice_field_id() {
            return absint( get_option( self::OPTION_CHOICE_FIELD_ID, 0 ) );
        }

        public static function get_company_field_id() {
            return absint( get_option( self::OPTION_COMPANY_FIELD_ID, 0 ) );
        }

        public static function get_alias_field_id() {
            return absint( get_option( self::OPTION_ALIAS_FIELD_ID, 0 ) );
        }

        public static function get_allowed_choices() {
            return [
                'first_and_lastname',
                'first_name',
                'username',
                'company_name',
                'nickname',
            ];
        }

        private static function get_first_name( $user_id ) {
            $field_id = function_exists( 'bp_xprofile_firstname_field_id' ) ? absint( bp_xprofile_firstname_field_id() ) : 0;
            $name     = self::get_xprofile_field_value( $field_id, $user_id );

            if ( '' !== $name ) {
                return $name;
            }

            return self::clean_name( get_user_meta( $user_id, 'first_name', true ) );
        }

        private static function get_last_name( $user_id ) {
            $field_id = function_exists( 'bp_xprofile_lastname_field_id' ) ? absint( bp_xprofile_lastname_field_id() ) : 0;
            $name     = self::get_xprofile_field_value( $field_id, $user_id );

            if ( '' !== $name ) {
                return $name;
            }

            return self::clean_name( get_user_meta( $user_id, 'last_name', true ) );
        }

        private static function get_nickname( $user_id ) {
            $name = self::get_xprofile_field_value( self::get_alias_field_id(), $user_id );
            if ( '' !== $name ) {
                return $name;
            }

            $field_id = function_exists( 'bp_xprofile_nickname_field_id' ) ? absint( bp_xprofile_nickname_field_id() ) : 0;
            $name     = self::get_xprofile_field_value( $field_id, $user_id );

            if ( '' !== $name ) {
                return $name;
            }

            return self::clean_name( get_user_meta( $user_id, 'nickname', true ) );
        }

        private static function get_xprofile_field_value( $field_id, $user_id ) {
            $field_id = absint( (int) $field_id );
            if ( $field_id <= 0 || ! function_exists( 'xprofile_get_field_data' ) ) {
                return '';
            }

            $value = xprofile_get_field_data( $field_id, $user_id );
            if ( is_array( $value ) ) {
                $value = implode( ' ', array_filter( array_map( 'sanitize_text_field', $value ) ) );
            }

            return self::clean_name( $value );
        }

        private static function get_fallback_display_name( $user_id, $fallback = '' ) {
            $fallback = self::clean_name( $fallback );
            if ( '' !== $fallback ) {
                return $fallback;
            }

            $user = get_userdata( $user_id );
            if ( ! $user instanceof WP_User ) {
                return '';
            }

            $raw_display_name = isset( $user->data->display_name ) ? (string) $user->data->display_name : '';
            if ( '' !== self::clean_name( $raw_display_name ) ) {
                return $raw_display_name;
            }

            return (string) $user->user_login;
        }

        private static function clean_name( $value ) {
            $value = wp_strip_all_tags( (string) $value );
            $value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) );
            $value = preg_replace( '/\s+/', ' ', $value );

            return trim( (string) $value );
        }
    }
}

if ( ! function_exists( 'koopo_get_user_display_name' ) ) {
    function koopo_get_user_display_name( $user_id, $fallback = '' ) {
        if ( class_exists( 'Koopo_BuddyBoss_Display_Name' ) ) {
            return Koopo_BuddyBoss_Display_Name::get_user_display_name( $user_id, $fallback );
        }

        return trim( wp_strip_all_tags( (string) $fallback ) );
    }
}
