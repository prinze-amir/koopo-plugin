/* One native WooCommerce form; steps only change presentation. */
(function ($) {
    'use strict';
    $(function () {
        var form = document.querySelector('form.kc-checkout');
        if (!form) return;
        var $form = $(form), words = window.koopoCheckout;
        var steps = ['delivery', 'payment', 'review'], step = 'delivery', reached = 0;
        var busy = false, failed = false, fingerprint = '', reviewNode = null;
        var q = function (s) { return form.querySelector(s); };
        var all = function (s) { return Array.from(form.querySelectorAll(s)); };
        function status(message, focus) {
            q('.kc-status').textContent = message || '';
            if (message && focus) q('.kc-status').focus();
        }
        function value(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
        function labelValue(id) {
            var el = document.getElementById(id);
            return el && el.tagName === 'SELECT' ? (el.selectedOptions[0] || {}).textContent || '' : value(id);
        }
        function summary() {
            var prefix = $('#ship-to-different-address-checkbox').is(':checked') ? 'shipping_' : 'billing_';
            var address = [
                ['first_name', 'last_name'].map(function (key) { return value(prefix + key); }).filter(Boolean).join(' '),
                ['address_1', 'address_2'].map(function (key) { return value(prefix + key); }).filter(Boolean).join(', '),
                ['city', 'state', 'postcode'].map(function (key) { return labelValue(prefix + key); }).filter(Boolean).join(', '),
                labelValue(prefix + 'country')
            ].filter(Boolean).join('\n');
            var selected = q('input[name="payment_method"]:checked');
            var paymentLabel = selected ? form.querySelector('label[for="' + selected.id + '"]') : null;
            var delivery = all('.kc-shipping-table input.shipping_method:checked, .kc-shipping-table input.shipping_method[type="hidden"]').map(function (el) {
                var label = form.querySelector('label[for="' + el.id + '"]');
                var heading = el.closest('tr').querySelector('th');
                return (heading ? heading.textContent.trim() + ': ' : '') + (label ? label.textContent.trim() : el.value);
            });
            var data = {
                contact: [value('billing_first_name') + ' ' + value('billing_last_name'), [value('billing_email'), value('billing_phone')].filter(Boolean).join(' · ')].filter(Boolean).join('\n'),
                address: address || words.noShipping,
                delivery: delivery.join('\n'),
                payment: paymentLabel ? paymentLabel.textContent.trim() : words.noPayment
            };
            all('[data-kc-summary]').forEach(function (el) { el.textContent = data[el.dataset.kcSummary]; });
            var total = q('.order-total .woocommerce-Price-amount');
            q('.kc-summary-total').textContent = total ? total.textContent : '';
        }
        function positionItems() {
            var table = q('.woocommerce-checkout-review-order-table');
            var target = q('.kc-review-items-table');
            if (!table || !target) return;
            var original = table.querySelector('tbody');
            var moved = target.querySelector('tbody');
            if (step === 'review' && original) {
                target.replaceChildren(original);
            } else if (step !== 'review' && moved) {
                if (original) moved.remove();
                else table.insertBefore(moved, table.querySelector('tfoot'));
            }
        }
        function controls() {
            q('.kc-next').disabled = busy || failed;
            // Native gateway controls retain ownership of their own disabled state.
            q('.kc-actions').setAttribute('aria-busy', busy ? 'true' : 'false');
            all('[data-kc-step]').forEach(function (el, index) {
                el.disabled = busy || index > reached;
                el.classList.toggle('is-complete', index < steps.indexOf(step));
                if (el.dataset.kcStep === step) el.setAttribute('aria-current', 'step');
                else el.removeAttribute('aria-current');
            });
        }
        function show(next, focus) {
            step = next;
            reached = Math.max(reached, steps.indexOf(step));
            form.dataset.step = step;
            q('.kc-next').firstChild.textContent = (step === 'delivery' ? words.nextPayment : words.nextReview) + ' ';
            q('.kc-intro').textContent = words[step + 'Copy'];
            all('[data-kc-panel]').forEach(function (el) { el.hidden = el.dataset.kcPanel !== step; });
            q('.kc-payment-slot').hidden = step !== 'payment';
            q('[data-kc-payment-heading]').hidden = step === 'review';
            q('.kc-back').hidden = step === 'delivery';
            q('.kc-back').textContent = step === 'review' ? words.backPayment : words.backDelivery;
            q('.kc-cart-link').hidden = step !== 'delivery';
            q('.kc-next').hidden = step === 'review';
            if (step === 'review') {
                q('.kc-summary').classList.remove('is-collapsed');
                q('.kc-summary-toggle').setAttribute('aria-expanded', 'true');
            }
            positionItems(); summary(); controls();
            if (focus) {
                q('.kc-header h1').focus({preventScroll: true});
                q('.kc-header').scrollIntoView({block: 'start', behavior: 'auto'});
            }
        }
        function fieldCheck() {
            var invalid = null;
            all('[data-kc-panel="delivery"] .form-row').forEach(function (row) {
                if (!$(row).is(':visible')) return;
                var input = row.querySelector('input:not([type="hidden"]), select, textarea');
                if (!input || input.disabled) return;
                var missing = row.classList.contains('validate-required') && (input.type === 'checkbox' ? !input.checked : !input.value.trim());
                var bad = missing || (input.value && !input.checkValidity());
                row.classList.toggle('woocommerce-invalid', !!bad);
                if (bad) { input.setAttribute('aria-invalid', 'true'); if (!invalid) invalid = input; }
                else input.removeAttribute('aria-invalid');
            });
            if (invalid) {
                status(words.required);
                if ($(invalid).hasClass('select2-hidden-accessible')) $(invalid).select2('open');
                else invalid.focus();
                return false;
            }
            return true;
        }
        function shippingCheck() {
            if (form.dataset.needsShipping !== '1') return true;
            var rows = all('.kc-shipping-table tr.shipping');
            var missing = !rows.length || rows.some(function (row) {
                return !row.querySelector('input.shipping_method:checked, input.shipping_method[type="hidden"], select.shipping_method option:checked');
            });
            var flag = q('#koopo_shipping_address_confirmed');
            if (missing || (flag && flag.value !== '1')) {
                status(words.shipping, true);
                return false;
            }
            return true;
        }
        function next() {
            if (busy || failed) { status(failed ? words.failed : words.busy, true); return; }
            status('');
            if (step === 'delivery') {
                if (fieldCheck() && shippingCheck()) show('payment', true);
            } else if (step === 'payment') {
                if (q('.wc_payment_methods') && !q('input[name="payment_method"]:checked')) {
                    status(words.payment, true); return;
                }
                show('review', true);
            }
        }
        function sync() {
            // Move existing nodes, never clone secure fields, inputs, nonces or gateway handlers.
            var payment = q('#payment');
            if (payment) {
                var button = payment.querySelector('#place_order');
                if (button) {
                    all('.kc-actions .kc-native-action').forEach(function (old) { old.remove(); });
                    button.classList.add('kc-native-action');
                    q('.kc-actions').appendChild(button);
                }
                var agreement = payment.querySelector('.place-order');
                if (agreement) {
                    var previousTerms = q('.kc-terms-slot input[name="terms"]');
                    var refreshedTerms = agreement.querySelector('input[name="terms"]');
                    if (previousTerms && refreshedTerms) refreshedTerms.checked = previousTerms.checked;
                    q('.kc-terms-slot').replaceChildren(agreement);
                }
                if (payment.parentNode !== q('.kc-payment-slot')) q('.kc-payment-slot').appendChild(payment);
            }
            var rows = all('#order_review tr.shipping');
            var currentReview = q('.woocommerce-checkout-review-order-table');
            if (q('.kc-shipping-table') && (rows.length || currentReview !== reviewNode)) {
                q('.kc-shipping-table tbody').replaceChildren();
                rows.forEach(function (row) { q('.kc-shipping-table tbody').appendChild(row); });
            }
            reviewNode = currentReview;
            summary();
            var fresh = q('.kc-summary-total').textContent + all('input.shipping_method:checked, input.shipping_method[type="hidden"]').map(function (el) { return el.value; }).join('|');
            if (fingerprint && fingerprint !== fresh && step === 'review') {
                reached = 1; show('payment', false);
                status(words.changed);
            }
            fingerprint = fresh;
            positionItems(); controls();
        }
        ['billing_email_field', 'billing_phone_field'].forEach(function (id) {
            var field = document.getElementById(id);
            if (field) q('.kc-contact-fields').appendChild(field);
        });
        if (!q('.kc-contact-fields').children.length) q('.kc-contact').remove();
        var notes = q('.woocommerce-additional-fields');
        if (notes) q('.kc-notes-slot').appendChild(notes);
        if (!notes || !notes.querySelector('input, textarea, select')) q('.kc-notes-slot').hidden = true;
        var confirm = q('.koopo-shipping-address-confirmation');
        if (confirm && q('.kc-confirmation-slot')) q('.kc-confirmation-slot').appendChild(confirm);
        else if (confirm && form.dataset.needsShipping !== '1') confirm.hidden = true;
        q('.kc-main').prepend(q('.kc-header'));
        form.classList.add('kc-enhanced');
        if (!form.closest('dialog')) document.body.classList.add('kc-enhanced-page');
        sync(); show('delivery', false);
        $form.on('click', '.kc-next', next);
        $form.on('click', '[data-kc-edit], [data-kc-step]', function () {
            if (busy) return;
            var target = this.dataset.kcEdit || this.dataset.kcStep;
            if (steps.indexOf(target) <= reached) { status(''); show(target, true); }
        });
        $form.on('click', '[data-kc-back]', function () { if (!busy) show(step === 'review' ? 'payment' : 'delivery', true); });
        $form.on('click', '.kc-summary-toggle', function () {
            var collapsed = q('.kc-summary').classList.toggle('is-collapsed');
            this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
        $form.on('change input', '#customer_details :input, .kc-contact-fields :input, input.shipping_method', function () {
            reached = 0; controls();
        });
        // Woo runs this guard before its method-specific payment handlers and server submission.
        $form.on('checkout_place_order.koopo', function () {
            if (step !== 'review') { next(); return false; }
            if (busy || failed) { status(failed ? words.failed : words.busy, true); return false; }
        });
        $(document.body).on('update_checkout.koopo', function () { busy = true; failed = false; status(words.busy); controls(); });
        $(document.body).on('updated_checkout.koopo', function (event, data) {
            busy = false; failed = !!(data && data.result === 'failure');
            status(failed ? words.failed : ''); sync();
        });
        $(document.body).on('checkout_error.koopo', function () {
            busy = false;
            var id = $('.woocommerce-error [data-id]').first().attr('data-id');
            var field = id ? document.getElementById(id) : null;
            var errorStep = 'payment';
            if ((field && field.closest('[data-kc-panel="delivery"]')) || (id && /shipping|delivery/.test(id))) errorStep = 'delivery';
            else if (field && field.closest('[data-kc-panel="review"], .place-order')) errorStep = 'review';
            if (errorStep === 'delivery') reached = 0;
            show(errorStep, false);
            status(words.checkMessage, true);
        });
        $(document).ajaxError(function (event, xhr, settings) {
            if (String(settings.url).indexOf('update_order_review') !== -1 && xhr.statusText !== 'abort') {
                busy = false; failed = true; status(words.failed); controls();
            }
        });
    });
})(jQuery);
