# Order details 2.70

Adds membership, site plan and generic recurring-subscription layouts to account order details.

- Membership classification requires a single Koopo Video tier associated with the product and the same owner. Channel merchandise alone does not qualify. Uses recorded channel attribution first, then an unambiguous tier link, then the membership plugin's verified legacy default-channel mapping. No old order metadata is rewritten.
- Published tier benefits are labelled current benefits, not historical purchase guarantees. Missing or ambiguous tier mappings retain the generic subscription/product layout.
- Linked Woo subscriptions must belong to the order customer and contain the same product/variation. Displays recorded subscription status, recurring total, available start/trial/next-payment/end dates, and native management link. Purchase completion is separate from subscription status. No status or billing mutation occurs.
- Dokan product_pack uses site identity and plan management, with recorded allowance/expiry where available. Current user-plan metadata is shown only when product_order_id matches this order. Vendor name hook is removed/restored for individual membership and site-plan rows only.
- Native totals, refunds, related subscriptions, permissions and all extension hooks remain.

Validation: existing local membership 6891 and site plan 6924 rendered read-only through native My Account entry point. Membership channel and subscription management links verified. Desktop and 390px mobile template previews inspected, black links and no horizontal overflow. Original four types and unauthorized view regression checks passed. Beta full-theme authenticated browser acceptance remains separate from CLI rendering.

Release: four PHP files; fresh beta bootstrap patched only from 2.69 to 2.70. Backup /home/u426708099/backups/koopo-orders-2.70-20260912/before.tar.gz. Cache flush and LiteSpeed purge succeeded; existing Elementor Pro CLI warnings appeared during purge. No payment settings or order data changed.

Beta verification: active controller/plugin 2.70. Read-only native renders passed for support/product/booking among the most recent 300 orders; membership/site-plan/ticket absent in that sample. Public CSS SHA256 matched source. New-type authenticated beta browser validation is unverified.
