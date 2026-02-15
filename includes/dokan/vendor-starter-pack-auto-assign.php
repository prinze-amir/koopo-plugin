<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Koopo_Dokan_Vendor_Starter_Pack_Assigner' ) ) {

    class Koopo_Dokan_Vendor_Starter_Pack_Assigner {

        const OPTION_PACK_ID   = 'koopo_vendor_starter_pack_id';
        const META_PENDING     = '_koopo_pending_starter_pack_assignment';
        const META_ASSIGNED_AT = '_koopo_starter_pack_assigned_at';
        const META_ASSIGNED_ID = '_koopo_starter_pack_assigned_pack_id';
        const META_LOCK        = '_koopo_starter_pack_assignment_lock';

        public function init() {
            add_action( 'dokan_new_seller_created', [ $this, 'mark_new_vendor_for_assignment' ], 20, 1 );
            add_action( 'dokan_new_vendor', [ $this, 'mark_new_vendor_for_assignment' ], 20, 1 );

            // BuddyBoss activation fires after pending users are activated.
            add_action( 'bp_core_activated_user', [ $this, 'maybe_assign_after_activation' ], 30, 1 );

            // Fallbacks for non-BuddyBoss flows where vendor role is added immediately.
            add_action( 'add_user_role', [ $this, 'maybe_assign_on_role_added' ], 20, 2 );
            add_action( 'set_user_role', [ $this, 'maybe_assign_on_role_set' ], 20, 2 );
        }

        public function mark_new_vendor_for_assignment( $user_id ) {
            $user_id = absint( $user_id );
            if ( ! $user_id ) {
                return;
            }

            update_user_meta( $user_id, self::META_PENDING, 'yes' );
            $this->maybe_assign_starter_pack( $user_id );
        }

        public function maybe_assign_after_activation( $user_id ) {
            $this->maybe_assign_starter_pack( absint( $user_id ) );
        }

        public function maybe_assign_on_role_added( $user_id, $role ) {
            if ( 'seller' !== $role ) {
                return;
            }

            $this->maybe_assign_starter_pack( absint( $user_id ) );
        }

        public function maybe_assign_on_role_set( $user_id, $role ) {
            if ( 'seller' !== $role ) {
                return;
            }

            $this->maybe_assign_starter_pack( absint( $user_id ) );
        }

        private function maybe_assign_starter_pack( $user_id ) {
            if ( ! $user_id || ! $this->should_attempt_assignment( $user_id ) ) {
                return;
            }

            // Prevent duplicate assignment if multiple hooks fire in the same request.
            if ( ! add_user_meta( $user_id, self::META_LOCK, time(), true ) ) {
                return;
            }

            try {
                $pack_id = $this->get_starter_pack_id( $user_id );
                if ( ! $pack_id ) {
                    return;
                }

                $order = $this->create_pack_order( $user_id, $pack_id );
                if ( is_wp_error( $order ) ) {
                    return;
                }

                $this->activate_pack_for_vendor( $user_id, $pack_id, $order );

                if ( absint( get_user_meta( $user_id, 'product_package_id', true ) ) !== $pack_id ) {
                    return;
                }

                update_user_meta( $user_id, self::META_ASSIGNED_AT, current_time( 'mysql' ) );
                update_user_meta( $user_id, self::META_ASSIGNED_ID, $pack_id );
                delete_user_meta( $user_id, self::META_PENDING );
            } finally {
                delete_user_meta( $user_id, self::META_LOCK );
            }
        }

        private function should_attempt_assignment( $user_id ) {
            if ( 'yes' !== get_user_meta( $user_id, self::META_PENDING, true ) ) {
                return false;
            }

            if ( ! $this->is_user_seller( $user_id ) ) {
                return false;
            }

            if ( $this->is_pending_activation( $user_id ) ) {
                return false;
            }

            if ( absint( get_user_meta( $user_id, 'product_package_id', true ) ) > 0 ) {
                delete_user_meta( $user_id, self::META_PENDING );
                return false;
            }

            if ( get_user_meta( $user_id, self::META_ASSIGNED_AT, true ) ) {
                delete_user_meta( $user_id, self::META_PENDING );
                return false;
            }

            return true;
        }

        private function is_user_seller( $user_id ) {
            if ( function_exists( 'dokan_is_user_seller' ) ) {
                return (bool) dokan_is_user_seller( $user_id );
            }

            $user = get_userdata( $user_id );
            if ( ! $user || ! is_array( $user->roles ) ) {
                return false;
            }

            return in_array( 'seller', $user->roles, true );
        }

        private function is_pending_activation( $user_id ) {
            $user = get_userdata( $user_id );
            if ( ! $user ) {
                return false;
            }

            return isset( $user->user_status ) && 2 === (int) $user->user_status;
        }

        private function get_starter_pack_id( $user_id ) {
            $pack_id = absint( get_option( self::OPTION_PACK_ID, 0 ) );
            $pack_id = absint( apply_filters( 'koopo_vendor_starter_pack_id', $pack_id, $user_id ) );

            if ( $pack_id > 0 ) {
                return $this->is_valid_pack_id( $pack_id ) ? $pack_id : 0;
            }

            $auto_detect = (bool) apply_filters( 'koopo_vendor_starter_pack_auto_detect', true, $user_id );
            if ( ! $auto_detect ) {
                return 0;
            }

            return $this->find_first_free_pack_id();
        }

        private function is_valid_pack_id( $pack_id ) {
            $product = wc_get_product( $pack_id );

            return $product && $product->is_type( 'product_pack' );
        }

        private function find_first_free_pack_id() {
            $query = new WP_Query(
                [
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 20,
                    'fields'         => 'ids',
                    'orderby'        => 'menu_order title',
                    'order'          => 'ASC',
                    'tax_query'      => [
                        [
                            'taxonomy' => 'product_type',
                            'field'    => 'slug',
                            'terms'    => 'product_pack',
                        ],
                    ],
                    'no_found_rows'  => true,
                ]
            );

            if ( empty( $query->posts ) ) {
                return 0;
            }

            foreach ( $query->posts as $pack_id ) {
                $product = wc_get_product( $pack_id );
                if ( ! $product ) {
                    continue;
                }

                if ( (float) $product->get_price() <= 0 ) {
                    return absint( $pack_id );
                }
            }

            return 0;
        }

        private function create_pack_order( $user_id, $pack_id ) {
            $product = wc_get_product( $pack_id );
            if ( ! $product ) {
                return new WP_Error( 'koopo_invalid_pack', 'Starter pack product could not be loaded.' );
            }

            try {
                $order = wc_create_order(
                    [
                        'customer_id' => $user_id,
                        'created_via' => 'koopo',
                    ]
                );
            } catch ( Exception $e ) {
                return new WP_Error( 'koopo_order_create_failed', $e->getMessage() );
            }

            if ( is_wp_error( $order ) ) {
                return $order;
            }

            try {
                $order->add_product( $product, 1 );
                $order->calculate_totals();
                $order->payment_complete();

                if ( 'completed' !== $order->get_status() ) {
                    $order->update_status( 'completed', 'Koopo auto-assigned starter pack.' );
                }
            } catch ( Exception $e ) {
                return new WP_Error( 'koopo_order_complete_failed', $e->getMessage() );
            }

            return $order;
        }

        private function activate_pack_for_vendor( $user_id, $pack_id, $order ) {
            $activated = false;

            if ( class_exists( '\WeDevs\DokanPro\Modules\Subscription\SubscriptionPack' ) ) {
                try {
                    $subscription = new \WeDevs\DokanPro\Modules\Subscription\SubscriptionPack( $pack_id, $user_id );
                    $subscription->activate_subscription( $order );
                    $activated = absint( get_user_meta( $user_id, 'product_package_id', true ) ) === $pack_id;
                } catch ( Throwable $e ) {
                    $activated = false;
                }
            }

            if ( $activated ) {
                return;
            }

            $pack_validity             = absint( get_post_meta( $pack_id, '_pack_validity', true ) );
            $admin_commission          = get_post_meta( $pack_id, '_subscription_product_admin_commission', true );
            $admin_additional_fee      = get_post_meta( $pack_id, '_subscription_product_admin_additional_fee', true );
            $admin_commission_type     = get_post_meta( $pack_id, '_subscription_product_admin_commission_type', true );
            $category_admin_commission = get_post_meta( $pack_id, '_subscription_product_admin_category_based_commission', true );

            update_user_meta( $user_id, 'product_package_id', $pack_id );
            update_user_meta( $user_id, 'product_order_id', $order->get_id() );
            update_user_meta( $user_id, 'product_no_with_pack', get_post_meta( $pack_id, '_no_of_product', true ) );
            update_user_meta( $user_id, 'product_pack_startdate', current_time( 'Y-m-d H:i:s' ) );

            if ( $pack_validity > 0 ) {
                update_user_meta(
                    $user_id,
                    'product_pack_enddate',
                    wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $pack_validity * DAY_IN_SECONDS ) )
                );
            } else {
                update_user_meta( $user_id, 'product_pack_enddate', 'unlimited' );
            }

            update_user_meta( $user_id, 'can_post_product', 1 );
            update_user_meta( $user_id, '_customer_recurring_subscription', '' );

            if ( function_exists( 'dokan' ) ) {
                $vendor = dokan()->vendor->get( $user_id );
                if ( $vendor && method_exists( $vendor, 'save_commission_settings' ) ) {
                    $vendor->save_commission_settings(
                        [
                            'percentage'           => $admin_commission,
                            'type'                 => $admin_commission_type,
                            'flat'                 => $admin_additional_fee,
                            'category_commissions' => $category_admin_commission,
                        ]
                    );
                }
            }

            do_action( 'dokan_vendor_purchased_subscription', $user_id );
        }
    }
}

add_action(
    'plugins_loaded',
    function () {
        if ( ! function_exists( 'wc_create_order' ) ) {
            return;
        }

        if ( ! function_exists( 'dokan' ) && ! function_exists( 'dokan_is_user_seller' ) ) {
            return;
        }

        $assigner = new Koopo_Dokan_Vendor_Starter_Pack_Assigner();
        $assigner->init();
    },
    30
);
