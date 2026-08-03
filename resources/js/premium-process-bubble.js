const root = document.querySelector('[data-global-loader]');

const operationMessages = {
  default: 'Traitement en cours…',
  save: 'Enregistrement des données…',
  upload: 'Téléversement du fichier…',
  import: 'Import et analyse du fichier…',
  export: 'Génération du fichier…',
  report: 'Génération du rapport…',
  publish: 'Publication en cours…',
  validate: 'Validation en cours…',
  delete: 'Suppression en cours…',
};

function inferOperation(form) {
  const haystack = [
    form.action,
    form.dataset.loadingTitle,
    form.dataset.loadingMessage,
    form.textContent,
  ].filter(Boolean).join(' ').toLowerCase();

  if (form.enctype === 'multipart/form-data' || form.querySelector('input[type="file"]')) return 'upload';
  if (/import|analyse/.test(haystack)) return 'import';
  if (/export|pdf|excel|word|télécharg|telecharg/.test(haystack)) return 'export';
  if (/rapport|report/.test(haystack)) return 'report';
  if (/publi/.test(haystack)) return 'publish';
  if (/valid|approb/.test(haystack)) return 'validate';
  if (/supprim|delete|destroy/.test(haystack)) return 'delete';

  return 'save';
}

function inferLinkOperation(link) {
  const haystack = [link.href, link.dataset.loadingMessage, link.textContent]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();

  if (/rapport|report/.test(haystack)) return 'report';

  return 'export';
}

function createGlobalLoader(element) {
  if (!element) return null;

  const message = element.querySelector('[data-global-loader-message]');
  const status = element.querySelector('[data-global-loader-status]');
  const pageRoot = document.querySelector('.admin-page-root');
  const activeOperations = new Map();
  let sequence = 0;
  let wasActive = false;

  const normalizePayload = (payload = {}) => {
    if (typeof payload === 'string') return { message: payload };

    return payload || {};
  };

  const render = () => {
    const latest = Array.from(activeOperations.values()).at(-1);
    const isActive = activeOperations.size > 0;

    element.classList.toggle('hidden', !isActive);
    element.classList.toggle('is-visible', isActive);
    element.setAttribute('aria-hidden', isActive ? 'false' : 'true');
    element.dataset.activeOperations = String(activeOperations.size);
    if (isActive) {
      document.body.setAttribute('aria-busy', 'true');
      pageRoot?.setAttribute('inert', '');
    } else {
      document.body.removeAttribute('aria-busy');
      pageRoot?.removeAttribute('inert');
    }

    if (latest && message) {
      message.textContent = latest.message || operationMessages.default;
    }

    if (isActive && !wasActive) {
      window.requestAnimationFrame(() => status?.focus({ preventScroll: true }));
    }

    wasActive = isActive;
  };

  const start = (payload = {}) => {
    const options = normalizePayload(payload);
    const token = `operation-${++sequence}`;
    activeOperations.set(token, {
      message: options.message || options.title || operationMessages[options.operation] || operationMessages.default,
    });
    render();

    return token;
  };

  const update = (tokenOrPayload, maybePayload = {}) => {
    let token = tokenOrPayload;
    let payload = normalizePayload(maybePayload);

    if (typeof tokenOrPayload !== 'string' || !activeOperations.has(tokenOrPayload)) {
      token = Array.from(activeOperations.keys()).at(-1);
      payload = normalizePayload(tokenOrPayload);
    }

    if (!token || !activeOperations.has(token)) return;

    const current = activeOperations.get(token);
    activeOperations.set(token, {
      ...current,
      message: payload.message || payload.title || current.message,
    });
    render();
  };

  const finish = (token) => {
    const resolvedToken = token || Array.from(activeOperations.keys()).at(-1);
    if (!resolvedToken) return;

    activeOperations.delete(resolvedToken);
    render();
  };

  const reset = () => {
    activeOperations.clear();
    document.querySelectorAll('[data-global-loader-locked="true"]').forEach((control) => {
      control.disabled = false;
      control.removeAttribute('aria-busy');
      delete control.dataset.globalLoaderLocked;
    });
    document.querySelectorAll('[data-global-loader-submitter-mirror]').forEach((mirror) => mirror.remove());
    document.querySelectorAll('form[data-global-loader-submitting="true"]').forEach((form) => {
      delete form.dataset.globalLoaderSubmitting;
      form.removeAttribute('aria-busy');
    });
    render();
  };

  const beginFormSubmission = (form, submitter = null) => {
    if (!(form instanceof HTMLFormElement)) return null;
    if (form.dataset.globalLoaderSubmitting === 'true') return null;

    const operation = form.dataset.loadingOperation || inferOperation(form);
    const token = start({
      operation,
      message: form.dataset.loadingMessage || operationMessages[operation],
    });

    form.dataset.globalLoaderSubmitting = 'true';
    form.setAttribute('aria-busy', 'true');

    const control = submitter instanceof HTMLElement
      ? submitter
      : form.querySelector('button[type="submit"], input[type="submit"]');

    if (control && 'disabled' in control) {
      if (control.name) {
        const mirror = document.createElement('input');
        mirror.type = 'hidden';
        mirror.name = control.name;
        mirror.value = control.value;
        mirror.dataset.globalLoaderSubmitterMirror = 'true';
        form.append(mirror);
      }

      control.disabled = true;
      control.setAttribute('aria-busy', 'true');
      control.dataset.globalLoaderLocked = 'true';
    }

    return token;
  };

  const track = (promiseOrCallback, payload = {}) => {
    const token = start(payload);
    let operation;

    try {
      operation = typeof promiseOrCallback === 'function' ? promiseOrCallback() : promiseOrCallback;
    } catch (error) {
      finish(token);
      throw error;
    }

    return Promise.resolve(operation).finally(() => finish(token));
  };

  render();

  return {
    start,
    begin: start,
    show: start,
    update,
    finish,
    end: finish,
    hide: finish,
    reset,
    track,
    run: track,
    beginFormSubmission,
    get activeCount() {
      return activeOperations.size;
    },
  };
}

