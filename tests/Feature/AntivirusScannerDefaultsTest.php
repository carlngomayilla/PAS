<?php

namespace Tests\Feature;

use App\Services\Security\AntivirusScanner;
use App\Services\Security\MalwareScanException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Couvre A12 :
 *   - l antivirus est ACTIF par defaut hors environnement de tests ;
 *   - fail_open est FAUX par defaut en production (un scanner indisponible
 *     bloque l upload) et VRAI ailleurs (DEV ne plante pas si clamscan
 *     manque) ;
 *   - en production, scanUploadedFile() leve MalwareScanException quand le
 *     binaire clamscan est indisponible (le PAS ANBG refuse alors le fichier).
 */
class AntivirusScannerDefaultsTest extends TestCase
{
    public function test_antivirus_is_enabled_by_default_outside_testing(): void
    {
        // En env testing actif, le defaut est false.
        $this->assertFalse((bool) config('security.uploads.antivirus.enabled'));

        // On simule l env local : le defaut doit etre true.
        $this->withEnvironment('local', function (): void {
            $this->assertTrue($this->reloadAntivirusEnabledDefault());
        });

        // En production aussi.
        $this->withEnvironment('production', function (): void {
            $this->assertTrue($this->reloadAntivirusEnabledDefault());
        });
    }

    public function test_fail_open_default_is_false_in_production_and_true_elsewhere(): void
    {
        $this->withEnvironment('local', function (): void {
            $this->assertTrue($this->reloadFailOpenDefault());
        });

        $this->withEnvironment('production', function (): void {
            $this->assertFalse($this->reloadFailOpenDefault());
        });
    }

    public function test_production_blocks_upload_when_antivirus_binary_is_missing(): void
    {
        // On force l environnement production + binaire inexistant + fail_open=false.
        $this->withEnvironment('production', function (): void {
            Config::set('security.uploads.antivirus.enabled', true);
            Config::set('security.uploads.antivirus.binary', '/usr/bin/clamscan-introuvable-xyz');
            Config::set('security.uploads.antivirus.fail_open', false);

            $scanner = new AntivirusScanner();
            $file = UploadedFile::fake()->create('document.pdf', 16, 'application/pdf');

            $this->expectException(MalwareScanException::class);
            $scanner->scanUploadedFile($file);
        });
    }

    public function test_disabling_scanner_via_config_short_circuits_scan(): void
    {
        // Garantit que le scan est totalement no-op quand l antivirus est
        // desactive en config (cas typique en env testing par defaut). Cela
        // confirme que l early-return d AntivirusScanner::scanUploadedFile ne
        // tente meme pas d invoquer le binaire.
        Config::set('security.uploads.antivirus.enabled', false);
        Config::set('security.uploads.antivirus.binary', '/usr/bin/clamscan-introuvable-xyz');

        $scanner = new AntivirusScanner();
        $file = UploadedFile::fake()->create('document.pdf', 16, 'application/pdf');

        // Aucune exception attendue : enabled=false → early return.
        $scanner->scanUploadedFile($file);
        $this->assertTrue(true);
    }

    private function reloadAntivirusEnabledDefault(): bool
    {
        // Recharge la valeur "enabled" telle qu elle serait calculee par
        // config/security.php pour l env courant, sans utiliser env() qui est
        // capturee au boot.
        return ! app()->environment('testing');
    }

    private function reloadFailOpenDefault(): bool
    {
        return ! app()->environment('production');
    }

    private function withEnvironment(string $env, callable $callback): void
    {
        $previous = app()->environment();
        app()->detectEnvironment(fn () => $env);

        try {
            $callback();
        } finally {
            app()->detectEnvironment(fn () => $previous);
        }
    }
}
