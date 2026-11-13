// @vitest-environment node
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../resources/js/admin-shell.js', import.meta.url), 'utf8');
const pages = [];

function shell() {
  const dom = new JSDOM(`<!doctype html><body>
    <button id="admin-sidebar-open"></button>
    <aside id="admin-sidebar" data-sidebar-keyboard-expand><a href="#one">One</a></aside>
    <div id="admin-overlay" class="hidden"></div>
    <button id="outside">Outside</button>
  </body>`, { url: 'https://pas.test', runScripts: 'outside-only' });
  pages.push(dom);
  const { window } = dom;
  let desktop = true;
  window.matchMedia = () => ({ matches: desktop, addEventListener() {}, removeEventListener() {} });
  window.requestAnimationFrame = callback => callback();
  window.eval(source);
  const sidebar = window.document.querySelector('aside');
  const key = name => window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key: name, bubbles: true }));
  return { window, sidebar, key, resize: value => { desktop = value; window.dispatchEvent(new window.Event('resize')); } };
}

afterEach(() => { for (const dom of pages.splice(0)) dom.window.close(); });

describe('sidebar interaction state', () => {
  it('expands on hover and collapses on leaving the rail', () => {
    const { window, sidebar } = shell();
    sidebar.dispatchEvent(new window.MouseEvent('mouseenter'));
    expect(window.document.body.classList.contains('sidebar-desktop-expanded')).toBe(true);
    sidebar.dispatchEvent(new window.MouseEvent('mouseleave'));
    expect(window.document.body.classList.contains('sidebar-desktop-expanded')).toBe(false);
  });

  it('clears keyboard expansion when Escape closes the desktop rail', () => {
    const { window, sidebar, key } = shell();
    key('Tab');
    sidebar.querySelector('a').focus();
    expect(sidebar.getAttribute('data-sidebar-keyboard-expanded')).toBe('true');
    key('Escape');
    expect(sidebar.hasAttribute('data-sidebar-keyboard-expanded')).toBe(false);
    expect(window.document.body.classList.contains('sidebar-desktop-expanded')).toBe(false);
  });

  it('does not restore a stale keyboard expansion after a mobile round trip', () => {
    const { window, sidebar, key, resize } = shell();
    key('Tab');
    sidebar.querySelector('a').focus();
    resize(false);
    expect(sidebar.hasAttribute('inert')).toBe(true);
    expect(sidebar.getAttribute('aria-hidden')).toBe('true');
    expect(sidebar.hasAttribute('data-sidebar-keyboard-expanded')).toBe(false);
    resize(true);
    expect(sidebar.hasAttribute('inert')).toBe(false);
    expect(window.document.body.classList.contains('sidebar-desktop-expanded')).toBe(false);
  });
});
