// @vitest-environment node

import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const styles = readFileSync(new URL('../../resources/css/ui-system.css', import.meta.url), 'utf8');
const layout = readFileSync(new URL('../../resources/views/layouts/admin.blade.php', import.meta.url), 'utf8');
const commandCenter = readFileSync(
  new URL('../../resources/views/dashboard/partials/command-center.blade.php', import.meta.url),
  'utf8',
);
const hierarchy = readFileSync(
  new URL('../../resources/views/partials/dashboard-analytics/_panel-synthesis-hierarchy.blade.php', import.meta.url),
  'utf8',
);

describe('institutional design system', () => {
  it('uses one semantic contract for light and dark surfaces', () => {
    expect(styles).toContain('--ui-canvas: #f2f7fb;');
    expect(styles).toContain('--ui-surface: #ffffff;');
    expect(styles).toContain('html.dark {');
    expect(styles).toContain('--ui-canvas: #091725;');
    expect(styles).toContain('--ui-surface: #112438;');
    expect(styles).toContain('data-ui-version="institutional-v2"');
  });

  it('supports keyboard focus, mobile layouts, and reduced motion', () => {
    expect(styles).toContain(':focus-visible');
    expect(styles).toContain('@media (max-width: 47.99rem)');
    expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
    expect(styles).toContain('table[data-mobile-cards]');
  });

  it('exposes search and an accessible theme toggle in the shared shell', () => {
    expect(layout).toContain('id="admin-spotlight-open"');
    expect(layout).toContain('aria-haspopup="dialog"');
    expect(layout).toContain('aria-pressed="false"');
    expect(layout).not.toContain('<style>');
  });

  it('prioritizes decisions before deep strategic details', () => {
    expect(commandCenter).toContain('data-dashboard-insight-zone');
    expect(commandCenter).toContain('À traiter aujourd’hui');
    expect(commandCenter).toContain('data-flow-state=');
    expect(hierarchy).toContain('Dépliez uniquement le niveau à analyser');
    expect(hierarchy).toContain('dashboard-action-facts');
  });
});
