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

        var response = await window.fetch(String(config.restBase).replace(/\/$/, '') + path, requestOptions);
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

        var modalDefaultStatus = modalStatus ? String(modalStatus.textContent || '') : '';
        var submitting = false;
        var enabling = false;

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
            modal.hidden = false;
            setModalStatus('');
            if (amountInput) {
                window.requestAnimationFrame(function () {
                    amountInput.focus();
                    amountInput.select();
                });
            }
        }

        function closeModal(forceClose) {
            if (!modal || (submitting && !forceClose)) {
                return;
            }
            modal.hidden = true;
            setModalStatus('');
            if (submitButton) {
                submitButton.disabled = false;
            }
        }

        async function submitDonation(event) {
            event.preventDefault();

            if (submitting || !creatorId || !amountInput) {
                return;
            }

            var amount = Number(amountInput.value || 0);
            if (!amount || amount <= 0) {
                setModalStatus(text('invalidAmount', 'Please enter a valid donation amount.'));
                return;
            }

            submitting = true;
            if (submitButton) {
                submitButton.disabled = true;
            }
            setModalStatus(text('preparing', 'Preparing checkout...'));

            try {
                var payload = await apiRequest('/checkout', {
                    method: 'POST',
                    body: JSON.stringify({
                        creator_id: creatorId,
                        amount: amount,
                        module: module,
                        surface: surface,
                        context_post_id: contextId,
                        context_post_type: contextType
                    })
                });

                if (!payload || !payload.checkout_url) {
                    throw new Error(text('requestFailed', 'Something went wrong. Please try again.'));
                }

                setModalStatus(text('redirecting', 'Redirecting to checkout...'));
                window.location.assign(payload.checkout_url);
            } catch (error) {
                setModalStatus(error && error.message ? error.message : text('requestFailed', 'Something went wrong. Please try again.'));
                if (submitButton) {
                    submitButton.disabled = false;
                }
                submitting = false;
                return;
            }

            closeModal(true);
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
        }

        if (enableButton) {
            enableButton.addEventListener('click', enableDonations);
        }

        quickButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!amountInput) {
                    return;
                }
                amountInput.value = String(button.getAttribute('data-kcs-quick') || '').trim();
                amountInput.focus();
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
        Array.prototype.forEach.call(document.querySelectorAll('[data-koopo-creator-support]'), initBlock);
    });
})();
