import { vi } from 'vitest';

export const navigationReplaceMock = vi.fn();

let currentSearchParameters = new URLSearchParams();

export function setNavigationSearchParameters(value = ''): void {
    currentSearchParameters = new URLSearchParams(value);
}

export function resetNavigationMock(): void {
    navigationReplaceMock.mockReset();
    setNavigationSearchParameters();
}

export function useRouter() {
    return {
        replace: navigationReplaceMock,
    };
}

export function useSearchParams(): URLSearchParams {
    return currentSearchParameters;
}
