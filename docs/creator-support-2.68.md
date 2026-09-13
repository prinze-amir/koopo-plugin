# Koopo Love 2.68

Manual support amount is hidden initially and for preset selections. Custom reveals and focuses it; choosing a preset hides it again and keeps focus on the selected button. Typing a preset-equivalent value while in Custom does not collapse the field. Restored non-preset draft amounts open Custom. The step-description row is removed; step transitions focus the modal heading instead. Conditional live-chat messaging is preserved.

Validation: renderer PHP syntax, JS syntax, whitespace checks, and the existing mocked-provider regression suite passed, including new Custom/preset visibility and focus assertions. Beta browser verified hidden preset mode, Custom reveal and typing, switching back to a preset, and absence of the step row. No payment submitted.

Deployed only the renderer, support JS, and plugin bootstrap version to beta 2.68. Baselines matched prior local files. Local and beta serve the updated JS byte-for-byte. WordPress and LiteSpeed caches purged; existing Elementor CLI api-on-null warning remains. Backup: /home/u426708099/backups/koopo-love-2.68-20260912/before.tar.gz. Restore that archive into the plugin directory and purge caches for rollback. Checkout assets remain unchanged at 2.67.
