import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

function refreshPtaSuiviUi() {
  document.dispatchEvent(new Event('anbg:page-soft-refreshed'));
}

describe('PTA tracking modal', () => {
  beforeAll(async () => {
    await import('../../resources/js/pta-suivi-ui.js');
  });

  beforeEach(() => {
    document.body.innerHTML = `
      <form id="pta-suivi-filter-form">
        <select name="direction_id"><option value="all">Toutes</option><option value="1">Direction 1</option></select>
        <select name="service_id"><option value="all">Tous</option><option value="2" selected>Service 2</option></select>
      </form>
      <a href="/workspace/pta-suivi/actions/1" data-pta-action-open data-url="/workspace/pta-suivi/actions/1">Action</a>
      <div class="pta-suivi-modal-backdrop" data-pta-modal aria-hidden="true" inert>
        <div class="pta-suivi-modal" role="dialog" tabindex="-1">
          <button type="button" data-pta-modal-close>Fermer</button>
          <div data-pta-modal-body></div>
        </div>
      </div>
    `;
    window.AnBGLoader = { finish: vi.fn(), start: vi.fn(() => 'loader') };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      text: async () => '<a href="/preuve">Preuve</a>',
    }));
    refreshPtaSuiviUi();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
  });

  it('opens scoped action details, restores focus and resets the dependent service filter', async () => {
    const trigger = document.querySelector('[data-pta-action-open]');
    const modal = document.querySelector('[data-pta-modal]');
    const direction = document.querySelector('[name="direction_id"]');
    const service = document.querySelector('[name="service_id"]');

    trigger.click();
    await Promise.resolve();
    await Promise.resolve();

    expect(fetch).toHaveBeenCalledWith('/workspace/pta-suivi/actions/1', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(modal.classList.contains('is-open')).toBe(true);
    expect(modal.getAttribute('aria-hidden')).toBe('false');
    expect(modal.inert).toBe(false);
    expect(document.body.classList.contains('pta-modal-open')).toBe(true);
    expect(modal.querySelector('[data-pta-modal-body]').textContent).toContain('Preuve');

    direction.value = '1';
    direction.dispatchEvent(new Event('change', { bubbles: true }));
    expect(service.value).toBe('all');

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(modal.classList.contains('is-open')).toBe(false);
    expect(modal.inert).toBe(true);
    expect(document.body.classList.contains('pta-modal-open')).toBe(false);
    expect(document.activeElement).toBe(trigger);
  });
});
