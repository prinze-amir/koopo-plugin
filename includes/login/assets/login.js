(() => {
  function setup(root = document) {
    root.querySelectorAll('.kl-form:not([data-kl-ready])').forEach(form => {
      form.dataset.klReady = 'true';
      const reveal = form.querySelector('.kl-show');
      reveal?.addEventListener('click', () => {
        const input = form.querySelector('input[name="pwd"]');
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        reveal.textContent = visible ? 'Hide' : 'Show';
        reveal.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        reveal.setAttribute('aria-pressed', String(visible));
      });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        const button = form.querySelector('.kl-submit');
        button.dataset.label = button.querySelector('span').textContent;
        button.disabled = true;
        button.querySelector('span').textContent = 'Signing in…';
        form.setAttribute('aria-busy', 'true');
        try {
          const response = await fetch(form.dataset.tokenUrl, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            signal: AbortSignal.timeout(15000),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=koopo_login_token',
          });
          const result = await response.json();
          if (!response.ok || !result.success || !result.data?.nonce) throw new Error('token');
          form.querySelector('[name="_koopo_login_nonce"]').value = result.data.nonce;
          HTMLFormElement.prototype.submit.call(form);
        } catch {
          let notice = form.querySelector('.kl-notice');
          if (!notice) {
            notice = document.createElement('div');
            notice.className = 'kl-notice';
            notice.setAttribute('role', 'alert');
            notice.tabIndex = -1;
            form.prepend(notice);
          }
          notice.textContent = 'Unable to prepare sign-in. Please check your connection and try again.';
          notice.focus();
          form.removeAttribute('aria-busy');
          button.disabled = false;
          button.querySelector('span').textContent = button.dataset.label;
        }
      });
      if (form.querySelector('.kl-notice')) form.querySelector('.kl-notice').focus();
    });
    root.querySelectorAll('.kl-social .theChampLogin:not([data-kl-ready])').forEach(provider => {
      provider.dataset.klReady = 'true';
      provider.setAttribute('role', 'button');
      provider.tabIndex = 0;
      provider.setAttribute('aria-label', provider.getAttribute('title') || 'Sign in with social account');
      provider.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); provider.click(); }
      });
    });
    root.querySelectorAll('.kl-visual').forEach(visual => {
      visual.querySelector('.kl-photo-primary')?.setAttribute('aria-hidden', String(visual.dataset.photo === 'community'));
      visual.querySelector('.kl-photo-secondary')?.setAttribute('aria-hidden', String(visual.dataset.photo !== 'community'));
    });
    root.querySelectorAll('.kl-photo-switch button:not([data-kl-ready])').forEach(button => {
      button.dataset.klReady = 'true';
      button.addEventListener('click', () => {
        const visual = button.closest('.kl-visual');
        visual.dataset.photo = button.dataset.photo;
        visual.querySelector('.kl-photo-primary')?.setAttribute('aria-hidden', String(button.dataset.photo === 'community'));
        visual.querySelector('.kl-photo-secondary')?.setAttribute('aria-hidden', String(button.dataset.photo !== 'community'));
        visual.querySelectorAll('.kl-photo-switch button').forEach(choice => choice.setAttribute('aria-pressed', String(choice === button)));
      });
    });
  }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => setup()) : setup();
  window.addEventListener('pageshow', () => document.querySelectorAll('.kl-form').forEach(form => {
    form.removeAttribute('aria-busy');
    const button = form.querySelector('.kl-submit');
    if (button) { button.disabled = false; button.querySelector('span').textContent = button.dataset.label || button.querySelector('span').textContent.replace('Signing in…', 'Sign in'); }
  }));
  // Elementor's editor can recreate widget markup without a full page reload.
  function editorHooks() {
    window.elementorFrontend?.hooks.addAction('frontend/element_ready/koopo-login.default', scope => setup(scope[0]));
  }
  if (window.elementorFrontend) editorHooks();
  else window.jQuery?.(window).on('elementor/frontend/init', editorHooks);
})();
