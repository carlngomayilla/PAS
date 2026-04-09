const SUPER_ADMIN_PATH_SEGMENT = '/workspace/super-admin';

const previewState = {
  form: null,
  submitter: null,
  modal: null,
  body: null,
  title: null,
  subtitle: null,
};

const eligibleSuperAdminPage = () => window.location.pathname.includes(SUPER_ADMIN_PATH_SEGMENT);

const eligibleSuperAdminForm = (form) => {
  if (!(form instanceof HTMLFormElement)) {
    return false;
  }

  const method = (form.getAttribute('method') || 'get').toLowerCase();
  if (method === 'get') {
    return false;
  }

  return form.dataset.previewDisabled !== 'true';
};

const truncatePreviewValue = (value) => (value.length > 120 ? `${value.slice(0, 117)}...` : value);

const previewLabelForField = (field) => {
  if (!(field instanceof HTMLElement)) {
    return 'Champ';
  }

  const id = field.getAttribute('id');
  if (id) {
    const explicit = document.querySelector(`label[for="${CSS.escape(id)}"]`);
    if (explicit instanceof HTMLElement) {
      return explicit.textContent?.trim() || 'Champ';
    }
  }

  const wrapped = field.closest('label');
  if (wrapped instanceof HTMLElement) {
    const clone = wrapped.cloneNode(true);
    clone.querySelectorAll('input, select, textarea, button').forEach((node) => node.remove());
    return clone.textContent?.trim() || 'Champ';
  }

  return field.getAttribute('name') || 'Champ';
};

const previewValueForField = (field) => {
  if (
    !(field instanceof HTMLInputElement) &&
    !(field instanceof HTMLTextAreaElement) &&
    !(field instanceof HTMLSelectElement)
  ) {
    return null;
  }

  if (!field.name || field.disabled || field.name === '_token' || field.name === '_method') {
    return null;
  }

  if (field instanceof HTMLInputElement) {
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button') {
      return null;
    }

    if (field.type === 'checkbox' || field.type === 'radio') {
      if (!field.checked) {
        return null;
      }

      return 'Oui';
    }

    if (field.type === 'file') {
      if (!field.files || field.files.length === 0) {
        return null;
      }

      return Array.from(field.files).map((file) => file.name).join(', ');
    }
  }

  if (field instanceof HTMLSelectElement) {
    if (field.multiple) {
      const values = Array.from(field.selectedOptions).map((option) => option.textContent?.trim()).filter(Boolean);
      return values.length > 0 ? values.join(', ') : null;
    }

    return field.selectedOptions[0]?.textContent?.trim() || null;
  }

  const value = field.value.trim();
  return value === '' ? null : truncatePreviewValue(value);
};

const previewSectionTitle = (section) => {
  if (!(section instanceof HTMLElement)) {
    return 'Details';
  }

  const title = section.querySelector('.form-section-title, h2, h3, legend');
  return title instanceof HTMLElement ? (title.textContent?.trim() || 'Details') : 'Details';
};

const buildPreviewSections = (form) => {
  const sectionNodes = Array.from(form.querySelectorAll('.form-section'));
  const sections = [];

  if (sectionNodes.length === 0) {
    const fields = Array.from(form.querySelectorAll('input, select, textarea'));
    const rows = fields
      .map((field) => {
        const value = previewValueForField(field);
        if (!value) {
          return null;
        }

        return {
          label: previewLabelForField(field),
          value,
        };
      })
      .filter(Boolean);

    if (rows.length > 0) {
      sections.push({ title: 'Modifications', rows });
    }

    return sections;
  }

  sectionNodes.forEach((section) => {
    const rows = Array.from(section.querySelectorAll('input, select, textarea'))
      .map((field) => {
        const value = previewValueForField(field);
        if (!value) {
          return null;
        }

        return {
          label: previewLabelForField(field),
          value,
        };
      })
      .filter(Boolean);

    if (rows.length > 0) {
      sections.push({
        title: previewSectionTitle(section),
        rows,
      });
    }
  });

  return sections;
};

const closePreviewModal = () => {
  if (!(previewState.modal instanceof HTMLElement)) {
    return;
  }

  previewState.modal.classList.add('hidden');
  document.body.classList.remove('super-admin-preview-open');
  previewState.form = null;
  previewState.submitter = null;
};

