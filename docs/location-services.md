# Shared Koopo location services

The module in `includes/location` belongs to Koopo core. It has no appointments dependency and installs no additional plugin. Shipping postal address validation remains in Koopo Shipping/Pitney Bowes.

## Configuration

Use **Koopo → Location Services** for provider mode, routing order, encrypted keys, daily/monthly caps, usage meters, public OSM permission and a daily per-user/guest-IP request cap. Counters cover all callers and operations using that provider.

A daily cap of **0 disables the provider**, including explicit provider mode. A monthly cap of 0 means no additional monthly cap. Existing installations preserve their routing order. New installations include LatLng, Acuris, and Mapbox in the order, but all three start with daily caps of 0; add their IDs to an existing routing order when enabling them. Google also defaults to 0. These settings control outgoing requests, rather than asserting a provider's free commercial allowance. Set caps against the actual account, including usage outside Koopo.

Migration reads the existing appointments configuration on first use. Existing encrypted appointments keys remain readable without the appointments plugin; saving/removing a key in the shared settings supersedes the legacy value. Today's old geocoding counter is imported into the shared daily bucket. Historical monthly usage was not recorded by the old router and cannot be reconstructed. Provider keys previously configured only in the mobile gateway must be entered in this shared settings page, and the Google key must enable Geocoding and/or Places API (New) for the chosen operations.

## Operations and adapters

| Adapter | Forward geocoding | Reverse geocoding | Address autocomplete | Location search | Selection details |
| --- | --- | --- | --- | --- | --- |
| GeocodeFarm | Yes | — | — | — | — |
| Geoapify | Yes | Yes | Yes | Address/place autocomplete | Already included in suggestion |
| Acuris Geo | Yes | Yes | Address suggestions | — | Already included in suggestion |
| Google | Yes | Yes | Places Autocomplete | Places Autocomplete | Places Details |
| Public Nominatim | Public addresses only | Public coordinates only | Never | Public submitted search only | Already included in result |

New providers register through `koopo_location_providers`: supply a provider ID with `label`, supported `operations`, provider `attribution`, an explicit `cache_ttl` (default 0), and a callable `request($provider, $operation, $input, $context)` returning normalized `{provider, items}` or `WP_Error`. Configure keys and limits through the shared page. Extend the shared TypeScript provider union when adding a mobile-visible provider. LatLng, Acuris Geo, and Mapbox adapters are implemented below. Tavily is web search, not a location provider. Confirm account allowance, storage rights and attribution when enabling a provider.

## Calling from WordPress

```php
$result = koopo_location_geocode($address, ['purpose' => 'appointment_coverage']);
$result = koopo_location_autocomplete($query, ['country_code' => 'US']);
$result = koopo_location_reverse($latitude, $longitude);
$result = koopo_location_search($query, ['latitude' => 42.3, 'longitude' => -83.0]);
```

## WooCommerce address autocomplete

The Koopo checkout integration adds autocomplete to the standard billing and shipping `address_1` fields on classic checkout and My Account → Addresses. It requests suggestions after four characters with a short debounce, then resolves the selected short-lived token and fills street, unit, city, state, postal code, and country when the provider returns those components. WooCommerce remains responsible for saving account addresses and checkout/order addresses when its existing forms are submitted. The suggestion list itself is transient and is not cached by Koopo.

Guest checkout is supported only for `autocomplete` and selection `details`. Guest requests use a hashed `REMOTE_ADDR` daily counter; logged-in users continue to use their account counter. Both also share the provider caps in Location Services. Other location operations remain authenticated. Address autocomplete routes through enabled providers that support it: Geoapify, Acuris, and Google. Acuris requires a supported country hint and keeps its visible attribution link with the selected address. Public Nominatim is never used for autocomplete or private/customer addresses; Mapbox remains ineligible on generic checkout because its integration requires an approved Mapbox-map workflow. Google Places autocomplete uses a per-field session token and resolves the selected prediction through Place Details; the selected street address is then used for that checkout/account-address transaction.

Address keys: `address_1`, `address_2`, `city`, `region`, `postal_code`, `country`. Forward geocoding returns the existing appointments latitude/longitude result shape. Other operations return normalized `provider`, `items`, and `attribution`. Handle `WP_Error` without treating geocoding as postal validation.

