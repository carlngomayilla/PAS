import { spawnSync } from 'node:child_process';
import { appEnvironment } from './environment';

function artisan(...arguments_: string[]): void {
    const result = spawnSync('php', ['artisan', ...arguments_], {
        cwd: process.cwd(),
        env: appEnvironment,
        encoding: 'utf8',
    });
    if (result.status !== 0) {
        throw new Error([`php artisan ${arguments_.join(' ')} a échoué.`, result.stdout, result.stderr].filter(Boolean).join('\n'));
    }
}

export default function globalSetup(): void {
    artisan('config:clear', '--no-interaction');
    artisan('e2e:prepare', '--no-interaction');
}
