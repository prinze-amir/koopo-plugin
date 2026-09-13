/* global koopoCart, jQuery */
(function ($) {
    'use strict';
    const cfg = koopoCart;
    let queue = Promise.resolve();
    let uncertain = false;
    let dialog, content, opener, viewRequest;
    let backButton;
    const viewStack = [];
    const timers = new WeakMap();

    function message(container, text, html = false) {
        let node = container.querySelector(':scope > .koopo-cart-message');
        if (!node) {
            node = document.createElement('div');
            node.className = 'koopo-cart-message';
            node.setAttribute('role', 'status');
            node.setAttribute('aria-live', 'polite');
            container.append(node);
        }
        if (html) node.innerHTML = text; // Server-rendered WooCommerce notices only.
        else node.textContent = text;
        return node;
    }

    function cartLink(node) {
        const link = document.createElement('a');
        link.href = cfg.cartUrl;
        link.textContent = cfg.viewCart;
        link.className = 'koopo-cart-view-link added_to_cart';
        node.append(link);
    }

    async function request(url, data, signal) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Koopo-Cart': '1' }, body: data, signal
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            const error = new Error(result.data?.message || cfg.uncertain);
            error.rejected = response.status >= 400 && response.status < 500;
            throw error;
        }
        return result.data;
    }

    function add(button, data, container, form) {
        if (button.dataset.koopoBusy) return;
        if (uncertain) { cartLink(message(container, cfg.uncertain)); return; }
        clearTimeout(timers.get(button));
        const original = button.dataset.koopoOriginal || button.innerHTML;
        button.dataset.koopoOriginal = original;
        button.dataset.koopoBusy = '1';
        button.style.minWidth = `${Math.ceil(button.getBoundingClientRect().width)}px`;
        button.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-disabled', 'true');
        button.classList.remove('koopo-cart-added');
        button.classList.add('koopo-cart-loading');
        button.textContent = cfg.adding;
        message(container, '');
        // Serialize all Koopo mutations in this document. Do not retry an ambiguous write.
        queue = queue.then(async () => {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 25000);
            let ok = false;
            try {
                if (uncertain) throw new Error(cfg.uncertain);
                const result = await request(cfg.addUrl, data, controller.signal);
                ok = result.added;
                const notice = ok ? message(container, cfg.added) : message(container, result.notices, true);
                cartLink(notice);
                if (result.added_ids.length) {
                    // Elementor requires the originating jQuery button, as does the Woo event contract.
                    // An extension's UI listener must not turn a confirmed addition into an ambiguous write.
                    try {
                        $(document.body).trigger('added_to_cart', [result.fragments, result.cart_hash, $(button)]);
                    } catch (error) {
                        console.error('Koopo cart: a cart UI listener failed.', error);
                        $.each(result.fragments, (selector, html) => $(selector).replaceWith(html));
                    }
                    // Clear only successfully added grouped rows, so a partial retry cannot add them twice.
                    if (form?.classList.contains('grouped_form')) {
                        result.added_ids.forEach(id => {
                            const input = form.elements.namedItem(`quantity[${id}]`);
                            if (input) {
                                if (input.type === 'checkbox') input.checked = false;
                                else input.value = '0';
                            }
                        });
                    }
                }
            } catch (error) {
                uncertain = !error.rejected;
                cartLink(message(container, error.rejected ? error.message : cfg.uncertain));
            } finally {
                clearTimeout(timeout);
                delete button.dataset.koopoBusy;
                button.removeAttribute('aria-busy');
                button.removeAttribute('aria-disabled');
                button.classList.remove('koopo-cart-loading');
                button.classList.remove('added');
                button.classList.toggle('koopo-cart-added', ok);
                button.innerHTML = ok ? '' : original;
                if (ok) {
                    button.textContent = cfg.added;
                    timers.set(button, setTimeout(() => {
                        button.innerHTML = original;
                        button.classList.remove('koopo-cart-added');
                    }, 1800));
                }
            }
        });
    }

    function modal() {
        if (dialog) return;
        dialog = document.createElement('dialog');
        dialog.className = 'koopo-quick-view woocommerce';
        dialog.setAttribute('aria-label', cfg.viewProduct);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'koopo-qv-close';
        close.setAttribute('aria-label', cfg.close);
        close.textContent = '×';
        close.addEventListener('click', () => dialog.close());
        content = document.createElement('div');
        content.className = 'koopo-qv-content';
        backButton = document.createElement('button');
        backButton.type = 'button';
        backButton.className = 'koopo-qv-back';
        backButton.textContent = cfg.back;
        backButton.hidden = true;
        backButton.addEventListener('click', () => {
            if (content.querySelector('[data-koopo-busy]')) return;
            viewRequest?.abort();
            const previous = viewStack.pop();
            if (!previous) return;
            content.replaceChildren(...previous.nodes);
            dialog.setAttribute('aria-labelledby', 'koopo-qv-title');
            backButton.hidden = viewStack.length === 0;
            previous.button.focus();
        });
        dialog.append(close, backButton, content);
        document.body.append(dialog);
        dialog.addEventListener('click', e => {
            if (e.target !== dialog) return;
            const rect = dialog.getBoundingClientRect();
            if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) dialog.close();
        });
        dialog.addEventListener('close', () => {
            viewRequest?.abort();
            document.body.classList.remove('koopo-qv-open');
            viewStack.length = 0;
            backButton.hidden = true;
            if (opener?.isConnected) opener.focus();
        });
    }

    async function view(button) {
        if (!window.HTMLDialogElement || !HTMLDialogElement.prototype.showModal) {
            window.location.assign(button.href);
            return;
        }
        modal();
        if (content.querySelector('[data-koopo-busy]')) return;
        viewRequest?.abort();
        const controller = new AbortController();
        viewRequest = controller;
        if (!dialog.open) opener = button;
        else if (content.contains(button)) {
            viewStack.push({ nodes: Array.from(content.childNodes), button });
            backButton.hidden = false;
        }
        dialog.removeAttribute('aria-labelledby');
        content.replaceChildren();
        message(content, cfg.loading);
        if (!dialog.open) dialog.showModal();
        else if (!backButton.hidden) backButton.focus();
        document.body.classList.add('koopo-qv-open');
        const data = new FormData();
        data.set('koopo_product_id', button.dataset.koopoProduct);
        try {
            const result = await request(cfg.viewUrl, data, controller.signal);
            if (controller.signal.aborted) return;
            content.innerHTML = result.html;
            dialog.setAttribute('aria-labelledby', 'koopo-qv-title');
            $(content).find('.variations_form').each(function () {
                $(this).wc_variation_form();
            });
            $(content).off('.koopoCart').on('found_variation.koopoCart', '.variations_form', function (event, variation) {
                const img = content.querySelector('.koopo-qv-image img');
                if (img && variation.image?.src) {
                    if (!img.dataset.originalSrc) {
                        img.dataset.originalSrc = img.src;
                        img.dataset.originalSrcset = img.srcset;
                    }
                    img.src = variation.image.src;
                    img.srcset = variation.image.srcset || '';
                }
            }).on('reset_data.koopoCart', '.variations_form', function () {
                const img = content.querySelector('.koopo-qv-image img');
                if (img?.dataset.originalSrc) {
                    img.src = img.dataset.originalSrc;
                    img.srcset = img.dataset.originalSrcset;
                }
            });
        } catch (error) {
            if (controller.signal.aborted) return;
            const node = message(content, cfg.loadError);
            const link = document.createElement('a');
            link.href = button.href;
            link.textContent = cfg.viewProduct;
            node.append(link);
        }
    }

    // Capture only server-marked classic controls, before Woo's delegated click handler.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-koopo-cart]');
        if (!button || button.classList.contains('wc-interactive') || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (button.dataset.koopoCart === 'view') { view(button); return; }
        const data = new FormData();
        data.set('koopo_product_id', button.dataset.koopoProduct);
        data.set('quantity', button.dataset.quantity || '1');
        add(button, data, button.parentElement);
    }, true);

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('form.cart') || !form.querySelector('[name="koopo_product_id"]')) return;
        const button = event.submitter || form.querySelector('.single_add_to_cart_button');
        if (!button) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!form.reportValidity()) return;
        if (button.classList.contains('disabled') || button.disabled) {
            message(form, cfg.options);
            return;
        }
        const data = new FormData(form);
        data.delete('add-to-cart');
        add(button, data, form, form);
    }, true);
})(jQuery);