Trusted server code can explicitly mark an address already displayed publicly with `privacy_class => public_profile`. Browser/mobile calls always remain private and cannot opt into public Nominatim. Customer addresses and device coordinates never enter persistent result caches. Only public forward/reverse results from eligible non-Google adapters are cached for 30 days. Existing appointments override hooks remain supported.

Enqueue `koopo-location` on a web form to use `window.KoopoLocation.geocode`, `.autocomplete`, `.reverse`, `.search`, `.details`. The helper uses WordPress REST cookie/nonce authentication. Call autocomplete after three characters, debounce typing and cancel superseded requests with its optional `AbortSignal`. Display returned attribution with suggestions.

## API and mobile

POST `/wp-json/koopo/v1/location/{geocode|reverse|autocomplete|search|details}` is the WordPress authority. Geocode, reverse, and search require sign-in; autocomplete and selection details also allow limited guest-checkout use. `/api/v1/location/{operation}` in the mobile gateway forwards the actual user token without service credentials, caching, or alternate-host replay. The mobile `locationApi` uses these methods. Existing story location routes, the mobile story picker and event location helpers now delegate to this same router; old Google-named helper exports remain compatible.

Autocomplete/search results contain encrypted, user-bound selection tokens valid for 15 minutes. Send the selected `id` to `details` to resolve it. Geoapify already supplies address components and coordinates; selecting it performs no extra provider request. Google requires its original provider's details lookup. Selection details return the native provider ID, not the temporary encrypted token, for published location metadata. Mobile suggestion caches expire after 10 minutes. Persist an intended public location only after selection.

All provider attempts reserve a persistent daily/monthly counter under a database advisory lock before HTTP. Failed outgoing attempts still count because providers may bill them. Unhealthy/rate-limited providers enter cooldown; exhausted providers fall through to the next capable adapter. Daily buckets roll at 00:00 UTC, monthly buckets at the first of the month UTC; no cron reset is required. Quota-storage failure prevents outgoing calls. No provider limit is bypassed by explicit mode.

## Rollout and verification

Deploy Koopo core and the appointments delegation together; deploy the updated stories metadata allowlist and gateway before using the new mobile client. The new settings and authenticated APIs require Koopo core to be active. Local work is not a beta/production deployment or device acceptance. No paid API calls or signup/key-provisioning actions were performed during implementation.

Offline behavioral fixtures:

```sh
php tests/location-router.php
php ../koopo-appointments/scripts/test-geocoding-router.php
```

The fixtures cover legacy credentials/config/counter migration, per-provider daily/monthly caps across operations, explicit-mode enforcement, rollover, fallback, no provider calls when quota storage fails, private/public OSM isolation, Google cache isolation, malformed coordinates and user-bound/expired selection tokens. Gateway fixtures cover caller-token forwarding, no host replay on quota rejection and non-Google story results. A real database concurrency run, configured provider responses and web/device autocomplete acceptance remain release checks.

## Additional providers (October 7, 2026)

All new providers appear in the admin page with encrypted key controls and shared daily/monthly counters. They remain disabled (daily cap 0) until configured. Existing automatic orders are preserved; add `latlng,acuris` where desired. Do not add quotas together as a promise of free capacity for every operation.

| Provider | Adapter operations | Activation notes |
| --- | --- | --- |
| LatLng | Forward, reverse, submitted places search | Uses `X-Api-Key`, `/api`, `/reverse`, `/v1/places/search`. Documented free allowance is 3,000 total calls/day across account keys and all APIs, including tiles used elsewhere. This adapter does not advertise place-name autosuggest as address autocomplete. No persistent result cache. |
| Acuris Geo | Forward, reverse, address suggestions | Uses `X-Acuris-Key`, `/suggest`, `/v1/geocode` JSON and `/v1/reverse`. Requires explicit country in address `country` or `country_code`; skips without an understood ISO-2 hint before spending quota. Supported conversion set: US, CA, GB, DE, AT, CH, FR, ES, BE, NL, DK, FI, SE, NO, IT, JP, SG, PT, AU, NZ, IE. UK routes to `paf.acuris-geo.com`; its smaller separate allowance is not covered by the local global cap. Use a cap safe for the relevant account/countries. Suggestions contain structured address fields and require no second provider call when selected. |
| Mapbox | Forward, reverse, address autocomplete, geographic search | Geocoding v6, `permanent=true`. Paid permanent geocoding is used because consumers retain coordinates; temporary/free results cannot be stored. **Only trusted PHP callers with `mapbox_map => true` are eligible**, indicating a workflow using a Mapbox map. Client requests cannot set this context, so current generic web/mobile REST routes do not use Mapbox. No POI Search Box integration. |

