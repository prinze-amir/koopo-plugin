# Koopo Dokan Subscription Upgrade Module

## Overview

This module provides a modal-based subscription upgrade flow for Dokan Pro vendors. When a vendor wants to upgrade their subscription pack to a higher-priced tier, they can:

1. Click the **"Upgrade Subscription"** button on their subscription dashboard
2. Select a higher-priced pack (only upgrades are shown)
3. View the proration breakdown showing remaining days credit
4. Enter payment details via Stripe card form (in-modal checkout)
5. Complete payment and have subscription updated automatically

## Features

✅ **Proration Discount** — Calculates unused days from current subscription and applies credit to first payment
✅ **Modal Checkout** — No redirects; all checkout happens inside the modal
✅ **Dokan Stripe Integration** — Uses `WeDevs\DokanPro\Modules\Stripe\Helper` for key management
✅ **Native Subscription Flow** — Uses Dokan's `woocommerce_order_status_changed` hook to activate subscriptions
✅ **React-Aware** — MutationObserver detects React component rendering and injects button at correct time
✅ **Security** — All calculations verified server-side; payment processed through Stripe

## Files

- **koopo-dokan-upgrade.php** — Bootstrap file (loads main class)
- **class-koopo-dokan-upgrade.php** — Main backend class with REST endpoints
- **assets/koopo-upgrade.js** — Frontend modal and logic
- **assets/koopo-upgrade.css** — Modal styling

## REST API Endpoints

### POST `/koopo/v1/upgrade/calc`

Calculate proration credit and first payment amount.

**Request:**
```json
{
  "vendor_id": 123,
  "new_pack_id": 456
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "current_pack_id": 123,
    "current_price": "49.99",
    "new_pack_id": 456,
    "new_price": "99.99",
    "days_remaining": 15,
    "days_total": 30,
    "credit": "24.99",
    "first_payment": "75.00"
  }
}
```

### POST `/koopo/v1/upgrade/pay`

Create Stripe PaymentIntent and order record.

**Request:**
```json
{
  "vendor_id": 123,
  "new_pack_id": 456,
  "payment_method_id": "pm_123abc" (optional)
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "client_secret": "pi_123_secret_456",
    "payment_intent_id": "pi_123",
    "order_id": 789,
    "first_payment": 75.00
  }
}
```

### POST `/koopo/v1/upgrade/finalize`

Complete payment and activate subscription (called after Stripe payment succeeds).

**Request:**
```json
{
  "vendor_id": 123,
  "order_id": 789,
  "payment_intent_id": "pi_123"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Subscription upgrade completed successfully",
  "data": {
    "order_id": 789,
    "status": "completed"
  }
}
```

## How Proration Works

1. **Get current subscription dates** from Dokan user meta:
   - `product_pack_startdate` — When current subscription started
   - `product_pack_enddate` — When current subscription expires

2. **Calculate days:**
   ```
   days_remaining = (enddate_timestamp - current_time) / 86400
   days_total = (enddate_timestamp - startdate_timestamp) / 86400
   ```

3. **Calculate credit:**
   ```
   credit = (days_remaining / days_total) × current_pack_price
   ```

4. **First payment:**
   ```
   first_payment = new_pack_price - credit
   ```

5. **Renewal pricing:** After first payment, vendor pays full `new_pack_price` on next renewal (via Stripe subscription)

## Configuration

### Stripe Keys

The module automatically retrieves Stripe keys from:

1. **Dokan Stripe Helper** (preferred):
   ```php
   WeDevs\DokanPro\Modules\Stripe\Helper::get_secret_key()
   WeDevs\DokanPro\Modules\Stripe\Helper::get_publishable_key()
   ```

2. **WooCommerce Stripe settings** (fallback):
   - Option: `woocommerce_stripe_settings`
   - Keys: `secret_key`, `test_secret_key`, `publishable_key`, `test_publishable_key`

### Test vs. Live Mode

Keys are automatically selected based on Dokan/WooCommerce test mode setting.

## JavaScript API

### Modal Control

```javascript
// Open modal
$('#koopo-upgrade-top-btn').click();

// Close modal
$('#koopo-upgrade-close').click();
```

### Global Data

Frontend has access to:
```javascript
KoopoUpgradeData = {
  restUrl: '/wp-json/koopo/v1/upgrade',
  nonce: 'wp_rest_nonce',
  currentUserId: 123,
  stripePublishableKey: 'pk_test_...',
  dokanPacksEndpoint: '/wp-json/dokan/v1/vendor-subscription/packages',
  dokanSubscriptionEndpoint: '/wp-json/dokan/v1/vendor-subscription'
}
```

## Order Metadata

When an upgrade order is created, the following metadata is stored:

- `_is_upgrade_purchase` — Set to 'yes'
- `_upgrade_proration_discount` — Credit amount applied
- `_payment_intent_id` — Stripe PaymentIntent ID

## Hooks & Filters

### Actions triggered

- `woocommerce_order_status_changed` — When order moves to 'completed' after payment
- Dokan's subscription activation flows are called automatically

### How to extend

To run custom logic after upgrade:

```php
add_action( 'woocommerce_order_status_changed', function( $order_id, $old_status, $new_status ) {
    if ( $new_status !== 'completed' ) return;
    $order = wc_get_order( $order_id );
    if ( $order->get_meta( '_is_upgrade_purchase' ) === 'yes' ) {
        // Your custom logic here
        $credit = $order->get_meta( '_upgrade_proration_discount' );
        // ...
    }
}, 10, 3 );
```

## Testing

### Test Stripe Cards

- **Success:** `4242 4242 4242 4242`
- **3DS Required:** `4000 0025 0000 3155`
- **Failed:** `4000 0000 0000 0002`

### Verification Steps

1. Navigate to vendor subscription dashboard
2. Look for **"⬆ Upgrade Subscription"** button
3. Click button → modal opens
4. Select higher-priced pack
5. Click "Next Step"
6. Review breakdown showing proration credit
7. Enter test card details
8. Click "Pay Now"
9. Confirm 3DS if prompted
10. Wait for processing → page reloads
11. Verify new subscription shows on dashboard

### Troubleshooting

**Button doesn't appear:**
- Check browser console for JS errors
- Verify MutationObserver is running (takes up to 30 seconds)
- Check that user is logged in and on subscriptions page

**Payment fails:**
- Verify Stripe keys are configured in Dokan/WooCommerce settings
- Check Stripe test mode matches settings
- Review browser console for Stripe.js errors

**Subscription doesn't update:**
- Check WordPress error log for PHP errors
- Verify `woocommerce_order_status_changed` hook fires
- Confirm Dokan's `SubscriptionPack` class is available

## Security Considerations

- All proration calculations verified server-side before charging
- PaymentIntent status verified before completing upgrade
- Order vendor ID matched against current user
- Uses WordPress nonce for REST endpoints
- Stripe handles PCI compliance for card data

## Performance Notes

- MutationObserver is stopped after button is injected
- REST endpoints use WP caching where appropriate
- Stripe keys cached in memory during request
- Modal assets enqueued only for logged-in users

## Future Enhancements

- [ ] Support for subscription downgrades with credits
- [ ] Email confirmation with proration details
- [ ] Admin dashboard showing upgrade history
- [ ] Custom proration rules per vendor/plan
- [ ] Support for multiple payment methods (PayPal, etc.)
