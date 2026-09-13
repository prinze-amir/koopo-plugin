# Creator support: same-page checkout plan

Status: implemented locally and deployed to beta on 2026-09-07. Provider payment and two-viewer live-chat acceptance remain pending.

## Implementation acceptance

- Shared same-page Stripe payment modal deployed across creator-support surfaces. Beta browser proved sandbox Payment Element loads without checkout navigation; no payment was submitted.
- Dedicated virtual support orders complete after verified full payment. Support-only earnings bypass fulfillment evidence and release delays; existing holds, provider verification, and Stripe settlement constraints still apply.
- Paid chat is persisted once per support item after verification, with reconciliation retries and refund removal.
- Sixteen local isolated acceptance checks passed, covering virtual orders, duplicate requests, paid completion, single chat publication, immediate payout eligibility, mixed-order exclusion, and ownership/context validation. PHP/JS syntax and diff checks passed. Served beta JS/CSS hashes match local.
- Release: Koopo 2.59, Koopo Live 0.13.15, local Payouts 0.12.1, beta Payouts 0.11.3. Beta received only the narrow support eligibility patch; the separate local fee-policy release was excluded. Backup: ~/backups/support-inline-20260907/before.tar.gz on beta.
- Beta is in Dokan Sandbox mode (sandbox_mode=yes, testmode=no). The older test-mode flag alone does not identify the active environment. Payout execution remains in its existing shadow configuration.
- Cancellation endpoint and automated expired-session cleanup described below are follow-up work; closing the modal retains an unpaid session for retry.

## Outcome

Every creator-support button opens the same payment modal on the current page:
channel, single-video viewer, blog article/profile, and live viewer. The viewer
chooses an amount and pays through Stripe Elements without visiting WooCommerce
checkout. WooCommerce orders, Dokan seller ownership/commission, and Koopo Payouts
accounting remain the authoritative backend.

Live support adds an optional public message and publishes a highlighted chat
entry only after payment is verified on the server. Ordinary support is private
and does not publish a chat message.

## Pre-implementation baseline (historical)

- Beta and installed local: Koopo Live 0.13.14; Koopo Video 2.6.6.
- Beta creator-support PHP/JS/CSS and Live plugin source match installed local
  files; the single-video watch template also matches. No support deployment
  delta was found during this check.
- Both sites enable Dokan Stripe Express. Local legacy Stripe Connect is disabled.
- Local Express uses test mode. Beta Express uses its separate Sandbox mode,
  matching beta Koopo Payouts. Beta remains in shadow mode with transfers and
  withdrawals disabled. The initial inference of live mode from testmode=no
  was incorrect; sandbox_mode takes precedence.
- Beta Koopo Payouts is 0.11.2; local is 0.12.0. Differences include a separate
  seller transaction-fee/schema release, not missing paid-chat UI files. Review
  that release independently before treating beta as accounting parity.
- Existing native create-intent and finalize routes are registered on beta.
  They demonstrate the Dokan Express integration seam; reusing them requires
  checking their runtime source and ownership contracts, not copying endpoints
  unchanged.

## Why the previous chat test failed

Local orders 7614 and 7615 used the legacy `dokan-stripe-connect` gateway. Their
paid-chat line items retained the correct recipient, stream, amount, and message.
They had completed status and a paid date, but their generic WooCommerce
transaction-ID field was empty. The charge was stored in seller-specific Dokan
metadata instead. PaidChat::publish required the generic transaction ID and
returned before inserting a chat message.

Switching to Express aligns with the intended gateway, but this was also a real
integration/test gap: publication silently failed and there was no reconciliation
or user-visible publication status. Existing synthetic acceptance populated the
transaction ID and did not test the gateway that was actually selected.

Koopo Payouts' observer and earnings projection target `dokan_stripe_express`;
no earnings rows for those two legacy-gateway orders were found locally. Do not
infer provider transfer success or failure from those missing projection rows.

## Viewer flow

