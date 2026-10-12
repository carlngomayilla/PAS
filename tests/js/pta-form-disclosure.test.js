import { beforeEach, describe, expect, it } from 'vitest';
import { revealInvalidActionField } from '../../resources/js/pta-form-disclosure.js';

describe('Action form progressive disclosure', () => {
    beforeEach(() => {
        document.body.innerHTML = `<div class="pta-registration-screen"><details data-action-block>
            <details class="form-step-accordion"><summary>Financement</summary>
                <input required name="actions[0][justificatif_financement]">
            </details><details id="unrelated"><summary>Risques</summary></details>
        </details></div><details id="outside"><input required></details>`;
    });

    it('reveals the action and its invalid section without opening unrelated sections', () => {
        const field = document.querySelector('[name]');
        field.dispatchEvent(new Event('invalid', { bubbles: false, cancelable: true }));
        expect(field.closest('.form-step-accordion').open).toBe(true);
        expect(field.closest('[data-action-block]').open).toBe(true);
        expect(document.getElementById('unrelated').open).toBe(false);
        expect(document.activeElement).not.toBe(field);
    });

    it('leaves unrelated forms and their validation unchanged', () => {
        const field = document.querySelector('#outside input');
        const event = new Event('invalid', { cancelable: true });
        field.dispatchEvent(event);
        expect(document.getElementById('outside').open).toBe(false);
        expect(event.defaultPrevented).toBe(false);
        revealInvalidActionField(null);
    });
});
