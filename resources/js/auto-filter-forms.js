const AUTO_FILTER_FORM_SELECTOR = 'form[data-auto-filter-form]';
const TEXT_FILTER_TYPES = new Set(['search', 'text', 'email', 'number', 'tel', 'url']);
const submitTimers = new WeakMap();

function clearScheduledSubmission(form) {
  const timer = submitTimers.get(form);

  if (timer === undefined) {
    return;
  }

  window.clearTimeout(timer);
  submitTimers.delete(form);
}

function resetAutoFilterFormState(form) {
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  clearScheduledSubmission(form);
  delete form.dataset.autoFilterSubmitting;
  delete form.dataset.globalLoaderSubmitting;
  form.removeAttribute('aria-busy');

  form.querySelectorAll('[data-is-composing="1"]').forEach((control) => {
    delete control.dataset.isComposing;
  });

  form.querySelectorAll([
    '[data-global-loader-locked="true"]',
    'button[disabled][aria-busy="true"]',
    'input[type="submit"][disabled][aria-busy="true"]',
    'input[type="reset"][disabled][aria-busy="true"]',
  ].join(',')).forEach((control) => {
    control.disabled = false;
    control.removeAttribute('aria-busy');
    delete control.dataset.globalLoaderLocked;
  });

  form.querySelectorAll('[data-global-loader-submitter-mirror]').forEach((mirror) => mirror.remove());
}

function resetAutoFilterForms() {
  document.querySelectorAll(AUTO_FILTER_FORM_SELECTOR).forEach(resetAutoFilterFormState);
}

function submitFilterForm(form) {
  clearScheduledSubmission(form);

  if (!form.checkValidity() || form.dataset.autoFilterSubmitting === '1') {
    return;
  }

  form.dataset.autoFilterSubmitting = '1';
  form.setAttribute('aria-busy', 'true');

  if (typeof form.requestSubmit === 'function') {
    form.requestSubmit();
    return;
  }

  HTMLFormElement.prototype.submit.call(form);
}

function scheduleSubmission(form, delay = 0) {
  clearScheduledSubmission(form);

  const timer = window.setTimeout(() => submitFilterForm(form), delay);
  submitTimers.set(form, timer);
}

function resetSelect(form, name) {
  const select = form.elements.namedItem(name);

  if (!(select instanceof HTMLSelectElement)) {
    return;
  }

  const defaultOption = Array.from(select.options).find((option) => ['', 'all'].includes(option.value));

  if (defaultOption) {
    select.value = defaultOption.value;
  }
}

function resetDependentFilters(control, form) {
  if (!(control instanceof HTMLSelectElement)) {
    return;
  }

  if (control.name === 'direction_id') {
    resetSelect(form, 'service_id');
    resetSelect(form, 'responsable_id');
    resetSelect(form, 'responsible_id');
  }

  if (control.name === 'service_id') {
    resetSelect(form, 'responsable_id');
    resetSelect(form, 'responsible_id');
  }
}

function isTextFilter(control) {
  return control instanceof HTMLTextAreaElement
    || (control instanceof HTMLInputElement && TEXT_FILTER_TYPES.has(control.type));
}

function bindAutoFilterForm(form) {
  if (!(form instanceof HTMLFormElement) || form.dataset.autoFilterReady === '1') {
    return;
  }

  form.dataset.autoFilterReady = '1';

  form.addEventListener('change', (event) => {
    const control = event.target;

    if (!(control instanceof HTMLInputElement)
      && !(control instanceof HTMLSelectElement)
      && !(control instanceof HTMLTextAreaElement)) {
      return;
    }

    if (control.disabled || isTextFilter(control)) {
      return;
    }

    resetDependentFilters(control, form);
    scheduleSubmission(form);
  });

  form.addEventListener('input', (event) => {
    const control = event.target;

    if (!isTextFilter(control) || control.disabled || control.dataset.isComposing === '1') {
      return;
    }

    scheduleSubmission(form, 450);
  });

  form.addEventListener('compositionstart', (event) => {
    if (isTextFilter(event.target)) {
      event.target.dataset.isComposing = '1';
    }
  });

  form.addEventListener('compositionend', (event) => {
    if (!isTextFilter(event.target)) {
      return;
    }

    delete event.target.dataset.isComposing;
    scheduleSubmission(form, 450);
  });

  form.addEventListener('submit', () => {
    clearScheduledSubmission(form);
    form.dataset.autoFilterSubmitting = '1';
    form.setAttribute('aria-busy', 'true');
  });
}

function initAutoFilterForms() {
  document.querySelectorAll(AUTO_FILTER_FORM_SELECTOR).forEach(bindAutoFilterForm);
}

window.AnBGAutoFilters = {
  refresh: initAutoFilterForms,
  reset: resetAutoFilterForms,
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAutoFilterForms, { once: true });
} else {
  initAutoFilterForms();
}

document.addEventListener('anbg:page-soft-refreshed', initAutoFilterForms);
window.addEventListener('pageshow', resetAutoFilterForms);
window.addEventListener('pagehide', () => {
  document.querySelectorAll(AUTO_FILTER_FORM_SELECTOR).forEach(clearScheduledSubmission);
});
