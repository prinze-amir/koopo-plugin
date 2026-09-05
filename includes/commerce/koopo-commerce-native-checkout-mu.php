<?php
/**
 * Plugin Name: Koopo Commerce Native Checkout Bridge
 * Description: REST bridge for mobile native commerce checkout through Dokan Stripe Express.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WeDevs\Dokan\Exceptions\DokanException;
use WeDevs\DokanPro\Modules\StripeExpress\Processors\Payment;
use WeDevs\DokanPro\Modules\StripeExpress\Api\PaymentIntent as StripePaymentIntent;
use WeDevs\DokanPro\Modules\StripeExpress\Support\Helper;
use WeDevs\DokanPro\Modules\StripeExpress\Support\OrderMeta;
use WeDevs\DokanPro\Modules\StripeExpress\Support\Settings;

if ( ! class_exists( 'Koopo_MU_Commerce_Native_Checkout' ) ) {
	class Koopo_MU_Commerce_Native_Checkout {
		private $namespace = 'koopo/v1';

		public function __construct() {
			add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		}

		public function register_routes() {
			register_rest_route(
				$this->namespace,
				'/commerce/checkout/native/create-intent',
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'rest_create_intent' ],
					'permission_callback' => [ $this, 'can_access_checkout' ],
				]
			);

			register_rest_route(
				$this->namespace,
				'/commerce/checkout/native/finalize',
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'rest_finalize' ],
					'permission_callback' => [ $this, 'can_access_checkout' ],
				]
			);

			register_rest_route(
				$this->namespace,
				'/commerce/checkout/native/cancel',
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'rest_cancel' ],
					'permission_callback' => [ $this, 'can_access_checkout' ],
				]
			);
		}

		public function can_access_checkout() {
			return is_user_logged_in();
		}

		private function error_response( $message, $status = 400, $extra = [] ) {
			return new \WP_REST_Response(
				array_merge(
					[
						'success' => false,
						'message' => $message,
					],
					$extra
				),
				$status
			);
		}

		private function get_publishable_key() {
			if ( class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Support\\Settings' ) ) {
				$key = Settings::get_publishable_key();
				if ( ! empty( $key ) ) {
					return $key;
				}
			}

			$stripe_settings = get_option( 'woocommerce_stripe_settings', [] );
			if ( ! empty( $stripe_settings['testmode'] ) && 'yes' === $stripe_settings['testmode'] ) {
				return isset( $stripe_settings['test_publishable_key'] ) ? $stripe_settings['test_publishable_key'] : '';
			}

			return isset( $stripe_settings['publishable_key'] ) ? $stripe_settings['publishable_key'] : '';
		}

		private function get_order_from_request( \WP_REST_Request $request ) {
			$order_id = absint( $request->get_param( 'orderId' ) ?: $request->get_param( 'order_id' ) );
			if ( ! $order_id ) {
				return new \WP_Error( 'koopo_missing_order_id', 'Missing order ID.' );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new \WP_Error( 'koopo_invalid_order', 'Order not found.' );
			}

			$current_user_id = get_current_user_id();
			if ( intval( $order->get_customer_id() ) === intval( $current_user_id ) ) {
				return $order;
			}

			$requested_customer_id = absint( $request->get_param( 'customerId' ) ?: $request->get_param( 'customer_id' ) );
			if (
				$requested_customer_id &&
				intval( $order->get_customer_id() ) === intval( $requested_customer_id ) &&
				current_user_can( 'manage_woocommerce' )
			) {
				return $order;
			}

			return new \WP_Error( 'koopo_invalid_order_owner', 'Order does not belong to the current user.' );
		}

		private function validate_vendor_payout_readiness( $order ) {
			if ( ! function_exists( 'koopo_payouts_vendor_can_accept_payments' ) ) {
				return true;
			}
			$reject_unresolved = function_exists( 'koopo_payouts_checkout_enforcement_has_targets' )
				&& koopo_payouts_checkout_enforcement_has_targets();

			$order_ids = [ $order->get_id() ];
			if ( function_exists( 'dokan_get_suborder_ids_by' ) ) {
				$suborder_ids = array_values( array_filter( array_map( 'absint', (array) dokan_get_suborder_ids_by( $order->get_id() ) ) ) );
				if ( ! empty( $suborder_ids ) ) {
					$order_ids = $suborder_ids;
				}
			}

			foreach ( $order_ids as $order_id ) {
				$vendor_id = function_exists( 'dokan_get_seller_id_by_order' ) ? absint( dokan_get_seller_id_by_order( $order_id ) ) : 0;
				if ( $vendor_id <= 0 && $reject_unresolved ) {
					return new \WP_Error( 'koopo_vendor_payouts_owner_unresolved', 'A seller in this order could not be verified for payment.' );
				}
				if ( $vendor_id > 0 && ! koopo_payouts_vendor_can_accept_payments( $vendor_id, [ 'order_id' => absint( $order_id ), 'channel' => 'native_checkout' ] ) ) {
					return new \WP_Error( 'koopo_vendor_payouts_not_ready', 'A seller in this order cannot accept payments yet.' );
				}
			}

			return true;
		}

		public function rest_create_intent( \WP_REST_Request $request ) {
			if ( ! class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Processors\\Payment' ) ) {
				return $this->error_response( 'Dokan Stripe Express is not available.', 500 );
			}

			$order = $this->get_order_from_request( $request );
			if ( is_wp_error( $order ) ) {
				return $this->error_response( $order->get_error_message(), 403 );
			}

			$payout_readiness = $this->validate_vendor_payout_readiness( $order );
			if ( is_wp_error( $payout_readiness ) ) {
				return $this->error_response( $payout_readiness->get_error_message(), 409, [ 'code' => $payout_readiness->get_error_code() ] );
			}

			$publishable_key = $this->get_publishable_key();
			if ( empty( $publishable_key ) ) {
				return $this->error_response( 'Stripe publishable key is not configured.', 500 );
			}

			try {
				$order->set_payment_method( Helper::get_gateway_id() );
				$order->set_payment_method_title( Helper::get_gateway_title() );
				$order->save();

				$intent_id = OrderMeta::get_payment_intent( $order );
				if ( ! empty( $intent_id ) ) {
					$intent = Payment::update_intent( $intent_id, $order, [], false, false, 'card' );
				} else {
					$intent = Payment::create_intent(
						$order,
						[
							'payment_method_types' => [ 'card' ],
						]
					);
					$intent = Payment::update_intent( $intent->id, $order, [], false, false, 'card' );
				}

				$intent_id     = ! empty( $intent->id ) ? $intent->id : OrderMeta::get_payment_intent( $order );
				$client_secret = ! empty( $intent->client_secret ) ? $intent->client_secret : '';
				if ( empty( $intent_id ) || empty( $client_secret ) ) {
					return $this->error_response( 'Stripe intent payload was incomplete.', 500 );
				}

				return rest_ensure_response(
					[
						'success' => true,
						'data'    => [
							'orderId'                    => $order->get_id(),
							'paymentIntentId'            => $intent_id,
							'clientSecret'               => $client_secret,
							'publishableKey'             => $publishable_key,
							'merchantDisplayName'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
							'allowsDelayedPaymentMethods' => false,
						],
					]
				);
			} catch ( DokanException $error ) {
				return $this->error_response( $error->getMessage(), 502 );
			} catch ( \Throwable $error ) {
				return $this->error_response( $error->getMessage(), 500 );
			}
		}

			public function rest_finalize( \WP_REST_Request $request ) {
			if ( ! class_exists( '\\WeDevs\\DokanPro\\Modules\\StripeExpress\\Processors\\Payment' ) ) {
				return $this->error_response( 'Dokan Stripe Express is not available.', 500 );
			}

			$order = $this->get_order_from_request( $request );
			if ( is_wp_error( $order ) ) {
				return $this->error_response( $order->get_error_message(), 403 );
			}

			$payment_intent_id = sanitize_text_field(
				$request->get_param( 'paymentIntentId' ) ?: $request->get_param( 'payment_intent_id' ) ?: OrderMeta::get_payment_intent( $order )
			);

			if ( empty( $payment_intent_id ) ) {
				return $this->error_response( 'Missing Stripe payment intent ID.', 400 );
			}

			try {
				if ( $order->has_status( [ 'pending', 'failed' ] ) ) {
					Payment::process_confirmed_intent( $order, $payment_intent_id, false );
				}

				clean_post_cache( $order->get_id() );
				$order = wc_get_order( $order->get_id() );

				return rest_ensure_response(
					[
						'success' => true,
						'data'    => [
							'orderId'         => $order->get_id(),
							'orderStatus'     => $order->get_status(),
							'paymentIntentId' => $payment_intent_id,
						],
					]
				);
			} catch ( DokanException $error ) {
				return $this->error_response( $error->getMessage(), 502 );
				} catch ( \Throwable $error ) {
					return $this->error_response( $error->getMessage(), 500 );
				}
			}

			public function rest_cancel( \WP_REST_Request $request ) {
				$order = $this->get_order_from_request( $request );
				if ( is_wp_error( $order ) ) {
					return $this->error_response( $order->get_error_message(), 403 );
				}
				if ( $order->is_paid() || $order->has_status( [ 'processing', 'completed', 'refunded' ] ) ) {
					return $this->error_response( 'A paid order cannot be abandoned.', 409 );
				}

				$intent_id = sanitize_text_field(
					$request->get_param( 'paymentIntentId' ) ?: $request->get_param( 'payment_intent_id' ) ?: OrderMeta::get_payment_intent( $order )
				);
				if ( $intent_id ) {
					try {
						$intent = StripePaymentIntent::get( $intent_id );
						if ( $intent && ! in_array( (string) $intent->status, [ 'canceled', 'succeeded' ], true ) ) {
							$intent->cancel();
						}
						if ( $intent && 'succeeded' === (string) $intent->status ) {
							return $this->error_response( 'Payment already succeeded and the order cannot be abandoned.', 409 );
						}
					} catch ( \Throwable $error ) {
						return $this->error_response( $error->getMessage(), 502 );
					}
				}

				$order_id   = (int) $order->get_id();
				$booking_ids = array_values( array_filter( array_map( 'absint', (array) $order->get_meta( '_koopo_booking_ids', true ) ) ) );
				foreach ( $order->get_items() as $item ) {
					$booking_id = absint( $item->get_meta( '_koopo_booking_id', true ) ?: $item->get_meta( 'bookingId', true ) );
					if ( $booking_id ) {
						$booking_ids[] = $booking_id;
					}
				}
				$booking_ids = array_values( array_unique( $booking_ids ) );
				do_action( 'koopo_mobile_native_checkout_abandoned', $order_id, $booking_ids );

				if ( function_exists( 'dokan_get_suborder_ids_by' ) ) {
					foreach ( (array) dokan_get_suborder_ids_by( $order_id ) as $suborder_id ) {
						$suborder = wc_get_order( absint( $suborder_id ) );
						if ( $suborder && ! $suborder->is_paid() ) {
							$suborder->delete( true );
						}
					}
				}
				$order->delete( true );

				return rest_ensure_response(
					[
						'success'         => true,
						'orderId'         => $order_id,
						'paymentIntentId' => $intent_id,
					]
				);
			}
			}
		}

new Koopo_MU_Commerce_Native_Checkout();