1. Click Donate/Support. Open an accessible modal naming the actual recipient.
   Resolve eligibility and amount rules from the server. Keep video/live playback
   running and preserve the current page and scroll position.
2. Show preset amounts, a custom amount, currency, and the exact total. Channel,
   video, and blog support use the same form. Live support additionally shows an
   optional message, its length limit, and a preview of the public chat entry.
3. If signed out, offer sign-in and return to this same support context. V1
   requires an authenticated account; do not silently create guest paid messages.
4. Display Stripe Payment Element inside the modal. Use saved profile details
   when available; ask only for billing details required by Stripe or the payment
   method. Support products are virtual: no shipping address, delivery selection,
   cart summary, unrelated products, or coupons in this flow.
5. The action reads “Support [amount]”. Disable duplicate submission while payment
   is in progress. Stripe handles card data and authentication. Use card-first
   methods and `redirect: if_required`; bank authentication may still require an
   external step, with a return URL that resumes this exact session.
6. Show “Confirming payment…” until the server verifies payment. For regular
   support, then show a receipt link and thank-you message in the modal.
7. For live support, distinguish “Payment received” from “Message posted”. Keep a
   retryable “Posting your message…” state if delivery is delayed. Never ask the
   viewer to pay again merely because chat delivery has failed.
8. Close restores focus to the support button. On a decline, retain amount/message
   and allow retry using the same session. Closing is not a refund and must not
   cancel a payment that has succeeded or is still being reconciled.

## Shared support session and backend

Introduce a support-specific service under `koopo/includes/creator-support`,
shared by every surface. Suggested API contracts:

| Operation | Responsibility |
| --- | --- |
| GET /creator-support/context | Resolve creator, eligible product, currency, limits, source context, live chat eligibility, and public-message disclosure. |
| POST /creator-support/sessions | Validate context, persist an idempotent support session and dedicated pending Woo order, calculate total, create/reuse its Express PaymentIntent. |
| GET /creator-support/sessions/{id} | Return owner-authorized payment and chat-delivery states plus receipt data; exclude provider/internal secrets. |
| POST /creator-support/sessions/{id}/confirm | Verify the already-bound PaymentIntent server-side and invoke the same finalization service used by the webhook. Never trust a browser “success” flag. |
| POST /creator-support/sessions/{id}/cancel | For explicitly abandoned, still-unpaid sessions only; verify server-bound intent state. A routine modal close can instead leave the session resumable until expiry. |

Session record: opaque public ID, viewer ID, creator/vendor IDs, approved support
product ID, source module/surface/context, amount in currency minor units,
currency, message/public consent when relevant, Woo order and canonical item IDs,
server-bound intent ID, creation/expiry times, payment state, publication state,
and redacted diagnostic reason. Do not persist card details or client secrets in
logs. Issue client secrets only to the session owner over an authenticated request.

Create one dedicated virtual support order, independent of the customer's shopping
cart. Reject physical products and unrelated product IDs. Creator and vendor must
be derived from trusted content/channel/stream records and validated product
ownership; do not trust submitted recipient IDs or totals. Store the source and
message directly in durable order/session metadata rather than a checkout URL
and transient/cart handoff. Revalidate support eligibility before creating payment.

Use a server-side session idempotency key and provider idempotency key. Bind the
PaymentIntent to exactly that order, amount, currency, customer, and environment.
Do not accept arbitrary intent IDs on finalize/cancel; the existing native bridge
accepts request-supplied IDs and needs a binding review before reuse here.

## Dokan and Koopo Payouts

Use Dokan Stripe Express payment creation/finalization so its seller allocation,
commission, gateway fees, and completion events feed the existing Koopo Payouts
observer. Extract a shared service from the native checkout bridge if appropriate;
do not make the browser orchestrate accounting or calculate connected-account
transfers.

- Validate the seller's Express account and payment readiness on the server.
- Use the configured Dokan/Koopo fee policy and freeze the resulting order-owned
  accounting snapshot. Do not hard-code a new support commission in JavaScript.
- Show the supporter their total. Keep seller gross, commission, applicable Koopo
  fees, Stripe fees, and net in the seller ledger/receipt as appropriate.
