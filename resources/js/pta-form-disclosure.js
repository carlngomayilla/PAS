/** Reveal invalid action fields before the browser tries to focus them. */
export function revealInvalidActionField(field) {
    if (!(field instanceof HTMLElement) || !field.closest('.pta-registration-screen [data-action-block]')) {
        return;
    }

    let ancestor = field.parentElement;
    while (ancestor) {
        if (ancestor instanceof HTMLDetailsElement) {
            ancestor.open = true;
        }
        ancestor = ancestor.parentElement;
    }
}

document.addEventListener('invalid', (event) => revealInvalidActionField(event.target), true);