const ensurePreviewModal = () => {
  if (previewState.modal instanceof HTMLElement) {
    return;
  }

  const modal = document.createElement('div');
  modal.className = 'super-admin-preview-modal hidden';
  modal.innerHTML = `
    <div class="super-admin-preview-backdrop" data-preview-close="1"></div>
    <div class="super-admin-preview-panel" role="dialog" aria-modal="true" aria-labelledby="super-admin-preview-title">
      <div class="super-admin-preview-head">
        <div>
          <p class="super-admin-preview-eyebrow">Apercu avant validation</p>
          <h2 id="super-admin-preview-title" class="super-admin-preview-title">Verification</h2>
          <p class="super-admin-preview-subtitle"></p>
        </div>
        <button type="button" class="super-admin-preview-close" data-preview-close="1" aria-label="Fermer">×</button>
      </div>
      <div class="super-admin-preview-body"></div>
      <div class="super-admin-preview-actions">
        <button type="button" class="btn btn-secondary" data-preview-close="1">Annuler</button>
        <button type="button" class="btn btn-primary" data-preview-confirm="1">Confirmer</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  previewState.modal = modal;
  previewState.body = modal.querySelector('.super-admin-preview-body');
  previewState.title = modal.querySelector('.super-admin-preview-title');
  previewState.subtitle = modal.querySelector('.super-admin-preview-subtitle');

  modal.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }

    if (target.dataset.previewClose === '1') {
      closePreviewModal();
    }

    if (target.dataset.previewConfirm === '1' && previewState.form instanceof HTMLFormElement) {
      previewState.form.dataset.previewConfirmed = '1';
      closePreviewModal();

      if (previewState.submitter instanceof HTMLElement && typeof previewState.form.requestSubmit === 'function') {
        previewState.form.requestSubmit(previewState.submitter);
        return;
      }

      previewState.form.submit();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && previewState.modal instanceof HTMLElement && !previewState.modal.classList.contains('hidden')) {
      closePreviewModal();
    }
  });
};

const renderPreviewModal = (form, submitter) => {
  ensurePreviewModal();

  if (
    !(previewState.modal instanceof HTMLElement) ||
    !(previewState.body instanceof HTMLElement) ||
    !(previewState.title instanceof HTMLElement) ||
    !(previewState.subtitle instanceof HTMLElement)
  ) {
    return;
  }

  const actionLabel = (submitter?.textContent || 'Enregistrer').trim();
  const sections = buildPreviewSections(form);

  previewState.form = form;
  previewState.submitter = submitter;
  previewState.title.textContent = actionLabel;
  previewState.subtitle.textContent = sections.length > 0
    ? 'Controle les valeurs avant application.'
    : 'Cette action ne contient pas de champs detailles. Verifie simplement l intention.';

  if (sections.length === 0) {
    previewState.body.innerHTML = `
      <div class="super-admin-preview-empty">
        <p>Action : <strong>${actionLabel}</strong></p>
        <p>Formulaire : <code>${form.getAttribute('action') || window.location.pathname}</code></p>
      </div>
    `;
  } else {
    previewState.body.innerHTML = sections.map((section) => `
      <section class="super-admin-preview-section">
        <h3>${section.title}</h3>
        <dl>
          ${section.rows.map((row) => `
            <div class="super-admin-preview-row">
              <dt>${row.label}</dt>
              <dd>${row.value}</dd>
            </div>
          `).join('')}
        </dl>
      </section>
    `).join('');
  }

  previewState.modal.classList.remove('hidden');
  document.body.classList.add('super-admin-preview-open');
};

const initSuperAdminPreview = () => {
  if (!eligibleSuperAdminPage()) {
    return;
  }

  document.querySelectorAll('form').forEach((form) => {
    if (!eligibleSuperAdminForm(form)) {
      return;
    }

    form.addEventListener('submit', (event) => {
      if (form.dataset.previewConfirmed === '1') {
        delete form.dataset.previewConfirmed;
        return;
      }

      event.preventDefault();

      const submitter = event.submitter instanceof HTMLElement
        ? event.submitter
        : form.querySelector('button[type="submit"], input[type="submit"]');

      renderPreviewModal(form, submitter);
    });
  });
};

initSuperAdminPreview();
