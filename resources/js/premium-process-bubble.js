const SELECTOR = '[data-process-bubble]';

const escapeHtml = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const stateCopy = {
  pending: ['Initialisation', 'Préparation sécurisée du traitement.'],
  running: ['Traitement en cours', 'Les données sont analysées et contrôlées.'],
  almost_done: ['Presque terminé', 'Dernières vérifications avant validation.'],
  success: ['Traitement terminé', 'Votre demande a été traitée avec succès.'],
  error: ['Traitement interrompu', 'Vérifiez les informations puis réessayez.'],
};

function controller() {
  const root = document.querySelector(SELECTOR);
  if (!root) return null;

  const title = root.querySelector('[data-process-title]');
  const message = root.querySelector('[data-process-message]');
  const eyebrow = root.querySelector('[data-process-eyebrow]');
  const bar = root.querySelector('[data-process-progress-bar]');
  const label = root.querySelector('[data-process-progress-label]');
  const steps = root.querySelector('[data-process-steps]');
  let timeoutHandle = null;
  let latencyHandle = null;
  let activeChannel = null;

  const update = (payload = {}) => {
    const progress = Math.min(100, Math.max(0, Number(payload.progress ?? 0)));
    const status = payload.status || (progress >= 90 ? 'almost_done' : 'running');
    const copy = stateCopy[status] || stateCopy.running;

    root.dataset.status = status;
    title.textContent = payload.title || copy[0];
    message.textContent = payload.message || copy[1];
    eyebrow.textContent = payload.eyebrow || (status === 'running' ? 'IA en analyse' : 'Traitement sécurisé');
    bar.style.width = `${progress}%`;
    label.textContent = `${Math.round(progress)} %`;

    if (Array.isArray(payload.logs)) {
      steps.innerHTML = payload.logs.slice(-5).map((entry) => (
        `<div class="premium-process-step is-${escapeHtml(entry.type || 'info')}">`
        + `<span class="premium-process-step-dot" aria-hidden="true"></span>`
        + `<span>${escapeHtml(entry.message || '')}</span></div>`
      )).join('');
    }

    if (status === 'success' || status === 'error') {
      window.clearTimeout(timeoutHandle);
      timeoutHandle = window.setTimeout(() => hide(), status === 'success' ? 1800 : 5000);
    }
  };

  const show = (payload = {}) => {
    root.classList.remove('hidden');
    root.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => root.classList.add('is-visible'));
    update(payload);
    window.clearTimeout(latencyHandle);
    latencyHandle = window.setTimeout(() => {
      if (!['success', 'error'].includes(root.dataset.status)) {
        update({
          status: 'running',
          progress: Number(label.textContent.replace(/\D/g, '')) || 18,
          title: 'Analyse approfondie',
          message: 'Le traitement prend un peu plus de temps. Vous pouvez laisser cette fenêtre ouverte.',
        });
      }
    }, 8000);
  };

  const hide = () => {
    window.clearTimeout(latencyHandle);
    root.classList.remove('is-visible');
    window.setTimeout(() => {
      root.classList.add('hidden');
      root.setAttribute('aria-hidden', 'true');
    }, 360);
  };

  root.querySelector('[data-process-close]')?.addEventListener('click', hide);

  const subscribe = ({ trackingId, userId }) => {
    if (!window.Echo || !Number.isInteger(Number(trackingId)) || !Number.isInteger(Number(userId))) {
      return false;
    }

    const channelName = `user.${Number(userId)}.process.${Number(trackingId)}`;
    if (activeChannel) window.Echo.leave(activeChannel);
    activeChannel = channelName;
    window.Echo.private(channelName).listen('.process.updated', (payload) => update(payload));

    return true;
  };

  return { show, update, hide, subscribe };
}

document.addEventListener('DOMContentLoaded', () => {
  const processBubble = controller();
  if (!processBubble) return;

  window.AnBGProcess = processBubble;

  document.querySelectorAll(
    'form[data-premium-loading], form[enctype="multipart/form-data"], form[action*="/ai-"], form[action*="/import"]',
  ).forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity()) return;

      processBubble.show({
        status: 'pending',
        progress: 8,
        title: form.dataset.loadingTitle || 'Envoi en cours',
        message: form.dataset.loadingMessage || 'Votre demande est sécurisée avant traitement.',
      });
    });
  });

  document.addEventListener('anbg:process-updated', (event) => processBubble.update(event.detail || {}));
});
