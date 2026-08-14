import path from 'node:path';

export const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8765';
export const database = process.env.E2E_DB_DATABASE ?? 'pas_anbg_e2e';
export const storageRoot = path.resolve(process.env.E2E_STORAGE_ROOT ?? 'storage/app/e2e-private');
export const appEnvironment: Record<string, string> = {
    ...Object.fromEntries(Object.entries(process.env).filter((entry): entry is [string, string] => entry[1] !== undefined)),
    APP_ENV: 'e2e',
    APP_DEBUG: 'false',
    APP_URL: baseURL,
    DB_CONNECTION: 'pgsql',
    DB_DATABASE: database,
    E2E_DB_DATABASE: database,
    E2E_STORAGE_ROOT: storageRoot,
    LOCAL_FILESYSTEM_ROOT: storageRoot,
    FILESYSTEM_DISK: 'local',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'database',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'stderr',
    DB_LOGIN_PREFLIGHT: 'false',
    ANTIVIRUS_SCAN_ENABLED: 'false',
};
