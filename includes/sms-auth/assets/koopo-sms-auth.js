(function () {
    'use strict';

    var cfg = window.KoopoSmsAuth || {};
    var messages = cfg.messages || {};

    function request(path, options) {
        options = options || {};
        var headers = options.headers || {};
        headers['Content-Type'] = 'application/json';

        if (cfg.nonce) {
            headers['X-WP-Nonce'] = cfg.nonce;
        }

        var token = window.localStorage ? window.localStorage.getItem('koopoSmsAuthToken') : '';
        if (token && !headers.Authorization) {
            headers.Authorization = 'Bearer ' + token;
        }

        return fetch((cfg.restBase || '') + path, {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    var message = json && json.message ? json.message : (messages.failed || 'Request failed.');
                    throw new Error(message);
                }
                return json;
            });
        });
    }

    function setStatus(root, message, isError) {
        var status = root.querySelector('[data-sms-status]');
        if (!status) {
            return;
        }
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
    }

    function setBusy(form, busy) {
        Array.prototype.forEach.call(form.querySelectorAll('button, input'), function (el) {
            el.disabled = !!busy;
        });
    }

    function initLogin(root) {
        var phoneForm = root.querySelector('[data-sms-step="phone"]');
        var codeForm = root.querySelector('[data-sms-step="code"]');
        var phoneInput = phoneForm ? phoneForm.querySelector('[name="phone"]') : null;
        var codeInput = codeForm ? codeForm.querySelector('[name="code"]') : null;
        var requestId = '';
        var phone = '';

        if (!phoneForm || !codeForm || !phoneInput || !codeInput) {
            return;
        }

        phoneForm.addEventListener('submit', function (event) {
            event.preventDefault();
            phone = phoneInput.value;
            setBusy(phoneForm, true);
            setStatus(root, messages.sending || 'Sending code...', false);

            request('/login/start', {
                method: 'POST',
                body: { phone: phone }
            }).then(function (json) {
                requestId = json.request_id || '';
                setStatus(root, json.message || messages.sent || 'Code sent.', false);
                phoneForm.hidden = true;
                codeForm.hidden = false;
                codeInput.focus();
            }).catch(function (error) {
                setStatus(root, error.message, true);
            }).finally(function () {
                setBusy(phoneForm, false);
            });
        });

        codeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var remember = !!(codeForm.querySelector('[name="remember"]') || {}).checked;
            setBusy(codeForm, true);
            setStatus(root, messages.verifying || 'Verifying code...', false);

            request('/login/verify', {
                method: 'POST',
                body: {
                    phone: phone,
                    request_id: requestId,
                    code: codeInput.value,
                    remember: remember
                }
            }).then(function (json) {
                if (json.access_token && window.localStorage) {
                    window.localStorage.setItem('koopoSmsAuthToken', json.access_token);
                }
                setStatus(root, messages.verified || 'Verified.', false);
                window.location.href = root.getAttribute('data-redirect-url') || window.location.href;
            }).catch(function (error) {
                setStatus(root, error.message, true);
                setBusy(codeForm, false);
            });
        });

        var back = root.querySelector('[data-sms-back]');
        if (back) {
            back.addEventListener('click', function () {
                requestId = '';
                phone = '';
                codeInput.value = '';
                codeForm.hidden = true;
                phoneForm.hidden = false;
                setStatus(root, '', false);
                phoneInput.focus();
            });
        }
    }

    function initPhoneSettings(root) {
        var phoneForm = root.querySelector('[data-sms-phone-step="phone"]');
        var codeForm = root.querySelector('[data-sms-phone-step="code"]');
        var phoneInput = phoneForm ? phoneForm.querySelector('[name="phone"]') : null;
        var codeInput = codeForm ? codeForm.querySelector('[name="code"]') : null;
        var current = root.querySelector('[data-sms-current-phone]');
        var requestId = '';
        var phone = '';

        if (!phoneForm || !codeForm || !phoneInput || !codeInput) {
            return;
        }

        phoneForm.addEventListener('submit', function (event) {
            event.preventDefault();
            phone = phoneInput.value;
            setBusy(phoneForm, true);
            setStatus(root, messages.sending || 'Sending code...', false);

            request('/phone/start', {
                method: 'POST',
                body: { phone: phone }
            }).then(function (json) {
                requestId = json.request_id || '';
                setStatus(root, json.message || messages.sent || 'Code sent.', false);
                phoneForm.hidden = true;
                codeForm.hidden = false;
                codeInput.focus();
            }).catch(function (error) {
                setStatus(root, error.message, true);
            }).finally(function () {
                setBusy(phoneForm, false);
            });
        });

        codeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            setBusy(codeForm, true);
            setStatus(root, messages.verifying || 'Verifying code...', false);

            request('/phone/verify', {
                method: 'POST',
                body: {
                    phone: phone,
                    request_id: requestId,
                    code: codeInput.value
                }
            }).then(function (json) {
                var masked = json && json.phone ? json.phone.masked : '';
                if (current && masked) {
                    current.textContent = 'Verified phone: ' + masked;
                }
                setStatus(root, messages.verified || 'Verified.', false);
                codeForm.hidden = true;
                phoneForm.hidden = false;
                codeInput.value = '';
            }).catch(function (error) {
                setStatus(root, error.message, true);
            }).finally(function () {
                setBusy(codeForm, false);
            });
        });

        var back = root.querySelector('[data-sms-phone-back]');
        if (back) {
            back.addEventListener('click', function () {
                requestId = '';
                phone = '';
                codeInput.value = '';
                codeForm.hidden = true;
                phoneForm.hidden = false;
                setStatus(root, '', false);
                phoneInput.focus();
            });
        }

        var remove = root.querySelector('[data-sms-remove-phone]');
        if (remove) {
            remove.addEventListener('click', function () {
                if (!window.confirm('Remove this phone number from your account?')) {
                    return;
                }

                remove.disabled = true;
                setStatus(root, messages.removing || 'Removing phone number...', false);

                request('/phone', { method: 'DELETE' }).then(function () {
                    if (current) {
                        current.textContent = 'No verified phone number is connected yet.';
                    }
                    remove.remove();
                    setStatus(root, messages.removed || 'Phone number removed.', false);
                }).catch(function (error) {
                    setStatus(root, error.message, true);
                    remove.disabled = false;
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-koopo-sms-login]'), initLogin);
        Array.prototype.forEach.call(document.querySelectorAll('[data-koopo-sms-phone-settings]'), initPhoneSettings);
    });
}());
