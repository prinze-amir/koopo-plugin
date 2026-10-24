# Order details 2.71

Bookable-product order details now recognize both the current `_koopo_booking_id` metadata and the appointment line-item aliases used by older booking-product orders. Internal appointment metadata is hidden from the customer-facing WooCommerce row and replaced with a focused appointment section containing the service name, provider, booking number and status, date/time, duration, timezone, service mode and safe booking action where available.

No booking, payment or order records are changed. Native WooCommerce totals, extension hooks and fulfillment controls remain intact.
