import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

const projectRoot = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
    esbuild: {
        jsx: 'automatic',
        jsxImportSource: 'react',
    },
    root: projectRoot,
    resolve: {
        alias: {
            'next/navigation': fileURLToPath(
                new URL('./tests/mocks/next-navigation.ts', import.meta.url),
            ),
            '@': projectRoot,
        },
    },
    test: {
        environment: 'jsdom',
        fileParallelism: false,
        include: ['tests/**/*.test.{ts,tsx}'],
        maxWorkers: 1,
        pool: 'vmThreads',
        setupFiles: ['./tests/setup.ts'],
        restoreMocks: true,
        clearMocks: true,
        testTimeout: 15_000,
    },
});
