// @vitest-environment node

import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const glassCss = readFileSync(new URL('../../resources/css/anbg-glass.css', import.meta.url), 'utf8');

function declarationBlock(css, selector) {
  const selectorOffset = css.indexOf(selector);
  expect(selectorOffset).toBeGreaterThanOrEqual(0);

  const declarationStart = css.indexOf('{', selectorOffset);
  const declarationEnd = css.indexOf('}', declarationStart);

  return css.slice(declarationStart + 1, declarationEnd);
}

describe.each([
  ['default admin theme', appCss, 'body.admin-theme-scope'],
  ['ANBG glass theme', glassCss, 'body.admin-theme-scope.anbg-glass-theme'],
])('%s dashboard hierarchy', (_theme, css, bodySelector) => {
  it('uses dark surfaces for strategic and operational summaries', () => {
    const strategicSelector = `html.dark ${bodySelector} .dashboard-synthesis-hierarchy-card .dashboard-synthesis-node-strategic-objective > .dashboard-synthesis-node-summary`;
    const operationalSelector = `html.dark ${bodySelector} .dashboard-synthesis-hierarchy-card .dashboard-synthesis-node-operational-objective > .dashboard-synthesis-node-summary`;

    expect(declarationBlock(css, strategicSelector)).toContain(
      'background: linear-gradient(135deg, #17345a, #12243a) !important;',
    );
    expect(declarationBlock(css, operationalSelector)).toContain(
      'background: linear-gradient(135deg, #18304b, #111c2f) !important;',
    );
  });

  it('uses readable muted text on the corrected dark surfaces', () => {
    const mutedTextSelector = `html.dark ${bodySelector} .dashboard-synthesis-hierarchy-card :where(.dashboard-synthesis-node-strategic-objective, .dashboard-synthesis-node-operational-objective) > .dashboard-synthesis-node-summary .text-\\[\\#667085\\]`;

    expect(declarationBlock(css, mutedTextSelector)).toContain('color: #cbd5e1 !important;');
  });
});