function boot() {
  const loader = createGlobalLoader(root || document.querySelector('[data-global-loader]'));
  if (!loader) return;

  window.AnBGLoader = loader;
  window.AnBGGlobalLoader = loader;
  window.AnBGProcess = loader;

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.noGlobalLoader !== undefined) return;
    if ((form.method || 'get').toLowerCase() === 'get') return;

    if (form.dataset.globalLoaderSubmitting === 'true') {
      event.preventDefault();
      return;
    }

    const submitter = event.submitter;
    window.queueMicrotask(() => {
      if (event.defaultPrevented || !form.checkValidity()) return;
      loader.beginFormSubmission(form, submitter);
    });
  });

  document.addEventListener('click', (event) => {
    const link = event.target.closest([
      'a[data-global-loader]',
      'a[data-global-download]',
      'a[download]',
      'a[href*="/export"]',
      'a[href*="/exports"]',
      'a[href*="/pdf"]',
      'a[href*="/excel"]',
      'a[href*="/word"]',
    ].join(', '));
    if (!link || link.dataset.noGlobalLoader !== undefined || link.getAttribute('aria-busy') === 'true') return;
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const operation = link.dataset.loadingOperation || inferLinkOperation(link);
    event.preventDefault();
    link.setAttribute('aria-busy', 'true');

    loader.run(async () => {
      const response = await window.fetch(link.href, {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/octet-stream,application/pdf,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.wordprocessingml.document;q=0.9,*/*;q=0.8',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) throw new Error(`Téléchargement impossible (${response.status}).`);

      const contentType = response.headers.get('Content-Type') || '';
      if (contentType.includes('text/html')) {
        window.location.assign(response.url || link.href);
        return;
      }

      const disposition = response.headers.get('Content-Disposition') || '';
      const utf8Name = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const simpleName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const pathnameName = new URL(response.url || link.href, window.location.href).pathname.split('/').filter(Boolean).at(-1);
      const filename = decodeURIComponent(utf8Name || simpleName || pathnameName || 'export');
      const objectUrl = URL.createObjectURL(await response.blob());
      const download = document.createElement('a');
      download.href = objectUrl;
      download.download = filename;
      download.hidden = true;
      document.body.append(download);
      download.click();
      download.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    }, {
      operation,
      message: link.dataset.loadingMessage || operationMessages[operation],
    }).catch(() => {
      window.location.assign(link.href);
    }).finally(() => {
      link.removeAttribute('aria-busy');
    });
  });

  document.addEventListener('anbg:loader-start', (event) => {
    const token = loader.start(event.detail || {});
    if (event.detail && typeof event.detail === 'object') event.detail.token = token;
  });
  document.addEventListener('anbg:loader-update', (event) => loader.update(event.detail || {}));
  document.addEventListener('anbg:loader-finish', (event) => loader.finish(event.detail?.token));
  document.addEventListener('anbg:process-updated', (event) => loader.update(event.detail || {}));

  window.addEventListener('pageshow', loader.reset);
  window.addEventListener('pagehide', loader.reset);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
