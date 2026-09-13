# AJAX cart and product quick view — Koopo 2.61

Implemented and verified in the mounted local WordPress site on 2026-09-07.
Deployed to beta on 2026-09-07 using the corrected SSH endpoint supplied by the user
(`u426708099@187.124.245.1`, port 65002). Beta reports active Koopo 2.61. Production
was not changed. The older `beta.koopo` alias failed authentication; its configuration
was not modified.

Beta bootstrap backup: `/home/u426708099/backups/koopo-ajax-cart-2.61-20260907/koopo.php`.
Only the cart module, its two assets, bootstrap lines, and version were deployed.
Remote PHP lint passed and LiteSpeed caches were purged. Both public asset bodies
exactly match local SHA-256 hashes. A fresh guest cart confirmed addition of product
8496 and eight cart fragments in 1.24 seconds; the quick-view form returned in 1.32
seconds. The beta product page serves the 2.61 script and product-form markers.
The scan of up to 100 published products per variable/grouped type found no eligible
products, so option-selection acceptance on beta remains pending a suitable product.

## Behavior

- Simple products add directly from classic WooCommerce loops and full product forms.
- Variable and grouped loop buttons open an accessible native dialog containing the
  product image, title, price, short description, and WooCommerce's own product form.
- Variable selections use WooCommerce's variation matching, including its threshold
  for fetching large variation sets. Selected wildcard attributes reach the cart.
- Grouped child option links open the child view. Back to group restores the existing
  DOM and entered quantities. Child variations are added separately; group submission
  adds the selected simple children through the standard WooCommerce grouped handler.
- Buttons show a spinner, then a green check and Added after server confirmation.
  Errors retain selections. Successfully added group rows reset even on partial failure.
- Cart fragments and the BuddyBoss header update from the add response. The standard
  `added_to_cart` event includes its jQuery button for Elementor compatibility.
- Escape/backdrop dismissal, focus return, a native modal focus boundary, live status
  announcements, reduced motion, and a single-column mobile layout are included.

External products, subscriptions/vendor packs, creator support, Koopo tickets, and
custom design flows are excluded. Interactive WooCommerce Blocks retain their own
handlers. Custom controls that bypass WooCommerce's loop/form hooks need an explicit
integration; this module does not intercept arbitrary links containing add-to-cart.

## Performance and integrity

- Approximately 4 KB gzip of custom JS/CSS (raw approximately 14 KB); existing WooCommerce
  dependencies are additional. Assets enqueue when a supported control renders.
  Variation dependencies are required only for variable/grouped controls.
- No new polling, page-load cart fetch, speculative modal fetch, or third-party framework.
  One modal read on opening and one mutation per submission. Existing WooCommerce/theme
  refresh behavior remains; the module does not request a redundant fragment refresh.
- Per-document queue and per-button pending guard. An ambiguous failed mutation stops
  further additions in that document and asks the shopper to inspect the cart. It is
  never automatically retried. This is not cross-tab/server-side idempotency.
- Endpoint is a public WooCommerce customer-cart operation. It requires POST plus a
  custom same-origin request header, rejects a mismatched Origin/cross-site fetch, and
  does not embed expiring nonces in cached catalog HTML. Do not enable cross-origin
  credentialed CORS for these endpoints.
- Published supported products only; password protection respected. Group child IDs
  are checked against actual membership. Quantity/options validation, extension hooks,
  stock enforcement, and cart sessions are delegated to WooCommerce's form handlers.
- Client never sends `add-to-cart`. The native early handler is detached only on these
  two endpoint requests, preventing accidental mutation before endpoint validation.
- WooCommerce/plugin cart hooks still determine backend cost. Local sampled endpoint
  requests were roughly 0.9–2.4 seconds across test runs; no production latency claim.

## Verification

PHP lint, JavaScript syntax, and diff whitespace validation passed.
Fourteen local HTTP assertions passed, including required header, origin rejection,
duplicate native field rejection, simple addition/fragments, negative quantities,
missing/invalid variations, wildcard attribute preservation, grouped partial success,
empty groups, blank unselected group quantities, foreign child rejection, and both
option-form renders.

Guest browser acceptance passed for simple loop addition, variable modal selection
and addition, grouped quantities and addition, preserving quantities across child
quick view/back, Escape/focus return, and the Elementor full product form. A 390px
viewport was visually checked. The initial Elementor event-argument defect was fixed
and its product-page success was reverified.

Remaining acceptance: authenticated customer browser, full real-catalog/vendor-store
coverage, dynamically inserted builder controls, and beta variable/grouped browser
acceptance. Beta asset parity and one guest-cart timing sample are verified above.
No checkout, payment, or postage purchase was submitted.
Temporary local product/page fixtures are removed after acceptance.

Reproduce locally (replace SELLER_ID with an enabled local Dokan seller):

```sh
docker exec wordpress_koopo wp eval-file /var/www/html/wp-content/plugins/koopo/tests/ajax-cart-fixtures.php SELLER_ID --allow-root > /tmp/koopo-cart-fixtures.json
python3 tests/ajax-cart-http.py /tmp/koopo-cart-fixtures.json
docker exec wordpress_koopo wp eval-file /var/www/html/wp-content/plugins/koopo/tests/ajax-cart-fixtures.php cleanup --allow-root
```

## Rollout and rollback

Deploy only `includes/commerce/class-koopo-ajax-cart.php`, its two assets, the bootstrap
require/boot lines, and a version update after comparing the live `koopo.php`. The local
working tree contains unrelated creator-support work; do not blindly sync the plugin.
Back up these paths first, lint on beta, purge page/asset caches, verify served hashes,
and repeat guest/authenticated browser acceptance before production.

Disable with `wp option update koopo_ajax_cart_enabled no` and purge cached pages.
Re-enable with `wp option update koopo_ajax_cart_enabled yes`. With the module disabled,
WooCommerce's normal controls/handlers remain. Filters `koopo_ajax_cart_enabled` and
`koopo_ajax_cart_product_supported` provide integration-level overrides.
