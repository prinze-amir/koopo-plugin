<?php
/** Customer order presentation. Commerce and fulfillment remain owned by their plugins. */
defined( 'ABSPATH' ) || exit;
final class Koopo_Order_Details {
    const VERSION = '2.73';
    public static $active_order;
    public static function boot() {
        require_once __DIR__ . "/class-koopo-order-details-rest.php";
        Koopo_Order_Details_REST::boot();
        add_filter( 'woocommerce_locate_template', array( __CLASS__, 'template' ), 60, 3 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 100 );
        add_filter( 'body_class', static function ( $classes ) {
            if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) $classes[] = 'koopo-order-details-page';
            return $classes;
        } );
    }
    public static function assets() {
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) {
            wp_enqueue_style( 'koopo-order-details', plugins_url( 'order-details.css', __FILE__ ), array(), self::VERSION );
        }
    }
    public static function template( $path, $name, $template_path ) {
        if ( ! apply_filters( 'koopo_order_details_enabled', true ) ) return $path;
        if ( 'myaccount/view-order.php' === $name ) return KOOPO_PATH . 'templates/woocommerce/myaccount/view-order.php';
        if ( self::$active_order && 'order/order-details.php' === $name ) return KOOPO_PATH . 'templates/woocommerce/order/order-details.php';
        return $path;
    }
    public static function kind( $item ) {
        if ( $item->get_meta( '_koopo_creator_support' ) ) return 'support';
        if ( $item->get_meta( '_koopo_ticket_type_id' ) ) return 'ticket';
        if ( self::booking_id( $item ) ) return 'booking';
        $product = $item->get_product();
        if ( $product && $product->is_type( 'product_pack' ) ) return 'site_plan';
        if ( self::membership_tier( $item ) ) return 'membership';
        if ( $product && $product->is_type( array( 'subscription', 'subscription_variation', 'variable-subscription' ) ) ) return 'subscription';
        return 'product';
    }
    public static function type( $order ) {
        $types = array_unique( array_map( array( __CLASS__, 'kind' ), array_values( $order->get_items() ) ) );
        return count( $types ) === 1 ? reset( $types ) : 'mixed';
    }
    public static function title( $type ) {
        return array( 'membership' => __( 'Membership Details', 'koopo' ), 'site_plan' => __( 'Site Plan Details', 'koopo' ), 'subscription' => __( 'Subscription Details', 'koopo' ), 'support' => __( 'Koopo Love Details', 'koopo' ), 'ticket' => __( 'Ticket Order Details', 'koopo' ), 'booking' => __( 'Booking Details', 'koopo' ) )[ $type ] ?? __( 'Order Details', 'koopo' );
    }
    public static function seller( $item, $order ) {
        if ( 'site_plan' === self::kind( $item ) ) return 0;
        foreach ( array( '_koopo_creator_support_creator_id', '_koopo_payee_user_id', '_dokan_vendor_id', '_vendor_id' ) as $key ) {
            if ( $item->get_meta( $key ) ) return absint( $item->get_meta( $key ) );
        }
        $vendor = absint( $order->get_meta( '_dokan_vendor_id' ) );
        return $vendor ?: ( $item->get_product_id() ? absint( get_post_field( 'post_author', $item->get_product_id() ) ) : 0 );
    }
    public static function fact( $label, $value ) {
        if ( '' === (string) $value ) return;
        echo '<div class="kod-fact"><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>';
    }
    public static function action( $url, $label, $primary = false ) {
        if ( ! $url ) return;
        echo '<a class="kod-button' . ( $primary ? ' kod-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span aria-hidden="true"> →</span></a>';
    }
    public static function booking_id( $item ) {
        foreach ( array( '_koopo_booking_id', 'bookingId', 'booking_id', 'appointmentId', 'appointment_id' ) as $key ) {
            $value = absint( $item->get_meta( $key ) );
            if ( $value ) return $value;
        }
        return 0;
    }
    public static function booking_meta_keys( $hidden ) {
        return array_merge( (array) $hidden, array( 'appointmentId', 'bookingId', 'serviceId', 'serviceName', 'serviceTitle', 'servicePrice', 'serviceCurrency', 'serviceDurationMins', 'placedAt', 'providerId', 'providerName', 'resourceId', 'placeId', 'placeName', 'wcProductId', 'serviceTaxStatus', 'serviceVirtual', 'scheduledAt', 'startAt', '_koopo_booking_id' ) );
    }
    public static function booking_formatted_meta( $formatted, $item ) {
        if ( 'booking' !== self::kind( $item ) ) return $formatted;
        $hidden = self::booking_meta_keys( array() );
        return array_filter( $formatted, static function ( $meta ) use ( $hidden ) { return ! in_array( (string) $meta->key, $hidden, true ); } );
    }
    public static function payment_is_verified( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->is_paid() || ! $order->get_date_paid() ) return false;
        if ( 'dokan_stripe_express' !== $order->get_payment_method() ) return true;
        return str_starts_with( (string) $order->get_meta( '_dokan_stripe_express_payment_intent_id' ), 'pi_' )
            && 'yes' === (string) $order->get_meta( '_dokan_stripe_express_charge_captured' )
            && str_starts_with( (string) $order->get_transaction_id(), 'ch_' );
    }
    /** Resolve only an unambiguous, owner-matched tier; ordinary channel merchandise is not a membership. */
    public static function membership_tier( $item ) {
        $id = $item->get_product_id();
        if ( ! $id || ! class_exists( 'Koopo_Video_Memberships' ) ) return 0;
        static $cache = array();
        if ( isset( $cache[$id] ) ) return $cache[$id];
        $tiers = get_posts( array( 'post_type' => Koopo_Video::TIER_CPT, 'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ), 'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => Koopo_Video_Memberships::TIER_META_PRODUCT_ID, 'meta_value' => $id ) );
        return $cache[$id] = count( $tiers ) === 1 && (int) get_post_field( 'post_author', $tiers[0] ) === (int) get_post_field( 'post_author', $id ) ? (int) $tiers[0] : 0;
    }
    public static function subscription_context( $item, $order ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) return;
        foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
            if ( ! $order->get_customer_id() || (int) $subscription->get_customer_id() !== (int) $order->get_customer_id() ) continue;
            $matches = false;
            foreach ( $subscription->get_items() as $line ) if ( $line->get_product_id() === $item->get_product_id() && $line->get_variation_id() === $item->get_variation_id() ) $matches = true;
            if ( ! $matches ) continue;
            echo '<dl class="kod-facts">';
            self::fact( __( 'Subscription status', 'koopo' ), esc_html( wc_get_order_status_name( $subscription->get_status() ) ) );
            self::fact( __( 'Recurring total', 'koopo' ), $subscription->get_formatted_order_total() );
            foreach ( array( 'start' => 'Started', 'trial_end' => 'Trial ends', 'next_payment' => 'Next payment', 'end' => 'Ends' ) as $date => $label ) {
                $time = $subscription->get_time( $date );
                if ( $time ) self::fact( $label, esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) );
            }
            echo '</dl>';
            self::action( $subscription->get_view_order_url(), __( 'Manage subscription', 'koopo' ), true );
        }
    }
    public static function context( $item, $order ) {
        $kind = self::kind( $item );
        if ( in_array( $kind, array( 'membership', 'site_plan', 'subscription' ), true ) ) {
            if ( 'membership' === $kind ) {
                $tier = self::membership_tier( $item );
                $creator = (int) get_post_field( 'post_author', $tier );
                $channel_id = absint( $item->get_meta( '_koopo_video_channel_id' ) );
                if ( ! $channel_id && class_exists( 'Koopo_Video_Channel_Business' ) ) {
                    $links = Koopo_Video_Channel_Business::channels_for_object( 'membership_tier', $tier, 'membership' );
                    if ( count( $links ) === 1 ) $channel_id = (int) $links[0]['channel_id'];
                    if ( ! $links ) {
                        $legacy_channel = Koopo_Video_Channel_Business::channel_id_for_creator( $creator, false );
                        if ( $legacy_channel && in_array( $tier, Koopo_Video_Memberships::tier_ids_for_channel( $legacy_channel ), true ) ) $channel_id = $legacy_channel;
                    }
                }
                $channel = $channel_id && class_exists( 'Koopo_Video_Channels' ) ? Koopo_Video_Channels::get_channel( $channel_id ) : null;
                if ( $channel && ( (int) $channel['owner_user_id'] !== $creator || 'active' !== $channel['status'] ) ) $channel = null;
                $user = get_userdata( $creator );
                echo '<div class="kod-feature">' . get_avatar( $creator, 80 ) . '<div><span class="kod-eyebrow">' . esc_html__( 'Channel membership', 'koopo' ) . '</span><h3>' . esc_html( $channel ? $channel['name'] : ( $user ? $user->display_name : $item->get_name() ) ) . '</h3>';
                if ( $channel && class_exists( 'Koopo_Video_Frontend_Profile' ) ) self::action( Koopo_Video_Frontend_Profile::channel_url( $channel ), __( 'View channel', 'koopo' ) );
                echo '</div></div><dl class="kod-facts">';
                self::fact( __( 'Membership tier purchased', 'koopo' ), esc_html( $item->get_name() ) );
                if ( $user ) self::fact( __( 'Creator', 'koopo' ), esc_html( $user->display_name ) );
                echo '</dl>';
                if ( 'publish' === get_post_status( $tier ) ) {
                    $benefits = Koopo_Video_Memberships::sanitize_benefits( get_post_meta( $tier, Koopo_Video_Memberships::TIER_META_BENEFITS, true ) );
                    if ( $benefits ) { echo '<h3>' . esc_html__( 'Current membership benefits', 'koopo' ) . '</h3><ul>'; foreach ( $benefits as $benefit ) echo '<li>' . esc_html( $benefit ) . '</li>'; echo '</ul>'; }
                }
            } elseif ( 'site_plan' === $kind ) {
                echo '<span class="kod-eyebrow">' . esc_html__( 'Site subscription plan', 'koopo' ) . '</span><h3>' . esc_html( $item->get_name() ) . '</h3><dl class="kod-facts">';
                self::fact( __( 'Provided by', 'koopo' ), esc_html( get_bloginfo( 'name' ) ) );
                $limit = $order->get_meta( '_no_of_product' );
                if ( '' !== $limit ) self::fact( __( 'Product allowance purchased', 'koopo' ), '-1' === (string) $limit ? __( 'Unlimited', 'koopo' ) : esc_html( $limit ) );
                self::fact( __( 'Recorded plan expiry', 'koopo' ), esc_html( $order->get_meta( '_pack_validity' ) ) );
                $customer = $order->get_customer_id();
                if ( $customer && (int) get_user_meta( $customer, 'product_order_id', true ) === (int) $order->get_id() ) {
                    self::fact( __( 'Current plan expiry', 'koopo' ), esc_html( get_user_meta( $customer, 'product_pack_enddate', true ) ) );
                    self::fact( __( 'Recurring plan status', 'koopo' ), esc_html( get_user_meta( $customer, '_customer_recurring_subscription', true ) ) );
                }
                echo '</dl>';
                if ( function_exists( 'dokan_get_navigation_url' ) ) self::action( dokan_get_navigation_url( 'subscription' ), __( 'Manage site plan', 'koopo' ), true );
            }
            self::subscription_context( $item, $order );
        } elseif ( 'support' === $kind ) {
            $creator_id = absint( $item->get_meta( '_koopo_creator_support_creator_id' ) );
            $creator = get_userdata( $creator_id );
            if ( $creator ) {
                $name = class_exists( 'Koopo_Creator_Support' ) ? Koopo_Creator_Support::instance()->service()->creator_display_name( $creator_id ) : $creator->display_name;
                $url = function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $creator_id ) : get_author_posts_url( $creator_id );
                echo '<div class="kod-feature">' . get_avatar( $creator_id, 80 ) . '<div><span class="kod-eyebrow">' . esc_html__( 'Creator supported', 'koopo' ) . '</span><h3>' . esc_html( $name ) . '</h3>';
                self::action( $url, __( 'View creator', 'koopo' ) );
                echo '</div></div>';
            }
            echo '<dl class="kod-facts">';
            self::fact( __( 'Payment verification', 'koopo' ), 'yes' === $order->get_meta( '_koopo_support_verified' ) ? __( 'Verified', 'koopo' ) : __( 'Not yet verified', 'koopo' ) );
            if ( 'live_paid_chat' === $item->get_meta( '_koopo_creator_support_surface' ) ) {
                $chat = (string) $order->get_meta( '_koopo_support_chat_state' );
                if ( $chat ) self::fact( __( 'Public chat', 'koopo' ), ucwords( str_replace( '_', ' ', $chat ) ) );
            }
            echo '</dl>';
        } elseif ( 'ticket' === $kind ) {
            $event = absint( $item->get_meta( '_koopo_ticket_event_id' ) );
            echo '<div class="kod-feature">';
            if ( $event && is_post_publicly_viewable( $event ) ) {
                echo get_the_post_thumbnail( $event, 'medium', array( 'class' => 'kod-event-image' ) );
                echo '<div><span class="kod-eyebrow">' . esc_html__( 'Event', 'koopo' ) . '</span><h3>' . esc_html( get_the_title( $event ) ) . '</h3>';
                self::action( get_permalink( $event ), __( 'View event', 'koopo' ) ); echo '</div>';
            }
            echo '</div><dl class="kod-facts">';
            self::fact( __( 'Event date', 'koopo' ), esc_html( $item->get_meta( '_koopo_ticket_schedule_label' ) ) );
            self::fact( __( 'Tickets', 'koopo' ), (int) $item->get_quantity() );
            $guests = $item->get_meta( '_koopo_ticket_guests' );
            if ( is_string( $guests ) ) $guests = json_decode( $guests, true );
            if ( is_array( $guests ) ) self::fact( __( 'Attendees', 'koopo' ), esc_html( implode( ', ', array_filter( array_map( static function ( $guest ) { return is_array( $guest ) ? sanitize_text_field( $guest['name'] ?? '' ) : ''; }, $guests ) ) ) ) );
            self::fact( __( 'Issuance', 'koopo' ), $item->get_meta( '_koopo_tickets_issued' ) ? __( 'Issued — open your tickets for current validity', 'koopo' ) : __( 'Not yet issued', 'koopo' ) );
            echo '</dl>';
        } elseif ( 'booking' === $kind ) {
            $id = self::booking_id( $item );
            $booking = class_exists( '\Koopo_Appointments\Bookings' ) ? \Koopo_Appointments\Bookings::get_booking( $id ) : null;
            // Do not expose another customer's booking through stale or malformed item metadata.
            if ( $booking && (int) $booking->wc_order_id !== (int) $order->get_id() ) {
                $booking_order = wc_get_order( (int) $booking->wc_order_id );
                $legacy_owned = ! (int) $booking->wc_order_id && $order->get_user_id() && (int) $booking->customer_id === (int) $order->get_user_id();
                if ( ! $legacy_owned && ( ! $booking_order || (int) $booking_order->get_parent_id() !== (int) $order->get_id() ) ) $booking = null;
            }
            $service_id = $booking ? (int) $booking->service_id : absint( $item->get_meta( 'serviceId' ) );
            $provider_id = $booking ? (int) ( $booking->provider_id ?? 0 ) : absint( $item->get_meta( 'providerId' ) );
            $subject = $booking ? ( (int) $booking->listing_id ?: $provider_id ) : $provider_id;
            if ( $subject && is_post_publicly_viewable( $subject ) ) {
                echo '<div class="kod-feature">' . get_the_post_thumbnail( $subject, 'medium' ) . '<div><span class="kod-eyebrow">' . esc_html__( 'Service provider', 'koopo' ) . '</span><h3>' . esc_html( get_the_title( $subject ) ) . '</h3>';
                self::action( get_permalink( $subject ), __( 'View provider', 'koopo' ) ); echo '</div></div>';
            }
            $service_name = $service_id ? get_the_title( $service_id ) : '';
            $service_name = $service_name ?: (string) ( $item->get_meta( 'serviceTitle' ) ?: $item->get_meta( 'serviceName' ) ?: $item->get_name() );
            $start = $booking ? (string) $booking->start_datetime : (string) ( $item->get_meta( 'scheduledAt' ) ?: $item->get_meta( 'startAt' ) );
            $end = $booking ? (string) $booking->end_datetime : '';
            $timezone = $booking ? (string) $booking->timezone : (string) $item->get_meta( 'timezone' );
            $provider_name = $booking ? '' : (string) $item->get_meta( 'providerName' );
            echo '<div class="kod-booking-heading"><span class="kod-eyebrow">' . esc_html__( 'Appointment', 'koopo' ) . '</span><h3>' . esc_html( $service_name ) . '</h3>';
            if ( $provider_name ) echo '<p>' . esc_html( sprintf( __( 'With %s', 'koopo' ), $provider_name ) ) . '</p>';
            echo '</div><dl class="kod-facts kod-booking-facts">';
            self::fact( __( 'Booking', 'koopo' ), '#' . $id );
            if ( $booking ) {
                self::fact( __( 'Appointment status', 'koopo' ), esc_html( ucwords( str_replace( '_', ' ', $booking->status ) ) ) );
                foreach ( array( 'start_datetime' => __( 'Starts', 'koopo' ), 'end_datetime' => __( 'Ends', 'koopo' ) ) as $field => $label ) {
                    $value = class_exists( '\Koopo_Appointments\Date_Formatter' ) ? \Koopo_Appointments\Date_Formatter::format( $booking->$field, $booking->timezone ?? '', 'full' ) : $booking->$field;
                    self::fact( $label, esc_html( $value ) );
                }
                self::fact( __( 'Timezone', 'koopo' ), esc_html( $booking->timezone ?? '' ) );
                $mode = (string) ( $booking->fulfillment_mode ?? 'at_location' );
                self::fact( __( 'Appointment type', 'koopo' ), esc_html( array( 'virtual' => __( 'Online appointment', 'koopo' ), 'mobile' => __( 'Provider comes to you', 'koopo' ), 'at_location' => __( 'At a location', 'koopo' ) )[ $mode ] ?? '' ) );
                if ( 'mobile' === $mode ) self::fact( __( 'Service address', 'koopo' ), esc_html( implode( ', ', array_filter( array( $booking->service_address_1 ?? '', $booking->service_address_2 ?? '', $booking->service_city ?? '', $booking->service_region ?? '', $booking->service_postal_code ?? '' ) ) ) ) );
            } else {
                $duration = absint( $item->get_meta( 'serviceDurationMins' ) );
                $formatted = $start ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) ) : '';
                self::fact( __( 'Service time', 'koopo' ), esc_html( $formatted ) );
                self::fact( __( 'Duration', 'koopo' ), $duration ? esc_html( sprintf( _n( '%d minute', '%d minutes', $duration, 'koopo' ), $duration ) ) : '' );
                self::fact( __( 'Timezone', 'koopo' ), esc_html( $timezone ) );
            }
            echo '</dl>';
            if ( $booking && 'expired' === (string) $booking->status && self::payment_is_verified( $order ) ) {
                echo '<div class="kod-alert kod-alert--error"><strong>' . esc_html__( 'Payment received, but appointment confirmation failed.', 'koopo' ) . '</strong><p>' . esc_html__( 'Do not book or pay again. This order needs support review so the paid appointment can be restored.', 'koopo' ) . '</p></div>';
            }
            if ( $booking && class_exists( '\Koopo_Appointments\MyAccount' ) ) self::action( \Koopo_Appointments\MyAccount::manage_appointment_url( $id ), __( 'Manage booking', 'koopo' ), true );
        }
    }
}
