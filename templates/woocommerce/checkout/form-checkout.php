<?php
/** Koopo layout; preserves the classic form contract and hooks. Upstream: 9.4.0. */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_before_checkout_form', $checkout );
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
    echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
    return;
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout kc-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php esc_attr_e( 'Checkout', 'koopo' ); ?>" data-needs-shipping="<?php echo WC()->cart->needs_shipping() ? '1' : '0'; ?>" data-step="delivery">
    <input type="hidden" name="koopo_checkout" value="1">
    <header class="kc-header">
        <div><h1 tabindex="-1"><?php esc_html_e( 'Checkout', 'koopo' ); ?></h1><p class="kc-intro"><?php esc_html_e( 'Let’s get your order on its way.', 'koopo' ); ?></p></div>
        <nav class="kc-stepper kc-js-only" aria-label="<?php esc_attr_e( 'Checkout progress', 'koopo' ); ?>">
            <?php foreach ( array( 'delivery' => __( 'Delivery', 'koopo' ), 'payment' => __( 'Payment', 'koopo' ), 'review' => __( 'Review', 'koopo' ) ) as $step => $label ) : ?>
                <button type="button" data-kc-step="<?php echo esc_attr( $step ); ?>" <?php echo 'delivery' === $step ? 'aria-current="step"' : 'disabled'; ?>><span class="kc-step-dot" aria-hidden="true"></span><span><?php echo esc_html( $label ); ?></span></button>
            <?php endforeach; ?>
        </nav>
    </header>
    <p class="kc-status kc-js-only" role="status" aria-live="polite" tabindex="-1"></p>
    <div class="kc-layout">
        <div class="kc-main">
            <section class="kc-contact kc-panel kc-js-only" data-kc-panel="delivery">
                <div class="kc-section-heading"><?php echo Koopo_Checkout::icon( 'contact' ); ?><div><h2><?php esc_html_e( 'Contact Information', 'koopo' ); ?></h2><p><?php esc_html_e( 'We’ll use this to keep you updated about your order.', 'koopo' ); ?></p></div></div>
                <div class="kc-contact-fields"></div>
            </section>
            <section class="kc-panel kc-details" data-kc-panel="delivery">
                <div class="kc-section-heading"><?php echo Koopo_Checkout::icon( 'address' ); ?><div><h2><?php esc_html_e( 'Your Details', 'koopo' ); ?></h2><p><?php esc_html_e( 'Confirm your billing and delivery information.', 'koopo' ); ?></p></div></div>
                <?php if ( $checkout->get_checkout_fields() ) : ?>
                    <?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
                    <div id="customer_details">
                        <?php do_action( 'woocommerce_checkout_billing' ); ?>
                        <?php do_action( 'woocommerce_checkout_shipping' ); ?>
                    </div>
                    <?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
                <?php endif; ?>
            </section>
            <?php if ( WC()->cart->needs_shipping() ) : ?>
                <section class="kc-panel kc-delivery kc-js-only" data-kc-panel="delivery">
                    <div class="kc-section-heading"><?php echo Koopo_Checkout::icon( 'delivery' ); ?><div><h2><?php esc_html_e( 'Delivery Method', 'koopo' ); ?></h2><p><?php esc_html_e( 'Choose an available option for each shipment.', 'koopo' ); ?></p></div></div>
                    <div class="kc-confirmation-slot"></div><table class="kc-shipping-table"><tbody></tbody></table>
                </section>
            <?php endif; ?>
            <section class="kc-panel kc-address-preview kc-js-only" data-kc-panel="payment">
                <?php echo Koopo_Checkout::icon( 'address' ); ?><div><h2><?php esc_html_e( 'Your Details', 'koopo' ); ?></h2><p data-kc-summary="address"></p></div><button type="button" class="kc-edit" data-kc-edit="delivery"><?php esc_html_e( 'Edit', 'koopo' ); ?></button>
            </section>
            <section class="kc-review kc-js-only" data-kc-panel="review">
                <div class="kc-review-details">
                <?php foreach ( array( 'contact' => __( 'Contact Information', 'koopo' ), 'address' => __( 'Delivery Address', 'koopo' ), 'delivery' => __( 'Delivery Method', 'koopo' ), 'payment' => __( 'Payment Method', 'koopo' ) ) as $key => $label ) : ?>
                    <div class="kc-panel kc-review-row" <?php echo in_array( $key, array( 'address', 'delivery' ), true ) && ! WC()->cart->needs_shipping() ? 'hidden' : ''; ?>><?php echo Koopo_Checkout::icon( $key ); ?><div><h2><?php echo esc_html( $label ); ?></h2><p data-kc-summary="<?php echo esc_attr( $key ); ?>"></p></div><button type="button" class="kc-edit" data-kc-edit="<?php echo 'payment' === $key ? 'payment' : 'delivery'; ?>"><?php esc_html_e( 'Edit', 'koopo' ); ?></button></div>
                <?php endforeach; ?>
                </div>
                <section class="kc-panel kc-review-items"><h2><?php esc_html_e( 'Items in Your Order', 'koopo' ); ?></h2><table class="shop_table kc-review-items-table"></table></section>
                <section class="kc-panel kc-notes-slot"><h2><?php esc_html_e( 'Order Notes', 'koopo' ); ?></h2></section>
            </section>
            <section class="kc-panel kc-payment-slot kc-js-only">
                <div class="kc-section-heading" data-kc-payment-heading><?php echo Koopo_Checkout::icon( 'payment' ); ?><div><h2><?php esc_html_e( 'Payment Method', 'koopo' ); ?></h2><p><?php esc_html_e( 'Choose how you’d like to pay for your order.', 'koopo' ); ?></p></div></div>
            </section>
        </div>
        <aside class="kc-sidebar" aria-label="<?php esc_attr_e( 'Order summary', 'koopo' ); ?>">
            <div class="kc-panel kc-summary">
                <?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
                <h2 id="order_review_heading"><?php esc_html_e( 'Order Summary', 'koopo' ); ?></h2>
                <button type="button" class="kc-summary-toggle kc-js-only" aria-expanded="true" aria-controls="order_review"><span><?php esc_html_e( 'Order Summary', 'koopo' ); ?></span><span class="kc-summary-total"></span><span class="kc-chevron" aria-hidden="true">⌃</span></button>
                <p class="kc-summary-count"><?php echo esc_html( sprintf( _n( '%s item in your order', '%s items in your order', WC()->cart->get_cart_contents_count(), 'koopo' ), WC()->cart->get_cart_contents_count() ) ); ?></p>
                <?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
                <div id="order_review" class="woocommerce-checkout-review-order"><?php do_action( 'woocommerce_checkout_order_review' ); ?></div>
                <?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
                <div class="kc-terms-slot kc-js-only"></div>
                <div class="kc-actions kc-js-only"><button type="button" class="kc-next"><?php esc_html_e( 'Continue to Payment', 'koopo' ); ?><span aria-hidden="true"> →</span></button></div>
                <button type="button" class="kc-back kc-js-only" data-kc-back><?php esc_html_e( 'Back', 'koopo' ); ?></button>
                <a class="kc-cart-link" href="<?php echo esc_url( wc_get_cart_url() ); ?>">← <?php esc_html_e( 'Return to Cart', 'koopo' ); ?></a>
            </div>
        </aside>
    </div>
</form>
<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
