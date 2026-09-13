(function () {
    'use strict';

    var config = window.KoopoCreatorSupport || null;
    if (!config || !config.restBase) {
        return;
    }

    function text(key, fallback) {
        if (config.messages && typeof config.messages[key] === 'string' && config.messages[key]) {
            return config.messages[key];
        }
        return fallback;
    }

    async function apiRequest(path, options) {
        var requestOptions = options || {};
        var headers = requestOptions.headers ? Object.assign({}, requestOptions.headers) : {};
        headers['Content-Type'] = 'application/json';

        if (config.nonce) {
            headers['X-WP-Nonce'] = config.nonce;
        }

        requestOptions.headers = headers;

        var controller = new AbortController();
        requestOptions.signal = controller.signal;
        var timer = window.setTimeout(function () { controller.abort(); }, 25000);
        var response;
        try { response = await window.fetch(String(config.restBase).replace(/\/$/, '') + path, requestOptions); }
        catch (error) { throw new Error(error.name === 'AbortError' ? 'The request timed out. Please retry; your checkout will be reused.' : 'Could not connect. Check your connection and retry.'); }
        finally { window.clearTimeout(timer); }
        var data = null;

        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }

        if (!response.ok) {
            var message = data && data.message ? data.message : text('requestFailed', 'Something went wrong. Please try again.');
            throw new Error(message);
        }

        return data;
    }

    function newRequestKey() {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }

    function initBlock(block) {
        if (!block || block.__koopoCreatorSupportReady) {
            return;
        }
        block.__koopoCreatorSupportReady = true;

        var creatorId = Number(block.getAttribute('data-creator-id') || 0);
        var module = String(block.getAttribute('data-module') || 'general');
        var surface = String(block.getAttribute('data-surface') || 'default');
        var contextId = Number(block.getAttribute('data-context-id') || 0);
        var contextType = String(block.getAttribute('data-context-type') || '');

        var openButton = block.querySelector('[data-kcs-open]');
        var enableButton = block.querySelector('[data-kcs-enable]');
        var status = block.querySelector('[data-kcs-status]');
        var modal = block.querySelector('[data-kcs-modal]');
        var form = block.querySelector('[data-kcs-form]');
        var amountInput = block.querySelector('[data-kcs-amount]');
        var submitButton = block.querySelector('[data-kcs-submit]');
        var modalStatus = block.querySelector('[data-kcs-modal-status]');
        var quickButtons = Array.prototype.slice.call(block.querySelectorAll('[data-kcs-quick]'));
        var closers = Array.prototype.slice.call(block.querySelectorAll('[data-kcs-close]'));
        var actions = block.querySelector('.koopo-creator-support__actions');

        var details = form ? form.querySelector('[data-kcs-details]') : null;
        var summary = form ? form.querySelector('[data-kcs-summary]') : null;
        var backButton = form ? form.querySelector('[data-kcs-back]') : null;
        var heading = modal ? modal.querySelector('[data-kcs-heading]') : null;
        var originalHeading = heading ? heading.textContent : '';
        var intro = modal ? modal.querySelector('[data-kcs-intro]') : null;
        var originalIntro = intro ? intro.textContent : '';
        var eyebrow = modal ? modal.querySelector('[data-kcs-eyebrow]') : null;
        var originalEyebrow = eyebrow ? eyebrow.textContent : '';
        var successMark = modal ? modal.querySelector('[data-kcs-success-mark]') : null;
        var dismissButton = form ? form.querySelector('button[data-kcs-close]') : null;
        var step = 1;
        var sessionDraft = '';
        function setStep(value) {
            step = value;
            var complete = value === 3;
            if (modal) modal.classList.toggle('is-success', complete);
            if (successMark) successMark.hidden = !complete;
            if (heading) heading.textContent = complete ? 'You made a difference.' : originalHeading;
            if (intro) intro.textContent = complete ? 'Thanks for supporting your favorite creator on Koopo. Your generosity helps them keep creating what you love.' : originalIntro;
            if (eyebrow) eyebrow.textContent = complete ? 'SUPPORT RECEIVED' : originalEyebrow;
            if (dismissButton) dismissButton.textContent = complete ? 'Done' : 'Cancel';
            if (details) details.hidden = value !== 1;
            if (paymentHost) paymentHost.hidden = value !== 2;
            if (backButton) backButton.hidden = value !== 2 || (session && session.confirming);
            if (summary) summary.hidden = value === 1;
            if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); }
            if (submitButton) submitButton.textContent = value === 1 ? 'Continue to payment' : 'Support ' + (session ? new Intl.NumberFormat(undefined, { style: 'currency', currency: session.currency }).format(Number(session.amount)) : '');
        }

        var modalDefaultStatus = modalStatus ? String(modalStatus.textContent || '') : '';
        var submitting = false;
        function setBusy(busy) {
            submitting = busy;
            if (form) form.setAttribute('aria-busy', busy ? 'true' : 'false');
            if (modal) modal.classList.toggle('is-processing', busy);
            if (details) details.inert = busy;
            if (submitButton) submitButton.disabled = busy;
            if (backButton) backButton.disabled = busy;
            closers.forEach(function (button) { if (button.tagName === 'BUTTON') button.disabled = busy; });
        }
        var enabling = false;
        var previousFocus = null;

        // Keep the fixed modal out of transformed or isolated surface containers.
        // This makes its positioning and z-index relative to the viewport.
        if (modal && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        function setStatus(message) {
            if (status) {
                status.textContent = String(message || '');
            }
        }

        function setModalStatus(message) {
            if (modalStatus) {
                modalStatus.textContent = String(message || modalDefaultStatus || '');
            }
        }

        function openModal() {
            if (!modal || submitting) {
                return;
            }
            if (session && session.paid) {
                session = null;
                if (elements) elements = null;
                if (paymentHost) { paymentHost.textContent = ''; paymentHost.hidden = true; }
                receiptLink.hidden = true; submitButton.hidden = false; submitButton.textContent = 'Continue to payment';
                amountInput.readOnly = false;
                var message = form.querySelector('[data-kcs-message]');
                if (message) { message.readOnly = false; message.value = ''; }
                quickButtons.forEach(function (button) { button.disabled = false; });
                setStep(1);
                requestKey = newRequestKey();
                try { window.sessionStorage.setItem(storageKey, requestKey); } catch (_) {}
            }
            previousFocus = document.activeElement;
            modal.hidden = false;
            document.documentElement.classList.add('koopo-creator-support-modal-open');
            setModalStatus('');
            if (amountInput && step === 1) {
                window.requestAnimationFrame(function () {
                    focusAmountChoice();
                });
            }
        }

        function closeModal(forceClose) {
            if (!modal || (submitting && !forceClose)) {
                return;
            }
            modal.hidden = true;
            document.documentElement.classList.remove('koopo-creator-support-modal-open');
            setModalStatus('');
            if (submitButton) {
                submitButton.disabled = false;
            }
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            previousFocus = null;
        }

        var session = null;
        var stripe = null;
        var elements = null;
        var requestKey = null;
        var paymentHost = form ? form.querySelector('[data-kcs-payment]') : null;
        var receiptLink = form ? form.querySelector('[data-kcs-receipt]') : null;
        var checkoutContext = block.getAttribute('data-checkout-context');
        var storageKey = 'koopo-support:' + checkoutContext;
        try { requestKey = window.sessionStorage.getItem(storageKey); } catch (_) {}
        if (!requestKey) {
            requestKey = newRequestKey();
            try { window.sessionStorage.setItem(storageKey, requestKey); } catch (_) {}
        }

        try {
            var draft = JSON.parse(window.sessionStorage.getItem(storageKey + ':draft') || 'null');
            if (draft && draft.requestKey === requestKey) {
                amountInput.value = draft.amount;
                var savedMessage = form.querySelector('[data-kcs-message]');
                if (savedMessage) savedMessage.value = draft.message;
            }
        } catch (_) {}

        async function loadStripe() {
            if (window.Stripe) return;
            if (!window.koopoSupportStripeReady) {
                window.koopoSupportStripeReady = new Promise(function (resolve, reject) {
                    var script = document.createElement('script');
                    script.src = 'https://js.stripe.com/v3/';
                    var timeout = window.setTimeout(function () { window.koopoSupportStripeReady = null; script.remove(); reject(new Error('Payment form took too long to load. Please retry.')); }, 20000);
                    script.onload = function () { window.clearTimeout(timeout); resolve(); };
                    script.onerror = function () { window.clearTimeout(timeout); window.koopoSupportStripeReady = null; reject(new Error('Payment form could not load. Please retry.')); };
                    document.head.appendChild(script);
                });
            }
            await window.koopoSupportStripeReady;
        }

        function showResult(result) {
            if (!result.paid) return false;
            if (session) session.paid = true;
            setStep(3);
            var pending = result.chat_state === 'pending';
            var withheld = result.chat_state === 'withheld';
            setModalStatus(pending ? 'Payment received. Your message is being posted; you do not need to pay again.' :
                withheld ? 'Payment received. Your message could not be posted because the chat rules changed.' :
                result.chat_state === 'posted' ? 'Thank you! Your support and message are now in the chat.' : 'Your payment is confirmed. Thanks for being part of their journey.');
            if (paymentHost) paymentHost.hidden = true;
            if (receiptLink) { receiptLink.href = result.receipt_url; receiptLink.hidden = false; }
            submitButton.hidden = true;
            try { window.sessionStorage.removeItem(storageKey); } catch (_) {}
            return true;
        }

        async function waitForPayment(orderId) {
            for (var attempt = 0; attempt < 12; attempt++) {
                var result = await apiRequest('/sessions/' + orderId + '/confirm', { method: 'POST', body: '{}' });
                if (showResult(result) && result.chat_state !== 'pending') return;
                setModalStatus(result.paid ? 'Payment received. Posting your message…' : 'Confirming payment. Please do not submit another payment.');
                await new Promise(function (resolve) { window.setTimeout(resolve, 2000); });
            }
            setModalStatus('Your payment is still being confirmed. You can close this window; check your orders before starting another payment.');
            submitButton.textContent = 'Check payment status';
        }

        async function submitDonation(event) {
            event.preventDefault();
            if (submitting || !creatorId || !amountInput) return;
            if (!config.loggedIn) { setModalStatus('Please sign in using the link below to support this creator.'); return; }
            if (step === 1 && form && !form.reportValidity()) { setModalStatus('Please enter a valid amount and check your message.'); return; }
            var amount = Number(amountInput.value || 0);
            if (!amount || amount < 1 || amount > 10000) { setModalStatus('Choose an amount between 1 and 10,000.'); return; }
            setBusy(true);
            try {
                if (step === 1) {
                    setModalStatus('Preparing secure payment…');
                    var message = form.querySelector('[data-kcs-message]');
                    try { window.sessionStorage.setItem(storageKey + ':draft', JSON.stringify({ requestKey: requestKey, amount: amount, message: message ? message.value : '' })); } catch (_) {}
                    var draftSignature = JSON.stringify([amount, message ? message.value : '']);
                    if (session && sessionDraft !== draftSignature) {
                        session = null; elements = null; paymentHost.textContent = ''; requestKey = newRequestKey();
                        try { window.sessionStorage.setItem(storageKey, requestKey); } catch (_) {}
                    }
                    try { window.sessionStorage.setItem(storageKey + ':draft', JSON.stringify({ requestKey: requestKey, amount: amount, message: message ? message.value : '' })); } catch (_) {}
                    if (!session) session = await apiRequest('/sessions', { method: 'POST', body: JSON.stringify({ context: checkoutContext, amount: amount, message: message ? message.value : '', request_key: requestKey }) });
                    if (showResult(session)) return;
                    sessionDraft = draftSignature;
                    setModalStatus('Loading secure payment form…');
                    await loadStripe();
                    if (!elements) {
                    stripe = window.Stripe(session.publishable_key);
                    elements = stripe.elements({ clientSecret: session.client_secret, appearance: { theme: 'stripe', variables: { colorPrimary: '#ffba12', colorText: '#101638', borderRadius: '12px' } } });
                    paymentHost.hidden = false;
                    var paymentElement = elements.create('payment', { defaultValues: { billingDetails: session.billing || {} } });
                    await new Promise(function (resolve, reject) {
                        var readyTimeout = window.setTimeout(function () { reject(new Error('Payment form took too long to load. Please retry.')); }, 20000);
                        paymentElement.on('ready', function () { window.clearTimeout(readyTimeout); resolve(); });
                        paymentElement.on('loaderror', function () { window.clearTimeout(readyTimeout); reject(new Error('Payment form could not load. Please retry.')); });
                        try { paymentElement.mount(paymentHost); } catch (error) { window.clearTimeout(readyTimeout); reject(error); }
                    }).catch(function (error) { paymentElement.destroy(); elements = null; throw error; });
                    }
                    if (summary) summary.textContent = new Intl.NumberFormat(undefined, { style: 'currency', currency: session.currency }).format(Number(session.amount)) + (message && message.value ? ' · ' + message.value : '');
                    setStep(2);
                    amountInput.readOnly = true;
                    quickButtons.forEach(function (button) { button.disabled = true; });
                    if (message) message.readOnly = true;
                    submitButton.textContent = 'Support ' + new Intl.NumberFormat(undefined, { style: 'currency', currency: session.currency }).format(Number(session.amount));
                    setModalStatus('Enter your payment details. You will stay on this page.');
                } else if (!elements || session.confirming) {
                    await waitForPayment(session.order_id);
                } else {
                    setModalStatus('Processing payment…');
                    var returnUrl = new URL(window.location.href);
                    returnUrl.searchParams.set('koopo_support_order', session.order_id);
                    returnUrl.searchParams.set('koopo_support_context', checkoutContext);
                    var result = await stripe.confirmPayment({ elements: elements, confirmParams: { return_url: returnUrl.toString() }, redirect: 'if_required' });
                    if (result.error) throw new Error(result.error.message || 'Payment was not completed. Please check your details.');
                    session.confirming = true;
                    await waitForPayment(session.order_id);
                }
            } catch (error) {
                setModalStatus(error.message || 'Payment could not be completed. Please retry.');
                if (session && !elements && !session.paid) session = null;
            } finally {
                setBusy(false);
            }
        }

        var resumedOrder = new URL(window.location.href).searchParams.get('koopo_support_order');
        if (resumedOrder && /^\d+$/.test(resumedOrder) && new URL(window.location.href).searchParams.get('koopo_support_context') === checkoutContext && !window.koopoSupportResumed) {
            window.koopoSupportResumed = true;
            openModal();
            session = { order_id: Number(resumedOrder), confirming: true };
            setBusy(true);
            setModalStatus('Confirming your payment…');
            waitForPayment(session.order_id).catch(function (error) { setModalStatus(error.message); }).finally(function () { setBusy(false); });
            var cleanUrl = new URL(window.location.href);
            ['koopo_support_order', 'koopo_support_context', 'payment_intent', 'payment_intent_client_secret', 'redirect_status'].forEach(function (key) { cleanUrl.searchParams.delete(key); });
            window.history.replaceState({}, '', cleanUrl.toString());
        }

        async function enableDonations() {
            if (enabling || !creatorId || !enableButton) {
                return;
            }

            enabling = true;
            enableButton.disabled = true;
            setStatus(text('creatingProduct', 'Enabling donations...'));

            try {
                var payload = await apiRequest('/product', {
                    method: 'POST',
                    body: JSON.stringify({
                        creator_id: creatorId
                    })
                });

                block.classList.add('is-enabled');
                enableButton.textContent = text('productEnabled', 'Donations are enabled. Supporters can now donate.');
                setStatus(payload && payload.message ? payload.message : text('productEnabled', 'Donations are enabled. Supporters can now donate.'));

                if (actions) {
                    actions.classList.add('is-enabled');
                }
            } catch (error) {
                setStatus(error && error.message ? error.message : text('requestFailed', 'Something went wrong. Please try again.'));
                enableButton.disabled = false;
                enabling = false;
            }
        }

        if (openButton) {
            openButton.addEventListener('click', openModal);
        }

        if (form) {
            form.addEventListener('submit', submitDonation);
            if (backButton) backButton.addEventListener('click', function () {
                if (submitting || (session && session.confirming)) return;
                setStep(1); amountInput.readOnly = false;
                var message = form.querySelector('[data-kcs-message]'); if (message) message.readOnly = false;
                quickButtons.forEach(function (button) { button.disabled = false; });
                setModalStatus('Review your amount and message.'); focusAmountChoice();
            });
        }

        if (enableButton) {
            enableButton.addEventListener('click', enableDonations);
        }

        var customButton = modal ? modal.querySelector('[data-kcs-custom]') : null;
        var customField = modal ? modal.querySelector('[data-kcs-custom-field]') : null;
        function focusAmountChoice() {
            var target = customField && !customField.hidden ? amountInput : quickButtons.find(function (button) { return button.getAttribute('aria-pressed') === 'true'; }) || quickButtons[0] || customButton;
            if (target) target.focus();
        }
        function highlightAmount(custom) {
            if (customField) customField.hidden = !custom;
            quickButtons.forEach(function (button) { button.setAttribute('aria-pressed', !custom && Number(amountInput.value) === Number(button.dataset.kcsQuick) ? 'true' : 'false'); });
            if (customButton) customButton.setAttribute('aria-pressed', custom ? 'true' : 'false');
        }
        if (customButton) customButton.addEventListener('click', function () { if (amountInput.readOnly || submitButton.disabled) return; highlightAmount(true); amountInput.focus(); });
        // Typing a preset value in Custom must not collapse the field mid-entry.
        if (amountInput) {
            amountInput.addEventListener('input', function () { highlightAmount(true); });
            highlightAmount(!!amountInput.value && !quickButtons.some(function (button) { return Number(button.dataset.kcsQuick) === Number(amountInput.value); }));
        }
        quickButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!amountInput) {
                    return;
                }
                amountInput.value = String(button.getAttribute('data-kcs-quick') || '').trim();
                highlightAmount(false);
                button.focus();
            });
        });

        closers.forEach(function (button) {
            button.addEventListener('click', function () {
                closeModal();
            });
        });

        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target && event.target.hasAttribute('data-kcs-close')) {
                    closeModal();
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Tab' && modal && !modal.hidden) {
                var focusable = Array.prototype.filter.call(modal.querySelectorAll('button, a[href], input, textarea, iframe, [tabindex="0"]'), function (node) { return !node.disabled && node.getClientRects().length > 0; });
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
            if (event.key === 'Escape' && modal && !modal.hidden) {
                closeModal();
            }
        });
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
            return;
        }
        fn();
    }

    ready(function () {
        function initialize() { Array.prototype.forEach.call(document.querySelectorAll('[data-koopo-creator-support]'), initBlock); }
        initialize();
        var observer = new MutationObserver(function (records) {
            records.forEach(function (record) { Array.prototype.forEach.call(record.addedNodes, function (node) {
                if (node.nodeType !== 1) return;
                if (node.matches('[data-koopo-creator-support]')) initBlock(node);
                Array.prototype.forEach.call(node.querySelectorAll('[data-koopo-creator-support]'), initBlock);
            }); });
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.addEventListener('pagehide', function () { observer.disconnect(); });
    });
})();
