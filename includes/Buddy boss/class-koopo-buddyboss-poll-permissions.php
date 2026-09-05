<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Koopo_BuddyBoss_Poll_Permissions {
    const OPTION_ALLOW_ALL_MEMBERS = 'koopo_allow_member_activity_polls';

    public static function boot() {
        add_filter( 'bb_can_user_create_poll_activity', array( __CLASS__, 'allow_member_profile_polls' ), 20, 2 );
    }

    public static function members_are_enabled() {
        return rest_sanitize_boolean( get_option( self::OPTION_ALLOW_ALL_MEMBERS, '1' ) );
    }

    public static function sanitize_enabled( $value ) {
        return rest_sanitize_boolean( $value );
    }

    public static function allow_member_profile_polls( $has_access, $args ) {
        if ( $has_access || ! self::members_are_enabled() ) {
            return $has_access;
        }

        if (
            ! function_exists( 'bb_is_enabled_activity_post_polls' )
            || ! bb_is_enabled_activity_post_polls( false )
        ) {
            return $has_access;
        }

        $args     = is_array( $args ) ? $args : array();
        $object   = isset( $args['object'] ) ? sanitize_key( (string) $args['object'] ) : '';
        $group_id = isset( $args['group_id'] ) ? absint( $args['group_id'] ) : 0;
        if ( 'group' === $object || $group_id > 0 || ( function_exists( 'bp_is_group' ) && bp_is_group() ) ) {
            return $has_access;
        }

        $user_id = ! empty( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id();
        return $user_id > 0 && get_current_user_id() === $user_id;
    }
}