- Decide how non-shipping support earnings become eligible for settlement under
  existing payout policy; never wait for fictitious shipment or delivery proof.
- Confirm exactly one owner of transfer execution. Do not combine an automatic
  Dokan transfer with a second custom transfer for the same support order.
- Keep existing shadow/transfer/withdrawal switches unchanged during UI work.
  Payment acceptance, ledger reconciliation, and actual payouts are separate test
  milestones. A successful payment does not prove a successful payout.
- Reconcile refunds/disputes through the same order/ledger path, with an explicit
  paid-chat visibility policy. Proposed default: remove the paid highlight after
  full reversal; record the reason, and avoid duplicate or reappearing entries.

## Reliable live chat publication

Treat paid-message delivery as fulfillment with durable state, not a best-effort
payment hook. Use one shared publisher triggered by verified Express completion,
webhook processing, and bounded background reconciliation.

Verify actual captured/succeeded payment evidence, including environment,
order/intent ownership, amount and currency. Reject SetupIntent-only success,
authorization-only status, pending/failed payments, and browser assertions.
Retain a generic Woo transaction ID as useful evidence, not the only possible
proof. V1 accepts Express only; a legacy gateway requires a deliberate adapter.

Use the canonical parent order item/support-session ID for uniqueness across
parent/suborder callbacks. Maintain a persisted publication status and retry
failed database/network delivery. Never remove or recreate a moderated entry on
retry. Chat amount comes from verified order/payment data.

Preserve chat bans and content rules. Validate before taking payment and again at
publication. If policy changes during payment, expose “Payment received; message
withheld by chat rules” instead of silently dropping it. If the stream ends during
payment, record the message in that stream's history with a clear late-arrival
state. Existing viewer and studio polling must independently recover server-written
paid messages even when newer ordinary messages arrive over websockets.

## Implementation order

1. Verify beta gateway/environment alignment and pin the payout/fee release used
   for acceptance. Confirm deployment provenance of the runtime native bridge.
2. Add durable support sessions, order/intent binding, publication state, diagnostics,
   and idempotency. Add real Express event-shape tests before changing the UI.
3. Build the shared same-page payment modal for channel, video, and blog support.
4. Add live message composition, publication feedback, viewer/studio delivery,
   and replay/refund behavior.
5. Deploy the bounded feature release to local and beta with backups, versioning,
   schema checks, cache purge, and served-asset verification. Do not enable new
   payout execution as an incidental deployment step.
6. Perform user-driven Stripe test-mode acceptance on beta. Remove redirect-based
   support entry points only when every supported surface passes. Do not silently
   fall back to the old checkout page if inline payment fails.

## Acceptance gates

- Channel, single video, blog, and live support remain on the same page; changing
  recipients never reuses another creator's payment session.
- Signed-out return, inaccessible/disabled creator, self-support, mobile keyboard,
  keyboard focus, modal close/reopen, and bank-authentication return work.
- No shipping form; no unrelated cart items; server-authoritative currency/total.
- Double clicks, declines, retries, browser refresh, duplicate and out-of-order
  webhooks, payment while modal is closed, and stream ending produce one charge
  and at most one paid-chat entry.
- Successful Express payment produces the correct Woo order, seller attribution,
  commission/fees, Koopo earnings projection, and receipt. Test capture separately
  from mere authorization or saved-card setup.
- Paid chat appears for payer, another viewer, and host, survives reload/history,
  and cannot be skipped by a newer websocket message. Simulated delivery failure
  recovers without charging again.
- Changed chat policy and moderation have explicit outcomes. Full/partial refunds
  and repeated reversal events reconcile accounting and display consistently.
- Beta runtime settings and provider evidence prove sandbox use before automated
  payment tests; no live-mode charge, transfer, or withdrawal is part of this plan.

References: Stripe Payment Element and `confirmPayment` with `redirect: if_required`:
https://docs.stripe.com/payments/existing-customers?locale=en-GB&platform=web&ui=elements
