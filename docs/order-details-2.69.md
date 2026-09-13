# Customer order details 2.69

## Scope and design

Redesigns My Account → Orders → View using the four supplied references. Black text and links, gold status markers/actions, white panels, sticky desktop summary and stacked mobile layout. The Orders list is unchanged. Account navigation is replaced by an Orders breadcrumb only while viewing details.

Classifies each line item from saved Koopo metadata, preserving mixed orders rather than forcing the whole order into one specialized layout:

- Products: grouped by seller, product thumbnails, native variations/item metadata, quantities and refunds; shipping address and existing shipment extensions.
- Support: creator identity/profile, public matching content context, verified-payment state and relevant public-chat metadata. No fabricated public visibility, notification, or receipt-delivery claims.
- Tickets: saved event/date/attendee context and issuance flag; retains installed ticket plugin View/Print/Download routes. No invented seating, QR codes, wallet or transfer actions.
- Bookings: current appointment status, dates/timezone, fulfillment mode and provider, linked to existing Manage Booking page. Legacy metadata-linked bookings require matching customer ownership; linked booking orders must be this order or its child. No new reschedule/cancel side effects.

The timeline shows recorded Woo order-created, paid and completed dates, distinct from appointment or shipment status. Totals come directly from Woo, including fees, tax, discounts and refunds. Sensitive billing details are expandable. Customer notes and customer-visible updates remain available.

## Integration boundaries

New controller: `includes/commerce/orders/class-koopo-order-details.php`; stylesheet alongside it. Overrides `myaccount/view-order.php` (Woo 10.6.0) and scopes `order/order-details.php` (Woo 10.9.0) to the active account order. Thank-you, emails and nested child-order detail templates retain native routing. Standard order-view permission checks run before rendering, and are repeated in the new templates.

Retains Woo order/item hooks, downloads, purchase notes, customer-visible updates, account actions and shipping/ticket extension output. Replaces the old single-booking injected summary only during this render, then restores its hook. Uses the installed Woo core two-column order-details-item template because the site's theme override uses four columns; its filters, refunded quantity logic and item metadata hooks remain intact. No commerce/provider configuration changes.

## Validation

PHP syntax and git whitespace checks passed. Local read-only fixture renders existing orders without writes. Model suite covers all four classifications, mixed-order fallback, unscoped template routing and absent-product seller resolution. DOM suite verifies four rendered layouts, totals, two-column item rows, ticket links, booking management link, absence of duplicate booking injection and unauthorized order denial.

Browser inspected local product, booking, support and ticket templates. Desktop summary stays visible; 390px support/ticket layouts have no horizontal overflow and links measure rgb(0,0,0). These are isolated template previews, not an authenticated full-theme beta browser session.

Beta active Koopo is 2.69. Server-side rendering as each order's customer passed for existing support/product/booking orders among the latest 150 orders. No ticket order was found in that beta sample; ticket rendering was validated locally. Beta browser Orders route correctly shows login when signed out. Authenticated beta UI, physical-device behavior and real fulfillment/payment actions remain untested. No orders, payments, bookings or tickets were created or modified.

## Deployment / rollback

Five scoped files deployed September 12, 2026; beta bootstrap patched from 2.68 with only new module boot and version lines. Other dirty local code excluded. Backup and release: `/home/u426708099/backups/koopo-orders-2.69-20260912/`.

`before.tar.gz` contains prior existing files; `new.txt` lists newly installed files. Restoring the old bootstrap disables the module; restore other previous files from the archive as necessary, remove only the paths listed in new.txt, then purge caches.

WordPress and LiteSpeed caches purged. Existing Elementor api-on-null CLI warnings appeared during purge, which reported success. Local and beta publicly served CSS match source SHA-256 `ff68d3c9da246b0aacb136d01e59e1165a574a52beb5e57c8004b635921dc41f`. Production unchanged.
