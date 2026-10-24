# Koopo integrated login — 2.75

The custom login now belongs to the existing Koopo plugin, under `includes/login/`. No separate login plugin is needed. The existing page ID 4214, `koopo_login_page_id` option, `koopo-login` Elementor widget name, shortcode and settings URL remain compatible. The admin-startup WP_Rewrite guard is retained. Assets now load from Koopo with module version 1.0.3; translatable strings use the Koopo text domain.

Local migration: the standalone plugin was deactivated, then moved outside the WordPress plugin directory to `output/login-integration/retired-koopo-login`. Its original 1.0.2 ZIP is preserved for rollback. No remote deployment was performed. Existing unrelated Koopo working-tree changes were preserved.

## Target-site integration

This is an integration package, not a new installable WordPress plugin. Copy the supplied `koopo/includes/login/` files into your existing Koopo plugin. The isolated `koopo-login-integration.patch` adds the loader to `koopo.php` and updates the local 2.74 header to 2.75; apply from the Koopo repository root, or adapt those two changes to the target version. Its diff was generated against the pre-integration working file so it excludes unrelated dirty work. Deactivate and remove the standalone Koopo Login plugin when handing over. To avoid a duplicate class while both plugins are active, use the temporary compatibility loader from the retired local folder during the handover, or deactivate the standalone plugin immediately before adding the integrated loader.

Existing installations keep their selected login page and Elementor content. A new installation can use `koopo-login-elementor.json`; upload the photos/logo and select the destination-site attachments, links and login page. Template IDs and URLs are local to the source installation.

## Validation

Run `python3 tests/login-local-http-smoke.py` against the named local Docker environment. It creates and cleans up a disposable subscriber, checks native login and account restrictions, mobile login/refresh, Ads redirects, simulated SMS challenges, rate limiting and admin startup. OAuth callback completion, real SMS delivery, activation emails and remote/device release acceptance are separate checks.

Local result: all 25 HTTP checks passed. Browser proof confirmed that all photos loaded, the form rendered with Koopo-owned assets, and the authenticated dashboard loaded after the standalone directory was removed.

## Local expired-form recovery — 2026-10-17

Login module 1.0.4 refreshes its browser-bound nonce through an uncached same-origin admin AJAX request immediately before submitting credentials. This repairs forms left open across guest-cookie changes. Token-fetch failures leave the form available for retry and show a connection error. Non-JavaScript submissions still validate their nonce; rejected submissions restore a missing guest cookie so the rendered replacement form can be retried. Existing origin checks, cookie binding, account restrictions, SMS challenges and rate limits remain active.

The previously failing Chrome form successfully authenticated and completed the Ads SSO redirect to `http://localhost:3000/`. This change belongs to the main Koopo plugin; no Ads bridge source changes or remote deployment were made.
