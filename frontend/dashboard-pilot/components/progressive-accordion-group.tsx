'use client';

import {
    type ReactNode,
    useEffect,
    useRef,
} from 'react';

export function ProgressiveAccordionGroup({ children }: { children: ReactNode }) {
    const groupReference = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const group = groupReference.current;

        if (!group) {
            return;
        }

        const accordionGroup: HTMLDivElement = group;

        function closeSiblingPanels(event: Event): void {
            const openedPanel = event.target;

            if (!(openedPanel instanceof HTMLDetailsElement) || !openedPanel.open) {
                return;
            }

            accordionGroup
                .querySelectorAll<HTMLDetailsElement>('details[data-progressive-section]')
                .forEach((panel) => {
                    if (panel !== openedPanel) {
                        panel.open = false;
                    }
                });
        }

        accordionGroup.addEventListener('toggle', closeSiblingPanels, true);

        return () => accordionGroup.removeEventListener('toggle', closeSiblingPanels, true);
    }, []);

    return (
        <div
            className="flex flex-col gap-4"
            ref={groupReference}
        >
            {children}
        </div>
    );
}
