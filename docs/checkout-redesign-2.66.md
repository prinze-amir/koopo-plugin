# Koopo checkout 2.66

Implements the supplied desktop and mobile references as Delivery → Payment → Review on the assigned classic WooCommerce checkout. Uses the native checkout form, field definitions, shipping packages, totals, payment renderer, terms, nonces, and submission handlers.

## Scope

- Active only on the assigned page containing `[woocommerce_checkout]`. Cart, Checkout Blocks, pay-for-order, order confirmation, creator support endpoints, and embedded checkouts do not receive the form override.
- Native fields retain their IDs/names, country rules, registration settings, billing/shipping distinction, and extension hooks.
- Uses the original payment DOM. Moving between steps never clones or recreates secure fields. Native Woo AJAX can still refresh payment fragments as before.
- Delivery shows existing rate choices per package. No new provider request path, carrier configuration, or payment processing is introduced.
- Review shows contact/address/package/payment-method summaries and moves the existing item rows above notes. Does not read card numbers or claim a masked card suffix that the gateway has not exposed.
- Gateway validation and authentication run on the native final submission. Continue to Review does not submit or charge a payment, and is not proof that secure card fields are complete.
- Mobile uses stacked sections, a collapsible order summary, and a fixed action area with safe-area padding. Desktop uses a sticky summary column and grouped review details.
- Without JavaScript the original fields, shipping choices, terms and native submit remain available as a single form.

## Ownership / rollback

`includes/commerce/checkout/` owns the controller, styles, and step behavior. Only `form-checkout.php` (upstream 9.4.0) and `review-order.php` (upstream 11.0.0) are overridden; compare these with future WooCommerce template updates.

Disable just this presentation with the `koopo_checkout_enabled` filter returning false, or the `koopo_checkout_enabled` option set to `no`. No checkout-page content rewrite or core/theme vendor-file edit is required.

Existing unrelated workspace changes were preserved. Koopo source version was 2.65 before this change and is now 2.66.

## Validation, September 12, 2026

- Local installed WordPress reports Koopo 2.66, BuddyBoss child theme, and the classic checkout shortcode on page 1380 (`/checkout-koopo/`).
- Browser: real local product added to an isolated guest cart; the new checkout renders product image, seller name, contact/details fields and per-package delivery region. Empty submission stays on Delivery and focuses the first invalid field. Native order-review AJAX still renders the custom item presentation.
- Local browser responsive inspection: desktop and 390px; isolated rendered-template previews: Payment and Review at desktop/390px/375px. No horizontal overflow in the measured narrow review viewport. These are browser viewport checks, not physical iPhone/Safari acceptance.
- PHP syntax validation on controller, both templates, fixture, and plugin entrypoint; JavaScript syntax validation.
- Regression suite uses real Woo/Koopo template output with in-memory physical/digital cart fixtures and a fake payment renderer. Covers required fields, package selection, retained payment DOM, one nonce/submit control, serialized native inputs, fragment replacement, changed totals, missing payment methods, error recovery, invalidated steps, summary disclosure, and removal of stale shipping choices.

### Reproduce the isolated regression suite

Install `jsdom@26` and `jquery@3` into a temporary directory; do not add them as production plugin dependencies.

```sh
npm install --prefix /tmp/koopo-checkout-qa --no-audit --no-fund jsdom@26 jquery@3
docker exec wordpress_koopo wp eval-file /var/www/html/wp-content/plugins/koopo/tests/checkout-render-fixture.php physical --allow-root > /tmp/koopo-checkout-qa/physical.html
docker exec wordpress_koopo wp eval-file /var/www/html/wp-content/plugins/koopo/tests/checkout-render-fixture.php digital --allow-root > /tmp/koopo-checkout-qa/digital.html
NODE_PATH=/tmp/koopo-checkout-qa/node_modules node tests/checkout-multistep.cjs /tmp/koopo-checkout-qa/physical.html
NODE_PATH=/tmp/koopo-checkout-qa/node_modules node tests/checkout-multistep.cjs /tmp/koopo-checkout-qa/digital.html
```

The PHP fixture refuses non-local hosts. It creates no persisted products/orders and makes no shipping or payment provider calls. The fake renderer does not prove actual gateway acceptance.

## Outstanding release acceptance

Local managed shipping quotes were disabled during the initial check. The user clarified that local uses sandbox despite appearing as live; the displayed mode value is not evidence of live payment processing. Provider settings were left unchanged. Managed shipping, connected-seller settlement, card/3DS and wallet success/failure, saved-card flows, and physical-device keyboard behavior still require controlled acceptance. No payment was submitted. Beta deployment is recorded below; production was not changed.


## Beta deployment — September 12, 2026

Deployed checkout release 2.66 to `https://beta.koopoonline.com/checkout-koopo/` at the user's request. Beta's previous Koopo baseline was 2.64.1. This was a narrow checkout deployment: the three checkout module files, two templates, and three bootstrap lines plus plugin version. Other local changes were excluded.

- Backup/staging: `/home/u426708099/backups/koopo-checkout-2.66-20260912-1626/`. `koopo.before.php` is the original beta bootstrap; the two checkout directories did not previously exist. Restoring that bootstrap disables the new module.
- Verified active Koopo 2.66 and loaded `Koopo_Checkout`, WooCommerce 11.1.0, BuddyBoss child theme, assigned classic shortcode, and enabled checkout presentation.
- Remote PHP syntax checks passed. WordPress object cache and LiteSpeed caches were purged. LiteSpeed's CLI run emitted Elementor Pro `api`-on-null warnings but reported purge success; subsequent WordPress and HTTP checkout checks succeeded.
- Public CSS and JS bodies exactly match local release files. CSS SHA-256: `db6d3646af43efa7d039b3197961874bdafbfce135e3a39718bf243e42292f01`; JS SHA-256: `cfa0bf0f8d271ddb5709705d2bc2524b99d6d75e90d9137c2cbb14f26501badb`.
- A fresh HTTP guest session added existing product 8496. Checkout returned HTTP 200 with the real product, one native checkout form, all three step controls, native submit, and both 2.66 assets.
- Native beta `update_order_review` AJAX returned success with two fragments: the custom item presentation and native payment/submit markup. No checkout submission or payment was made.
- The in-app browser navigation timed out. Its generated error page was then blocked by the browser tool's data-URL policy. Beta visual/device acceptance remains unverified; the deployment and HTTP response checks above succeeded independently.
- Production and provider configuration were unchanged. No order/payment was submitted.
