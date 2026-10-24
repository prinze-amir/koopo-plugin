/* Enqueue koopo-location to use the shared service in signed-in web forms. */
(function (window) {
  'use strict';
  const config = window.koopoLocationConfig || {};
  const apiFetch = window.wp && window.wp.apiFetch;
  if (apiFetch && config.root) apiFetch.use(apiFetch.createRootURLMiddleware(config.root));
  if (apiFetch && config.nonce) apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
  const request = (operation, input, signal) => {
    if (config.gateway && (operation === 'autocomplete' || (operation === 'details' && String(input.id || '').startsWith('gw_')))) {
      return fetch(config.gateway.replace(/\/+$/, '') + '/api/v1/location/' + operation, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input), signal, credentials: 'omit', cache: 'no-store' }).then(async (response) => {
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Location services unavailable');
        return data;
      });
    }
    if (!apiFetch) return Promise.reject(new Error('Location API client unavailable'));
    return apiFetch({ path: '/koopo/v1/location/' + operation, method: 'POST', data: input, signal });
  };
  window.KoopoLocation = Object.freeze({
    geocode: (address, signal) => request('geocode', { address }, signal),
    reverse: (latitude, longitude, signal) => request('reverse', { latitude, longitude }, signal),
    autocomplete: (query, options = {}, signal) => request('autocomplete', { ...options, query }, signal),
    search: (query, options = {}, signal) => request('search', { ...options, query }, signal),
    details: (id, signal) => request('details', { id }, signal),
  });
})(window);
