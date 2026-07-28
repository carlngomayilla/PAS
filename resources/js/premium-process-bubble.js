const root = document.querySelector('[data-process-bubble]');

function createProcessBubble(element) {
  if (!element) return null;

  const title = element.querySelector('[data-process-title]');
  const message = element.querySelector('[data-process-message]');
  const eyebrow = element.querySelector('[data-process-eyebrow]');
  const bar = element.querySelector('[data-process-progress-bar]');
  const label = element.querySelector('[data-process-progress-label]');
  const steps = element.querySelector('[data-process-steps]');
  let closeTimer = null;
  let latencyTimer = null;

  const update = (payload = {}) => {
    const progress = Math.min(100, Math.max(0, Number(payload.progress ?? 12)));
    const status = payload.status || (progress >= 90 ? 'almost_done' : 'running');
    const defaultCopy = {
      pending: ['Préparation', 'Votre demande démarre.'],
      running: ['Traitement en cours', 'Analyse et vérification des données.'],
      almost_done: ['Presque terminé', 'Dernières vérifications.'],
      success: ['Terminé', 'Le traitement est terminé.'],
      error: ['Traitement interrompu', 'Vérifiez les données puis réessayez.'],
    }[status] || ['Traitement en cours', 'Analyse et vérification des données.'];

    element.dataset.status = status;
    eyebrow.textContent = payload.eyebrow || 'Traitement sécurisé';
    title.textContent = payload.title || defaultCopy[0];
    message.textContent = payload.message || defaultCopy[1];
    bar.style.width = `${progress}%`;
    label.textContent = `${Math.round(progress)} %`;

    const logs = Array.isArray(payload.logs) ? payload.logs.slice(-3) : [];
    steps.replaceChildren(...logs.map((entry) => {
      const row = document.createElement('div');
      row.className = `premium-process-step is-${entry.type || 'info'}`;
      const dot = document.createElement('span');
      dot.className = 'premium-process-step-dot';
      dot.setAttribute('aria-hidden', 'true');
      const copy = document.createElement('span');
      copy.textContent = entry.message || '';
      row.append(dot, copy);
      return row;
    }));
    steps.hidden = logs.length === 0;

    if (status === 'success' || status === 'error') {
      window.clearTimeout(closeTimer);
      closeTimer = window.setTimeout(hide, status === 'success' ? 1600 : 4500);
    }
  };

  const show = (payload = {}) => {
    window.clearTimeout(closeTimer);
    window.clearTimeout(latencyTimer);
    element.classList.remove('hidden');
    element.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => element.classList.add('is-visible'));
    update(payload);
    latencyTimer = window.setTimeout(() => update({
      status: 'running',
      progress: 35,
      title: 'Analyse en cours',
      message: 'Le traitement continue en arrière-plan.',
    }), 8000);
  };

  function hide() {
    window.clearTimeout(latencyTimer);
    element.classList.remove('is-visible');
    window.setTimeout(() => {
      element.classList.add('hidden');
      element.setAttribute('aria-hidden', 'true');
    }, 240);
  }

  element.querySelector('[data-process-close]')?.addEventListener('click', hide);

  return { show, update, hide };
}

function boot() {
  const processBubble = createProcessBubble(root || document.querySelector('[data-process-bubble]'));
  if (!processBubble) return;

  window.AnBGProcess = processBubble;

  document.querySelectorAll(
    'form[data-premium-loading], form[enctype="multipart/form-data"], form[action*="/ai-"], form[action*="/import"]',
  ).forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;

      processBubble.show({
        status: 'pending',
        progress: 10,
        title: form.dataset.loadingTitle || 'Traitement en cours',
        message: form.dataset.loadingMessage || 'Veuillez patienter quelques instants.',
      });
    });
  });

  document.addEventListener('anbg:process-updated', (event) => processBubble.update(event.detail || {}));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
