const PTA_MODAL_SELECTOR = '[data-pta-modal]';
const PTA_MODAL_OPEN_SELECTOR = '[data-pta-action-open]';
const PTA_FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function bindPtaSuiviModal() {
  const modal = document.querySelector(PTA_MODAL_SELECTOR);

  if (!(modal instanceof HTMLElement) || modal.dataset.ptaModalReady === '1') {
    return;
  }

  const dialog = modal.querySelector('[role="dialog"]');
  const modalBody = modal.querySelector('[data-pta-modal-body]');
  const closeButton = modal.querySelector('[data-pta-modal-close]');

  if (!(dialog instanceof HTMLElement) || !(modalBody instanceof HTMLElement)) {
    return;
  }

  modal.dataset.ptaModalReady = '1';
    modal.inert = true;
  let activeTrigger = null;
  let requestSequence = 0;

  const closeModal = () => {
    const trigger = activeTrigger;

    requestSequence += 1;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    modalBody.removeAttribute('aria-busy');
    modalBody.replaceChildren();
    document.body.classList.remove('pta-modal-open');
    activeTrigger = null;

    if (trigger instanceof HTMLElement && trigger.isConnected) {
      trigger.focus();
    }
  };

  const openModal = async (trigger) => {
    const url = trigger.dataset.url;

    if (!url) {
      return;
    }

    activeTrigger = trigger;
    const requestId = ++requestSequence;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    modal.inert = false;
    modalBody.setAttribute('aria-busy', 'true');
    modalBody.innerHTML = '<div class="pta-suivi-modal-loading" role="status">Chargement du détail de l’action…</div>';
    document.body.classList.add('pta-modal-open');
    closeButton?.focus();

    const loaderToken = window.AnBGLoader?.start({
      operation: 'load',
      message: 'Chargement du détail de l’action…',
    });

    try {
      const response = await fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });

      if (!response.ok) {
        throw new Error('Erreur de chargement');
      }

      const content = await response.text();

      if (requestId !== requestSequence || activeTrigger !== trigger) {
        return;
      }

      modalBody.innerHTML = content;
    } catch {
      if (requestId !== requestSequence || activeTrigger !== trigger) {
        return;
      }

      modalBody.innerHTML = '<div class="pta-suivi-modal-error" role="alert">Impossible de charger le détail de cette action.</div>';
    } finally {
      if (requestId === requestSequence && activeTrigger === trigger) {
        modalBody.removeAttribute('aria-busy');
      }

      window.AnBGLoader?.finish(loaderToken);
    }
  };

  document.addEventListener('click', (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const trigger = target.closest(PTA_MODAL_OPEN_SELECTOR);

    if (trigger instanceof HTMLElement) {
      event.preventDefault();
      void openModal(trigger);
    }
  });

  closeButton?.addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (!modal.classList.contains('is-open')) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
      return;
    }

    if (event.key !== 'Tab') {
      return;
    }

    const focusableElements = Array.from(dialog.querySelectorAll(PTA_FOCUSABLE_SELECTOR))
      .filter((element) => element instanceof HTMLElement && !element.hasAttribute('hidden'));

    if (focusableElements.length === 0) {
      event.preventDefault();
      dialog.focus();
      return;
    }

    const firstFocusable = focusableElements[0];
    const lastFocusable = focusableElements[focusableElements.length - 1];

    if (event.shiftKey && document.activeElement === firstFocusable) {
      event.preventDefault();
      lastFocusable.focus();
    } else if (!event.shiftKey && document.activeElement === lastFocusable) {
      event.preventDefault();
      firstFocusable.focus();
    }
  });
}

function bindPtaSuiviFilters() {
  const form = document.getElementById('pta-suivi-filter-form');

  if (!(form instanceof HTMLFormElement) || form.dataset.ptaSuiviFiltersReady === '1') {
    return;
  }

  const directionSelect = form.elements.namedItem('direction_id');
  const serviceSelect = form.elements.namedItem('service_id');

  if (!(directionSelect instanceof HTMLSelectElement) || !(serviceSelect instanceof HTMLSelectElement)) {
    return;
  }

  form.dataset.ptaSuiviFiltersReady = '1';
  directionSelect.addEventListener('change', () => {
    serviceSelect.value = 'all';
  });
}

function initPtaSuiviUi() {
  bindPtaSuiviModal();
  bindPtaSuiviFilters();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPtaSuiviUi, { once: true });
} else {
  initPtaSuiviUi();
}

document.addEventListener('anbg:page-soft-refreshed', initPtaSuiviUi);
