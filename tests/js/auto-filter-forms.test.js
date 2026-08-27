import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

function dispatchPageShow(persisted) {
  const event = new Event('pageshow');
  Object.defineProperty(event, 'persisted', { value: persisted });
  window.dispatchEvent(event);
}

describe('dynamic filter forms', () => {
  beforeAll(async () => {
    await import('../../resources/js/auto-filter-forms.js');
  });

  beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = `
      <form data-auto-filter-form data-auto-filter-submitting="1" aria-busy="true">
        <input name="query" type="search" value="initial">
        <select name="status">
          <option value="">Tous</option>
          <option value="active">Actif</option>
        </select>
        <select name="service_id" disabled>
          <option value="">Tous</option>
        </select>
        <button
          type="reset"
          disabled
          aria-busy="true"
          data-global-loader-locked="true"
        >
          Réinitialiser
        </button>
        <input
          type="hidden"
          name="submitter"
          value="filter"
          data-global-loader-submitter-mirror="true"
        >
      </form>
    `;
    window.AnBGAutoFilters.refresh();
  });

  afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  it('restores a BFCache form and keeps auto-submit and reset usable', () => {
    const form = document.querySelector('form');
    const query = form.elements.namedItem('query');
    const status = form.elements.namedItem('status');
    const service = form.elements.namedItem('service_id');
    const resetButton = form.querySelector('button[type="reset"]');
    const requestSubmit = vi.fn();
    form.requestSubmit = requestSubmit;

    query.value = 'pending';
    query.dispatchEvent(new Event('input', { bubbles: true }));
    query.dataset.isComposing = '1';

    expect(vi.getTimerCount()).toBe(1);

    dispatchPageShow(true);

    expect(form.dataset.autoFilterSubmitting).toBeUndefined();
    expect(form.hasAttribute('aria-busy')).toBe(false);
    expect(query.dataset.isComposing).toBeUndefined();
    expect(resetButton.disabled).toBe(false);
    expect(resetButton.hasAttribute('aria-busy')).toBe(false);
    expect(resetButton.dataset.globalLoaderLocked).toBeUndefined();
    expect(service.disabled).toBe(true);
    expect(form.querySelector('[data-global-loader-submitter-mirror]')).toBeNull();
    expect(vi.getTimerCount()).toBe(0);

    vi.advanceTimersByTime(500);
    expect(requestSubmit).not.toHaveBeenCalled();

    resetButton.click();
    expect(query.value).toBe('initial');
    expect(requestSubmit).not.toHaveBeenCalled();

    status.value = 'active';
    status.dispatchEvent(new Event('change', { bubbles: true }));
    vi.runOnlyPendingTimers();

    expect(requestSubmit).toHaveBeenCalledOnce();
    expect(form.dataset.autoFilterSubmitting).toBe('1');
    expect(form.getAttribute('aria-busy')).toBe('true');
  });

  it('also clears stale submission state on a regular pageshow', () => {
    const form = document.querySelector('form');

    dispatchPageShow(false);

    expect(form.dataset.autoFilterSubmitting).toBeUndefined();
    expect(form.hasAttribute('aria-busy')).toBe(false);
  });

  it('cancels a pending debounce when the page is hidden', () => {
    const form = document.querySelector('form');
    const query = form.elements.namedItem('query');
    const requestSubmit = vi.fn();
    form.requestSubmit = requestSubmit;

    query.value = 'pending';
    query.dispatchEvent(new Event('input', { bubbles: true }));
    expect(vi.getTimerCount()).toBe(1);

    window.dispatchEvent(new Event('pagehide'));
    expect(vi.getTimerCount()).toBe(0);

    vi.advanceTimersByTime(500);
    expect(requestSubmit).not.toHaveBeenCalled();
  });
});
