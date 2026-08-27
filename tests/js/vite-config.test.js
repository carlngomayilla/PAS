// @vitest-environment node

import { describe, expect, it } from 'vitest';
import viteConfig from '../../vite.config.js';

describe('Vite development server configuration', () => {
  it('publishes assets and HMR on the IPv4 loopback address', () => {
    expect(viteConfig.server.host).toBe('127.0.0.1');
    expect(viteConfig.server.port).toBe(5173);
    expect(viteConfig.server.strictPort).toBe(true);
    expect(viteConfig.server.hmr.host).toBe('127.0.0.1');
  });
});
