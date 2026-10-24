/* Shared-provider address autocomplete for classic WooCommerce billing/shipping forms. */
(function ($, window, document) {
  'use strict';
  if (!$ || !window.KoopoLocation || !window.koopoAddressAutocomplete) return;

  const cfg = window.koopoAddressAutocomplete;
  const minimum = Math.max(3, Number(cfg.minimumLength) || 4);
  const messages = cfg.messages || {};
  let activeInput = null;
  const states = new WeakMap();
  const sessionTokens = new WeakMap();
  const newSessionToken = () => window.crypto && window.crypto.randomUUID
    ? window.crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  const sessionToken = (input) => {
    if (!sessionTokens.has(input)) sessionTokens.set(input, newSessionToken());
    return sessionTokens.get(input);
  };
  const stateFor = (input) => {
    if (!states.has(input)) states.set(input, { timer: null, controller: null, sequence: 0 });
    return states.get(input);
  };

  const profilePrefix = (input) => /^(koopo-provider-edit|koopo-provider-area)-address$/.exec(input.id || '')?.[1];
  const isAddressInput = (input) => !!profilePrefix(input) || /^(billing|shipping)_address_1$/.test(input.name || '')
    || /^(billing|shipping)_address_1$/.test(input.id || '');
  const sibling = (input, field) => {
    const profile = profilePrefix(input);
    if (profile) return document.getElementById(profile + '-' + ({state:'region',postcode:'postal'}[field] || field));
    const form = input.closest('form');
    if (!form) return null;
    const prefix = (input.name || input.id || '').split('_address_1')[0];
    return form.querySelector('[name="' + prefix + '_' + field + '"]') || document.getElementById(prefix + '_' + field);
  };
  const ensurePanel = (input) => {
    const parent = input.parentElement;
    let wrap = parent && parent.querySelector(':scope > .koopo-address-autocomplete');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'koopo-address-autocomplete';
      wrap.innerHTML = '<div class="koopo-address-autocomplete__hint"></div><div class="koopo-address-autocomplete__status" role="status" aria-live="polite"></div><ul class="koopo-address-autocomplete__list" role="listbox" hidden></ul><div class="koopo-address-autocomplete__attribution" hidden></div>';
      const hint = wrap.querySelector('.koopo-address-autocomplete__hint');
      hint.id = 'koopo-address-hint-' + Math.random().toString(36).slice(2);
      hint.textContent = messages.hint || 'Type at least 4 characters. Choose a suggestion or continue entering your address manually.';
      input.insertAdjacentElement('afterend', wrap);
    }
    const list = wrap.querySelector('[role="listbox"]');
    if (!list.id) list.id = 'koopo-address-list-' + Math.random().toString(36).slice(2);
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
    const describedBy = new Set((input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    describedBy.add(wrap.querySelector('.koopo-address-autocomplete__hint').id);
    describedBy.add(wrap.querySelector('[role="status"]').id || '');
    describedBy.delete('');
    wrap.querySelector('[role="status"]').id = wrap.querySelector('[role="status"]').id || list.id + '-status';
    describedBy.add(wrap.querySelector('[role="status"]').id);
    input.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
    return { wrap, list, status: wrap.querySelector('[role="status"]'), attribution: wrap.querySelector('.koopo-address-autocomplete__attribution') };
  };
  const setStatus = (panel, text) => { panel.status.textContent = text || ''; };
  const close = (input) => {
    const wrap = input.parentElement && input.parentElement.querySelector(':scope > .koopo-address-autocomplete');
    if (wrap) {
      wrap.querySelector('[role="listbox"]').hidden = true;
      input.setAttribute('aria-expanded', 'false');
      if (wrap.dataset.selectedProvider !== 'acuris') wrap.querySelector('.koopo-address-autocomplete__attribution').hidden = true;
    }
  };
  const setValue = (element, value) => {
    if (!element || value === undefined || value === null || value === '') return;
    const normalized = String(value);
    if (element.tagName === 'SELECT') {
      const option = Array.from(element.options).find((item) => item.value.toLowerCase() === normalized.toLowerCase());
      if (option) { element.value = option.value; $(element).trigger('change'); }
      return;
    }
    element.value = normalized;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const applyAddress = (input, result) => {
    const address = result.address || {};
    const countryField = sibling(input, 'country');
    const stateField = sibling(input, 'state');
    input.dataset.koopoApplying = '1';
    if (address.address_1) setValue(input, address.address_1);
    setValue(sibling(input, 'address_2'), address.address_2);
    setValue(sibling(input, 'city'), address.city);
    setValue(sibling(input, 'postcode'), address.postal_code);
    setValue(countryField, address.country);
    // WooCommerce may rebuild the state list after country changes.
    if (stateField && address.region) {
      if (profilePrefix(input)) setValue(stateField, address.region);
      else window.setTimeout(() => setValue(sibling(input, 'state'), address.region), 120);
    }
    window.setTimeout(() => { delete input.dataset.koopoApplying; }, 180);
    if (profilePrefix(input)) {
      setValue(sibling(input, 'latitude'), result.latitude);
      setValue(sibling(input, 'longitude'), result.longitude);
      delete input.dataset.koopoApplying;
    }
    input.dispatchEvent(new CustomEvent('koopo:address-selected', { bubbles: true, detail: { provider: result.provider || '' } }));
  };
  const attributionLabel = (provider) => {
    if (provider === 'google') return 'Google Maps';
    if (provider === 'geoapify') return 'Powered by Geoapify';
    if (provider === 'osm') return '© OpenStreetMap contributors';
    return messages.attribution || 'Address suggestions';
  };
  const render = (input, response, requestNumber) => {
    if (requestNumber !== stateFor(input).sequence || !input.isConnected) return;
    const panel = ensurePanel(input);
    panel.list.replaceChildren();
    const items = Array.isArray(response.items) ? response.items : [];
    if (!items.length) {
      panel.list.hidden = true;
      setStatus(panel, messages.empty || 'No matching addresses found.');
      input.setAttribute('aria-expanded', 'false');
      return;
    }
    setStatus(panel, '');
    items.forEach((item) => {
      if (!item || !item.id || !item.formatted_address) return;
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.tabIndex = -1;
      li.dataset.selection = item.id;
      li.textContent = item.formatted_address;
      li.addEventListener('mousedown', (event) => event.preventDefault());
      li.addEventListener('click', () => choose(input, item.id, requestNumber));
      li.addEventListener('keydown', (event) => {
        const options = Array.from(panel.list.querySelectorAll('[role="option"]'));
        const current = options.indexOf(li);
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); li.click(); }
        else if (event.key === 'ArrowDown') { event.preventDefault(); options[Math.min(current + 1, options.length - 1)]?.focus(); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); if (current <= 0) input.focus(); else options[current - 1].focus(); }
        else if (event.key === 'Escape') { close(input); input.focus(); }
      });
      panel.list.appendChild(li);
    });
    if (!panel.list.children.length) {
      setStatus(panel, messages.empty || 'No matching addresses found.');
      panel.list.hidden = true;
      return;
    }
    const attr = attributionLabel(response.provider);
    const attribution = panel.attribution;
    attribution.replaceChildren();
    if (response.provider === 'geoapify') {
      const osm = document.createElement('a'); osm.href = 'https://www.openstreetmap.org/copyright'; osm.target = '_blank'; osm.rel = 'noopener noreferrer'; osm.textContent = '© OpenStreetMap contributors';
      const a = document.createElement('a'); a.href = 'https://www.geoapify.com/'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = 'Powered by Geoapify';
      attribution.append(osm, document.createTextNode(' · '), a);
    } else if (response.provider === 'acuris') {
      const a = document.createElement('a'); a.href = 'https://www.acuris-geo.com/'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = 'Address data by Acuris'; attribution.appendChild(a);
    } else attribution.textContent = attr;
    attribution.hidden = false;
    panel.list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  };
  const choose = async (input, id, requestNumber) => {
    const state = stateFor(input);
    if (requestNumber !== state.sequence) return;
    const panel = ensurePanel(input);
    state.controller && state.controller.abort();
    const detailController = new AbortController();
    state.controller = detailController;
    setStatus(panel, messages.loading || 'Searching addresses…');
    panel.list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    try {
      const result = await window.KoopoLocation.details(id, detailController.signal);
      if (requestNumber !== state.sequence || !input.isConnected) return;
      const picked = result.items && result.items[0];
      if (!picked) throw new Error('Address selection returned no result');
      applyAddress(input, { ...picked, provider: result.provider });
      sessionTokens.set(input, newSessionToken());
      setStatus(panel, profilePrefix(input) ? 'Location confirmed. Review the address before saving.' : '');
      panel.wrap.dataset.selectedProvider = result.provider || '';
      // Keep Acuris attribution visible after selection as required for its free commercial use.
      panel.attribution.hidden = result.provider !== 'acuris';
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      setStatus(panel, messages.error || 'Address suggestions are unavailable. You can still enter your address manually.');
    }
  };
  const search = (input) => {
    const query = input.value.trim();
    const panel = ensurePanel(input);
    const state = stateFor(input);
    window.clearTimeout(state.timer);
    state.sequence += 1;
    const requestNumber = state.sequence;
    if (state.controller) state.controller.abort();
    panel.wrap.dataset.selectedProvider = '';
    panel.attribution.hidden = true;
    if (query.length < minimum) {
      panel.list.hidden = true;
      panel.attribution.hidden = true;
      setStatus(panel, '');
      input.setAttribute('aria-expanded', 'false');
      return;
    }
    panel.list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    setStatus(panel, messages.loading || 'Searching addresses…');
    state.timer = window.setTimeout(async () => {
      state.controller = new AbortController();
      const country = sibling(input, 'country');
      const countryCode = /^(United States|USA|US)$/i.test(country?.value || '') ? 'US' : (country ? country.value : '');
      try {
        const response = await window.KoopoLocation.autocomplete(query, { country_code: countryCode, state: sibling(input, 'state')?.value || '', state_name: sibling(input, 'state')?.value ? (sibling(input, 'state')?.selectedOptions?.[0]?.textContent || sibling(input, 'state')?.value) : '', city: sibling(input, 'city')?.value || '', postal_code: sibling(input, 'postcode')?.value || '', limit: 5, session_token: sessionToken(input) }, state.controller.signal);
        render(input, response, requestNumber);
      } catch (error) {
        if (error && error.name === 'AbortError') return;
        if (requestNumber !== state.sequence) return;
        panel.list.hidden = true;
        setStatus(panel, messages.error || 'Address suggestions are temporarily unavailable. You can still enter your address manually.');
        input.setAttribute('aria-expanded', 'false');
      }
    }, 280);
  };
  const attach = (root) => {
    (root || document).querySelectorAll('input').forEach((input) => {
      if (!isAddressInput(input) || input.dataset.koopoAutocomplete === '1') return;
      input.dataset.koopoAutocomplete = '1';
      ensurePanel(input);
      input.addEventListener('input', () => { if (input.dataset.koopoApplying !== '1') search(input); });
      input.addEventListener('keydown', (event) => {
        const panel = ensurePanel(input);
        if (panel.list.hidden) return;
        const options = Array.from(panel.list.querySelectorAll('[role="option"]'));
        let index = options.indexOf(document.activeElement);
        if (event.key === 'ArrowDown') { event.preventDefault(); options[Math.min(index + 1, options.length - 1)]?.focus(); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); if (index <= 0) input.focus(); else options[index - 1].focus(); }
        else if (event.key === 'Escape') { close(input); input.focus(); }
        else if (event.key === 'Enter' && index >= 0) { event.preventDefault(); options[index].click(); }
      });
      input.addEventListener('blur', () => window.setTimeout(() => close(input), 180));
      input.addEventListener('focus', () => { activeInput = input; });
    });
  };
  document.addEventListener('input', (event) => {
    const match = /^(koopo-provider-edit|koopo-provider-area)-(address|city|region|postal|country)$/.exec(event.target.id || '');
    const input = match && document.getElementById(match[1] + '-address');
    if (!input || input.dataset.koopoApplying === '1') return;
    ['latitude','longitude'].forEach(field => { const coordinate = sibling(input,field); if (coordinate) coordinate.value = ''; });
    setStatus(ensurePanel(input), 'Address changed. Choose a suggestion to confirm the updated location.');
  });
  document.addEventListener('change', (event) => {
    const match = /^(billing|shipping)_(country|state|city|postcode)$/.exec(event.target.name || event.target.id || '');
    const profileMatch = /^(koopo-provider-edit|koopo-provider-area)-(city|region|postal|country|address)$/.exec(event.target.id || '');
    if (profileMatch) {
      const addressInput = document.getElementById(profileMatch[1] + '-address');
      if (addressInput && addressInput.dataset.koopoApplying !== '1') {
        ['latitude', 'longitude'].forEach(field => { const coordinate = sibling(addressInput, field); if (coordinate) coordinate.value = ''; });
        setStatus(ensurePanel(addressInput), 'Address changed. Choose a suggestion to confirm the updated location.');
        if (profileMatch[2] !== 'address') search(addressInput);
      }
      return;
    }
    if (!match) return;
    const input = event.target.closest('form')?.querySelector('[name="' + match[1] + '_address_1"]');
    if (input && input.dataset.koopoApplying !== '1') search(input);
  });
  document.addEventListener('DOMContentLoaded', () => attach(document));
  attach(document);
  const observer = new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach((node) => {
    if (node.nodeType === 1) { if (node.matches && node.matches('input')) attach(node.parentElement || document); attach(node); }
  })));
  observer.observe(document.body, { childList: true, subtree: true });
  document.addEventListener('click', (event) => {
    if (activeInput && !event.target.closest('.koopo-address-autocomplete') && event.target !== activeInput) close(activeInput);
  });
  document.addEventListener('focusin', (event) => { if (isAddressInput(event.target)) activeInput = event.target; });
})(window.jQuery, window, document);
