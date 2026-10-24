(function () {
  'use strict';

  const config = window.KOOPO_CREATOR_ONBOARDING || {};
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function activateChoice(choice) {
    const checked = choice.querySelector('.koopo-creator-choice__main input');
    const primary = choice.querySelector('.koopo-creator-primary input');
    choice.classList.toggle('is-selected', Boolean(checked && checked.checked));
    if (primary) {
      primary.disabled = !checked.checked;
      if (!checked.checked) primary.checked = false;
    }
  }

  function wireChoices(root) {
    root.querySelectorAll('.koopo-creator-choice').forEach((choice) => {
      activateChoice(choice);
      choice.querySelector('.koopo-creator-choice__main input')?.addEventListener('change', () => {
        activateChoice(choice);
        const selected = root.querySelectorAll('.koopo-creator-choice__main input:checked');
        const primary = root.querySelector('.koopo-creator-primary input:checked');
        if (!primary && selected.length === 1) {
          choice.querySelector('.koopo-creator-primary input').checked = true;
        }
      });
    });
  }

  function setupModal(entry) {
    const modal = entry.querySelector('[data-koopo-creator-modal]');
    const open = entry.querySelector('[data-koopo-creator-open]');
    if (!modal || !open) return;
    const panel = modal.querySelector('[role="dialog"]');
    let previousFocus = null;

    function openModal() {
      previousFocus = document.activeElement;
      modal.hidden = false;
      document.documentElement.classList.add('koopo-creator-modal-open');
      requestAnimationFrame(() => {
        modal.classList.add('is-open');
        panel?.focus();
      });
    }
    function closeModal() {
      modal.classList.remove('is-open');
      document.documentElement.classList.remove('koopo-creator-modal-open');
      const finish = () => { modal.hidden = true; previousFocus?.focus(); };
      if (reduceMotion) finish(); else window.setTimeout(finish, 180);
    }
    open.addEventListener('click', openModal);
    modal.querySelectorAll('[data-koopo-creator-close]').forEach((button) => button.addEventListener('click', closeModal));
    modal.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeModal();
      if (event.key !== 'Tab') return;
      const focusable = Array.from(modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),textarea:not([disabled]),[tabindex="0"]')).filter((node) => node.offsetParent !== null);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    setupExistingUserForm(modal.querySelector('[data-koopo-creator-form]'));
  }

  function setupExistingUserForm(form) {
    if (!form) return;
    const panels = Array.from(form.querySelectorAll('[data-koopo-creator-panel]'));
    const back = form.querySelector('[data-koopo-creator-back]');
    const next = form.querySelector('[data-koopo-creator-next]');
    const submit = form.querySelector('[data-koopo-creator-submit]');
    const count = form.querySelector('[data-koopo-creator-count]');
    const progress = form.querySelector('[data-koopo-creator-progress]');
    const status = form.querySelector('[data-koopo-creator-status]');
    const accountTypes = form.querySelectorAll('[name="account_type"]');
    const companyField = form.querySelector('[data-koopo-company-field]');
    let current = 0;
    wireChoices(form);

    function syncAccountType() {
      const business = form.querySelector('[name="account_type"]:checked')?.value === 'business';
      companyField.hidden = !business;
      companyField.querySelector('input').required = business;
    }
    accountTypes.forEach((field) => field.addEventListener('change', syncAccountType));
    syncAccountType();

    function show(index, focus) {
      current = Math.max(0, Math.min(index, panels.length - 1));
      panels.forEach((panel, panelIndex) => {
        panel.hidden = panelIndex !== current;
        panel.classList.toggle('is-current', panelIndex === current);
      });
      back.hidden = current === 0;
      next.hidden = current === panels.length - 1;
      submit.hidden = current !== panels.length - 1;
      count.textContent = `Step ${current + 1} of ${panels.length}`;
      progress.style.width = `${((current + 1) / panels.length) * 100}%`;
      if (focus) panels[current].querySelector('h2')?.focus({ preventScroll: true });
    }

    function validatePanel() {
      if (current === 1) {
        const content = form.querySelectorAll('[name="koopo_creator_content_types[]"]:checked');
        const primary = form.querySelector('[name="koopo_creator_primary_goal"]:checked');
        if (!content.length) {
          form.querySelector('.koopo-creator-choice__main input').setCustomValidity('Choose at least one creator path.');
          form.querySelector('.koopo-creator-choice__main input').reportValidity();
          return false;
        }
        form.querySelector('.koopo-creator-choice__main input').setCustomValidity('');
        if (!primary) {
          const firstPrimary = form.querySelector('.koopo-creator-primary input:not([disabled])');
          firstPrimary.setCustomValidity('Choose which creator path should be your first stop.');
          firstPrimary.reportValidity();
          return false;
        }
        form.querySelectorAll('.koopo-creator-primary input').forEach((field) => field.setCustomValidity(''));
      }
      for (const field of panels[current].querySelectorAll('input,textarea')) {
        if (!field.checkValidity()) { field.reportValidity(); return false; }
      }
      return true;
    }

    next.addEventListener('click', () => { if (validatePanel()) show(current + 1, true); });
    back.addEventListener('click', () => show(current - 1, true));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!validatePanel()) return;
      submit.disabled = true;
      form.classList.add('is-busy');
      status.textContent = 'Preparing your creator studio…';
      status.className = 'koopo-creator-status';
      const values = new FormData(form);
      const payload = {
        first_name: values.get('first_name') || '',
        last_name: values.get('last_name') || '',
        date_of_birth: values.get('date_of_birth') || '',
        account_type: values.get('account_type') || '',
        company_name: values.get('company_name') || '',
        content_types: values.getAll('koopo_creator_content_types[]'),
        primary_goal: values.get('koopo_creator_primary_goal') || '',
        affiliate_interest: values.has('affiliate_interest'),
        experience: values.get('experience') || ''
      };
      try {
        if (payload.date_of_birth && config.agePolicyActive) {
          const ageResponse = await fetch(config.ageConfirmationUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
            body: JSON.stringify({ date_of_birth: payload.date_of_birth })
          });
          const ageData = await ageResponse.json();
          if (!ageResponse.ok) throw new Error(ageData.error || ageData.message || 'Your date of birth could not be confirmed.');
        }
        const response = await fetch(config.restUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
          body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Creator setup could not be completed.');
        status.textContent = 'You’re in. Opening your creator tools…';
        status.classList.add('is-success');
        window.location.assign(data.destination_url);
      } catch (error) {
        status.textContent = error.message || 'Creator setup could not be completed. Please try again.';
        status.classList.add('is-error');
        submit.disabled = false;
        form.classList.remove('is-busy');
      }
    });
    show(0);
  }

  function setupSignup() {
    if (!document.body.classList.contains('koopo-creator-signup')) return;
    const form = document.querySelector('#signup-form');
    if (!form || form.dataset.koopoCreatorEnhanced) return;
    form.dataset.koopoCreatorEnhanced = '1';
    const candidates = [
      form.querySelector('#basic-details-section'),
      form.querySelector('#profile-details-section'),
      form.querySelector('#koopo-creator-goals'),
      form.querySelector('#koopo-creator-opportunity')
    ].filter(Boolean);
    if (candidates.length < 3) return;
    wireChoices(form);
    let current = candidates.findIndex((panel) => panel.querySelector('.error'));
    if (current < 0) current = 0;
    const shell = document.createElement('div');
    shell.className = 'koopo-creator-signup-shell';
    shell.innerHTML = '<aside class="koopo-creator-signup-rail"><a href="/" class="koopo-creator-brand">Koopo <small>Creator Studio</small></a><div><span>Creator onboarding</span><h1>Make something worth following.</h1><p>Build your account, choose your formats, and land in the right place to start creating.</p></div><ol></ol></aside><main class="koopo-creator-signup-stage"><div class="koopo-creator-signup-mobile"></div><div class="koopo-creator-signup-slot"></div><footer class="koopo-creator-signup-actions"><button type="button" data-signup-back>Back</button><button type="button" data-signup-next>Continue</button></footer></main>';
    const originalParent = form.parentNode;
    originalParent.insertBefore(shell, form);
    shell.querySelector('.koopo-creator-signup-slot').appendChild(form);
    const list = shell.querySelector('ol');
    const labels = ['Account', 'Profile', 'Creator paths', 'Opportunity'].slice(0, candidates.length);
    labels.forEach((label, index) => { list.insertAdjacentHTML('beforeend', `<li><span>${index + 1}</span><b>${label}</b></li>`); });
    const back = shell.querySelector('[data-signup-back]');
    const next = shell.querySelector('[data-signup-next]');
    const actions = shell.querySelector('.koopo-creator-signup-actions');
    const submit = form.querySelector('[type="submit"]');
    if (submit) {
      if (!form.id) form.id = 'koopo-creator-signup-form';
      submit.setAttribute('form', form.id);
      actions.appendChild(submit.closest('.submit') || submit);
    }
    const legal = form.querySelector('.register-privacy-info, .register-page .legal-agreement');
    if (legal) candidates[candidates.length - 1].appendChild(legal);

    function show(index, focus) {
      current = Math.max(0, Math.min(index, candidates.length - 1));
      candidates.forEach((panel, panelIndex) => { panel.hidden = panelIndex !== current; });
      list.querySelectorAll('li').forEach((item, itemIndex) => {
        item.classList.toggle('is-current', itemIndex === current);
        item.classList.toggle('is-complete', itemIndex < current);
      });
      shell.querySelector('.koopo-creator-signup-mobile').textContent = `Step ${current + 1} of ${candidates.length} · ${labels[current]}`;
      back.hidden = current === 0;
      next.hidden = current === candidates.length - 1;
      if (submit) (submit.closest('.submit') || submit).hidden = current !== candidates.length - 1;
      if (focus) candidates[current].querySelector('h2,legend')?.focus({ preventScroll: true });
    }
    function valid() {
      if (candidates[current].id === 'koopo-creator-goals') {
        const choices = candidates[current].querySelectorAll('[name="koopo_creator_content_types[]"]:checked');
        const primary = candidates[current].querySelector('[name="koopo_creator_primary_goal"]:checked');
        if (!choices.length || !primary) {
          const target = candidates[current].querySelector('input:not([disabled])');
          target.setCustomValidity(!choices.length ? 'Choose at least one creator path.' : 'Choose your first creator destination.');
          target.reportValidity(); return false;
        }
      }
      for (const field of candidates[current].querySelectorAll('input,select,textarea')) {
        field.setCustomValidity('');
        if (!field.checkValidity()) { field.reportValidity(); return false; }
      }
      return true;
    }
    next.addEventListener('click', () => { if (valid()) show(current + 1, true); });
    back.addEventListener('click', () => show(current - 1, true));
    form.addEventListener('invalid', (event) => {
      const index = candidates.findIndex((panel) => panel.contains(event.target));
      if (index >= 0 && index !== current) show(index);
    }, true);
    show(current);
  }

  function boot() {
    document.querySelectorAll('[data-koopo-creator-entry]').forEach(setupModal);
    document.addEventListener('click', (event) => {
      const legacyTrigger = event.target.closest && event.target.closest('#newInfluencer');
      if (!legacyTrigger) return;
      const mount = document.querySelector('[data-koopo-creator-square-mount]');
      if (!mount) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      const creatorOpen = mount.querySelector('[data-koopo-creator-open]');
      const creatorDestination = mount.querySelector('.koopo-creator-welcome a');
      if (creatorOpen) creatorOpen.click();
      else if (creatorDestination) window.location.assign(creatorDestination.href);
    }, true);
    setupSignup();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
