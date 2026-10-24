# Order details 2.72

Bookable line-item metadata is now filtered through WooCommerce's formatted-meta hook as well as the legacy hidden-meta hook. This removes appointment implementation fields from older and current booking orders, including `serviceDurationMins`, `scheduledAt`, `providerName`, `placeName` and related identifiers, while retaining the purpose-built appointment presentation.