Acuris free commercial usage requires a permanent visible link to https://www.acuris-geo.com wherever results are shown. Use a suitable paid plan for consumers that do not display that link. The checkout keeps this link visible after a suggestion is selected. Typeahead support requires an explicit supported country hint; unsupported countries fall through to existing adapters.

Sources: [LatLng API](https://www.latlng.work/docs), [Acuris OpenAPI](https://api.acuris-geo.com/docs/openapi.yaml), [Acuris pricing](https://www.acuris-geo.com/acuris-pricing/), [Mapbox API/storage/map restrictions](https://docs.mapbox.com/api/search/geocoding/), [Tavily credits](https://docs.tavily.com/documentation/api-credits).

Offline fixtures verify request endpoints/auth headers, country hints/UK routing, Mapbox eligibility and permanent mode, response normalization, zero-extra-request Mapbox selection, invalid coordinates, and shared caps. Real keys, provider responses, account allowances, deployments and device acceptance remain unverified.


## Beta locality update (October 10, 2026; 2.77)

Checkout sends selected country, state code and name, city and postcode as optional autocomplete context. Providers receive a contextual search; US structured suggestions outside the selected state are discarded. The form hides stale results on edits and refreshes after locality changes. Invalid or oversized context is rejected before provider usage. Autocomplete provider timeouts are three seconds; general geocoding retains eight seconds.

Location Services exposes a separate automatic autocomplete order. Beta uses `acuris,geoapify,google`, preserving the existing general geocoding order and all provider counters/caps. Google details requests now use only `id,formattedAddress,location,addressComponents,types` (Essentials). Beta's saved Google key was rejected as invalid; its daily cap is zero pending a valid key and live acceptance. Mapbox remains zero. Google's published global free caps are monthly, with 10,000 Autocomplete Requests and 10,000 Place Details Essentials events per SKU, aggregated across linked projects. Router caps cover only this router, so other consumers require their own account-side controls.

Acceptance: behavioral routing/locality/billing fixtures and appointments compatibility pass. A live beta Michigan search returned a Michigan result first; selecting it filled street, city, state and postcode correctly, both REST responses HTTP 200. Beta end-to-end suggestion latency was approximately 3.0 seconds, with a separate router/provider timing of 0.8 seconds; further request-path performance work remains. Files and settings backup: `/home/u426708099/backups/koopo-autocomplete-locality-2.77/`. Production was not deployed.


### Google beta acceptance (2.78)

The legacy appointments Google key was double-encrypted. The shared settings reader now unwraps authenticated nested legacy envelopes with a bounded depth, retaining existing single-encrypted keys and explicit removals. A behavioral regression fixture verifies this case without real credentials.

After the repair, Google autocomplete and Essentials details both returned HTTP 200. Browser selection filled Birmingham, MI and ZIP 48009 correctly. The measured beta suggestion latency was approximately 2.1 seconds; a server-only autocomplete check measured 135 ms. Beta autocomplete order is `google,acuris,geoapify`; Google's combined router allowance is 30 calls/day and 900/month, including autocomplete and details. Mapbox remains disabled. Other account/project consumers are outside these router counters. Backup: `/home/u426708099/backups/koopo-google-legacy-key-2.78/`.

## Shared gateway acceleration

The optional Gateway URL sends website autocomplete and opaque `gw_` selections to the mobile gateway. WordPress remains authoritative for encrypted keys, provider order, off switches and durable daily/monthly provider budgets. The gateway fetches its private snapshot with authenticated POST, refreshes every 15 seconds and fails closed after 45 seconds without a successful refresh. Never expose or cache this snapshot publicly.

Google, Geoapify and Acuris autocomplete run directly from the gateway using conservative batches of up to five requests reserved in WordPress before provider calls. Unused reserved units are counted, including units lost at restart. Google selection reserves a details unit before presenting suggestions. Geocode/reverse and legacy WordPress selection tokens retain their WordPress route. Address request bodies are omitted from debug logs. Guest routes permit autocomplete and bound details only; native authenticated callers retain search and other location operations.

Mobile shared address fields currently cover checkout, account billing/shipping and appointment customer/provider addresses. Social posts retain a location label; Story stickers retain structured location details through the shared search/detail adapter. Native device acceptance requires a new mobile build. Pitney Bowes shipping validation remains independent.
