import { Page } from '@playwright/test';

export type UiAuditIssue = {
    category: string;
    severity: 'high' | 'medium' | 'low';
    selector: string;
    detail: string;
};

export async function auditRenderedPage(page: Page): Promise<UiAuditIssue[]> {
    return page.evaluate((): UiAuditIssue[] => {
        const issues: UiAuditIssue[] = [];
        const maximumIssues = 500;

        function add(category: string, severity: UiAuditIssue['severity'], element: Element | null, detail: string): void {
            if (issues.length >= maximumIssues) {
                return;
            }

            issues.push({ category, severity, selector: selectorFor(element), detail });
        }

        function selectorFor(element: Element | null): string {
            if (! element) {
                return 'document';
            }

            if (element.id) {
                return `#${CSS.escape(element.id)}`;
            }

            const parts: string[] = [];
            let current: Element | null = element;
            while (current && parts.length < 4 && current !== document.documentElement) {
                let part = current.tagName.toLowerCase();
                const stableClasses = Array.from(current.classList)
                    .filter(className => ! /[:/\[\]#]/.test(className))
                    .slice(0, 2);
                if (stableClasses.length > 0) {
                    part += `.${stableClasses.map(className => CSS.escape(className)).join('.')}`;
                }

                const siblings = current.parentElement
                    ? Array.from(current.parentElement.children).filter(sibling => sibling.tagName === current?.tagName)
                    : [];
                if (siblings.length > 1) {
                    part += `:nth-of-type(${siblings.indexOf(current) + 1})`;
                }

                parts.unshift(part);
                current = current.parentElement;
            }

            return parts.join(' > ');
        }

        function isVisible(element: Element): boolean {
            if (element.closest('[inert]')) {
                return false;
            }

            const closedDetails = element.closest('details:not([open])');
            if (closedDetails && ! element.closest('summary')) {
                return false;
            }

            if (element.closest('[hidden]')) {
                return false;
            }

            const style = window.getComputedStyle(element);
            const rectangle = element.getBoundingClientRect();

            return style.display !== 'none'
                && style.visibility !== 'hidden'
                && Number(style.opacity) > 0
                && rectangle.width > 0
                && rectangle.height > 0;
        }

        function pointIsInsideClippingAncestors(element: Element, x: number, y: number): boolean {
            let current = element.parentElement;

            while (current && current !== document.documentElement) {
                const style = window.getComputedStyle(current);
                const rectangle = current.getBoundingClientRect();
                const clipsX = ['auto', 'clip', 'hidden', 'scroll'].includes(style.overflowX);
                const clipsY = ['auto', 'clip', 'hidden', 'scroll'].includes(style.overflowY);

                if ((clipsX && (x < rectangle.left || x > rectangle.right))
                    || (clipsY && (y < rectangle.top || y > rectangle.bottom))) {
                    return false;
                }

                current = current.parentElement;
            }

            return true;
        }

        function accessibleName(element: Element): string {
            const labelledBy = element.getAttribute('aria-labelledby');
            const labelledText = labelledBy
                ? labelledBy.split(/\s+/).map(id => document.getElementById(id)?.textContent ?? '').join(' ')
                : '';
            const nativeLabels = element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement
                ? Array.from(element.labels ?? []).map(label => label.textContent ?? '').join(' ')
                : '';

            return [
                element.getAttribute('aria-label'),
                labelledText,
                nativeLabels,
                element.getAttribute('title'),
                element.textContent,
                element instanceof HTMLInputElement ? element.value : '',
            ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
        }

        function parseRgb(value: string): [number, number, number, number] | null {
            const match = value.match(/rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+))?\s*\)/i);
            if (! match) {
                return null;
            }

            return [Number(match[1]), Number(match[2]), Number(match[3]), match[4] === undefined ? 1 : Number(match[4])];
        }

        function opaqueBackground(element: Element): [number, number, number] | null {
            let current: Element | null = element;
            while (current) {
                const style = window.getComputedStyle(current);
                if (style.backgroundImage !== 'none') {
                    return null;
                }

                const color = parseRgb(style.backgroundColor);
                if (color && color[3] >= 0.95) {
                    return [color[0], color[1], color[2]];
                }

                current = current.parentElement;
            }

            return document.documentElement.classList.contains('dark') ? [15, 23, 42] : [255, 255, 255];
        }

        function luminance(rgb: [number, number, number]): number {
            const channels = rgb.map(channel => {
                const value = channel / 255;

                return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
            });

            return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
        }

        function contrastRatio(foreground: [number, number, number], background: [number, number, number]): number {
            const light = Math.max(luminance(foreground), luminance(background));
            const dark = Math.min(luminance(foreground), luminance(background));

            return (light + 0.05) / (dark + 0.05);
        }

        if (! document.documentElement.lang) {
            add('document-language', 'medium', document.documentElement, 'La langue du document n’est pas déclarée.');
        }

        if (! document.title.trim()) {
            add('document-title', 'medium', document.head, 'Le titre de page est vide.');
        }

        if (document.documentElement.scrollWidth > window.innerWidth + 2) {
            add('horizontal-overflow', 'high', document.documentElement, `Largeur document ${document.documentElement.scrollWidth}px pour un viewport de ${window.innerWidth}px.`);
        }

        const ids = new Map<string, Element[]>();
        document.querySelectorAll('[id]').forEach(element => {
            const values = ids.get(element.id) ?? [];
            values.push(element);
            ids.set(element.id, values);
        });
        ids.forEach((elements, id) => {
            if (elements.length > 1) {
                add('duplicate-id', 'high', elements[1], `L’identifiant #${id} apparaît ${elements.length} fois.`);
            }
        });

        const mainLandmarks = Array.from(document.querySelectorAll('main, [role="main"]')).filter(isVisible);
        if (mainLandmarks.length !== 1) {
            add('main-landmark', 'medium', mainLandmarks[0] ?? document.body, `${mainLandmarks.length} repère(s) principal(aux) visible(s), un seul attendu.`);
        }

        const visibleHeadings = Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6')).filter(isVisible);
        const h1Headings = visibleHeadings.filter(heading => heading.tagName === 'H1');
        if (h1Headings.length !== 1) {
            add('heading-h1', 'medium', h1Headings[0] ?? document.body, `${h1Headings.length} titre(s) H1 visible(s), un seul attendu.`);
        }
        let previousLevel = 0;
        visibleHeadings.forEach(heading => {
            const level = Number(heading.tagName.substring(1));
            if (previousLevel > 0 && level > previousLevel + 1) {
                add('heading-order', 'low', heading, `Passage de H${previousLevel} à H${level}.`);
            }
            previousLevel = level;
        });

        document.querySelectorAll('img').forEach(image => {
            if (isVisible(image) && ! image.hasAttribute('alt')) {
                add('image-alt', 'medium', image, 'Image visible sans attribut alt, même vide.');
            }
        });

        document.querySelectorAll('iframe').forEach(frame => {
            if (isVisible(frame) && ! accessibleName(frame)) {
                add('iframe-title', 'medium', frame, 'Cadre visible sans nom accessible.');
            }
        });

        const actionableSelector = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, [role="button"], [role="link"], [tabindex]';
        document.querySelectorAll(actionableSelector).forEach(element => {
            if (! isVisible(element)) {
                return;
            }

            if (! accessibleName(element)) {
                add('interactive-name', 'high', element, 'Élément interactif visible sans nom accessible.');
            }

            if (element.hasAttribute('tabindex') && Number(element.getAttribute('tabindex')) > 0) {
                add('positive-tabindex', 'medium', element, `tabindex=${element.getAttribute('tabindex')} perturbe l’ordre naturel.`);
            }

            const rectangle = element.getBoundingClientRect();
            if (window.innerWidth < 768 && rectangle.width < 24 && rectangle.height < 24) {
                add('touch-target', 'medium', element, `Cible tactile ${Math.round(rectangle.width)}×${Math.round(rectangle.height)}px, inférieure à 24×24px.`);
            }

            const centerX = rectangle.left + (rectangle.width / 2);
            const centerY = rectangle.top + (rectangle.height / 2);
            if (centerX >= 0
                && centerX <= window.innerWidth
                && centerY >= 0
                && centerY <= window.innerHeight
                && pointIsInsideClippingAncestors(element, centerX, centerY)) {
                const topElement = document.elementFromPoint(centerX, centerY);
                if (topElement && topElement !== element && ! element.contains(topElement) && ! topElement.contains(element)) {
                    add('covered-control', 'high', element, `Centre recouvert par ${selectorFor(topElement)}.`);
                }
            }
        });

        document.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(control => {
            if (! isVisible(control)) {
                return;
            }

            const labelled = control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement
                ? control.labels?.length
                : 0;
            if (! labelled && ! control.getAttribute('aria-label') && ! control.getAttribute('aria-labelledby') && ! control.getAttribute('title')) {
                add('form-label', 'high', control, 'Champ visible sans label associé ni nom ARIA.');
            }
        });

        document.querySelectorAll('[aria-hidden="true"]').forEach(container => {
            const focusable = container.matches(actionableSelector) ? container : container.querySelector(actionableSelector);
            if (focusable && isVisible(focusable)) {
                add('aria-hidden-focus', 'high', focusable, 'Élément focalisable dans une zone aria-hidden=true.');
            }
        });

        document.querySelectorAll('[role="dialog"], dialog').forEach(dialog => {
            if (isVisible(dialog) && ! accessibleName(dialog)) {
                add('dialog-name', 'high', dialog, 'Dialogue visible sans nom accessible.');
            }
        });

        document.querySelectorAll('table').forEach(table => {
            if (isVisible(table) && table.querySelectorAll('th').length === 0) {
                add('table-headers', 'medium', table, 'Tableau visible sans cellule d’en-tête.');
            }
        });

        document.querySelectorAll('a[target="_blank"]').forEach(link => {
            const rel = (link.getAttribute('rel') ?? '').split(/\s+/);
            if (! rel.includes('noopener')) {
                add('external-link-rel', 'medium', link, 'Lien target=_blank sans rel=noopener.');
            }
        });

        document.querySelectorAll('a[href="#"], a[href="javascript:void(0)"]').forEach(link => {
            if (isVisible(link)) {
                add('placeholder-link', 'medium', link, `Lien visible avec destination ${link.getAttribute('href')}.`);
            }
        });

        document.querySelectorAll('body *').forEach(element => {
            if (! isVisible(element)) {
                return;
            }

            const htmlElement = element as HTMLElement;
            const style = window.getComputedStyle(element);
            const ownText = Array.from(element.childNodes)
                .filter(node => node.nodeType === Node.TEXT_NODE)
                .map(node => node.textContent ?? '')
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim();

            const fullTextIsAvailable = [element.getAttribute('title'), element.getAttribute('aria-label')]
                .filter(Boolean)
                .some(label => label?.trim() === ownText);
            if (! fullTextIsAvailable
                && ownText.length > 12
                && htmlElement.scrollWidth > htmlElement.clientWidth + 2
                && ['hidden', 'clip'].includes(style.overflowX)) {
                add('clipped-text', 'low', element, `Texte tronqué : « ${ownText.substring(0, 80)} »`);
            }

            if (! ownText || Number(style.opacity) < 0.5 || style.backgroundImage !== 'none') {
                return;
            }

            const foregroundValue = parseRgb(style.color);
            const background = opaqueBackground(element);
            if (! foregroundValue || foregroundValue[3] < 0.95 || ! background) {
                return;
            }

            const foreground: [number, number, number] = [foregroundValue[0], foregroundValue[1], foregroundValue[2]];
            const ratio = contrastRatio(foreground, background);
            const fontSize = Number.parseFloat(style.fontSize);
            const fontWeight = Number.parseInt(style.fontWeight, 10) || 400;
            const isLarge = fontSize >= 24 || (fontSize >= 18.66 && fontWeight >= 700);
            const minimum = isLarge ? 3 : 4.5;
            if (ratio + 0.05 < minimum) {
                add('contrast-candidate', 'medium', element, `Contraste estimé ${ratio.toFixed(2)}:1, minimum ${minimum}:1, texte rgb(${foreground.join(', ')}), fond rgb(${background.join(', ')}) pour « ${ownText.substring(0, 80)} »`);
            }
        });

        return issues;
    });
}
