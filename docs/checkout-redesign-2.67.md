# Checkout and Koopo Love 2.67

Updates the existing WooCommerce classic checkout and shared creator-support modal from the supplied references.

- Desktop summary now sticks below the site header. BuddyBoss `#page` overflow created an unintended scrolling ancestor; checkout-only `overflow:clip` resolves it. A viewport-height limit with internal scrolling keeps long summaries reachable. Mobile retains its fixed bottom action.
- Moves the native `.place-order` agreement/nonce block into the summary, above the native final submit button. It is shown on Review. AJAX replaces the moved block and retains the checked terms value; inputs serialize once. The now-empty main payment panel is hidden on Review, while its gateway DOM is retained.
- Show Love modal has a creator avatar/name and available bio, cream heart callout, navy text, white rounded panel, rectangular amount options with selected state, Custom amount focus, and yellow primary action. Stripe appearance uses matching navy/gold. Existing eligibility, signed recipient, payment flow, public-live-message rules, and verified success/receipt handling remain in place. No unsupported wallet logos, verified badges, or instant-payment promises were added. Ticket and appointment images remain design references for future scoped work.

## Validation

- Physical and digital Woo-rendered fixtures passed the checkout regression suite, including terms placement, checked-state retention across AJAX, unique nonce/submit/terms, serialization, native payment DOM retention, changed totals, step validation, and error routing.
- Existing creator-support flow tests passed using a mocked Stripe/API provider: amount validation, server failure/retry, step transitions, session reuse, changed amount, and verified-result presentation. This is not real payment acceptance.
- Local installed WordPress reports 2.67. Browser scrolling showed the summary pinned at y=100 after the document scrolled 720px. Desktop review and 390px mobile review previews show terms inside the summary; mobile has no horizontal overflow. Show Love was visually checked on desktop and at 390px, including selected amount styling.
- PHP/JS syntax and git whitespace checks passed.

## Beta release

Deployed eight scoped files on September 12, 2026: checkout controller/CSS/JS, form template, creator-support renderer/CSS/JS, and bootstrap version. The existing beta support files matched the local pre-edit baseline; beta bootstrap was patched only from 2.66 to 2.67. Other dirty local work was excluded.

Backup and staged release: `/home/u426708099/backups/koopo-checkout-love-2.67-20260912/`. Roll back by restoring `before.tar.gz` into the plugin directory and purging caches.

Beta active plugin version is 2.67. WordPress and LiteSpeed caches were purged. LiteSpeed emitted the existing Elementor Pro api-on-null CLI warning but reported successful purge. All four publicly served CSS/JS bodies match source by SHA-256. A real beta browser guest cart containing Blue Light Glasses renders checkout 2.67; native AJAX refresh completed and retains one nonce and terms within the summary. At scrollY=720 the summary remains at top=100 and bottom=682 in the 720px viewport.

No order/payment was submitted. Provider settings and production were not changed. Actual gateway/3DS/wallet and physical-device acceptance remain separate from these presentation checks.

The deployed Show Love modal was also opened on beta's public `Live from Koopo8` creator page. A real Elementor button-style conflict was corrected with scoped modal selectors. Desktop and 390px beta screenshots confirm white unselected presets, a gold selected preset, round close button, and the new creator/callout presentation. The mobile panel measured 364px client/scroll width (no internal horizontal overflow). No sign-in or payment submission was performed.
