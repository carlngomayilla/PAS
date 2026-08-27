<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\RolePermissionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

/**
 * Couvre A03 : la route signed `workspace.reporting.exports.download` ne doit
 * livrer un export que si l utilisateur authentifie est bien le proprietaire
 * du fichier (chemin sous `exports/reporting/{user_id}/...`).
 */
class ReportingExportDownloadSecurityTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_owner_can_download_his_own_export(): void
    {
        $owner = $this->createAdminUser([
            'email' => 'owner.export@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        Storage::fake('local');
        $path = 'exports/reporting/'.$owner->id.'/legitimate.xlsx';
        Storage::disk('local')->put($path, 'XLSX_CONTENT');

        $url = URL::temporarySignedRoute(
            'workspace.reporting.exports.download',
            now()->addMinutes(5),
            [
                'path' => Crypt::encryptString($path),
                'name' => 'reporting.xlsx',
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );

        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_user_cannot_download_another_users_export(): void
    {
        $owner = $this->createAdminUser([
            'email' => 'real.owner@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);
        $attacker = $this->createAdminUser([
            'email' => 'attacker@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        Storage::fake('local');
        $stolenPath = 'exports/reporting/'.$owner->id.'/private.xlsx';
        Storage::disk('local')->put($stolenPath, 'SECRET');

        $url = URL::temporarySignedRoute(
            'workspace.reporting.exports.download',
            now()->addMinutes(5),
            [
                'path' => Crypt::encryptString($stolenPath),
                'name' => 'leaked.xlsx',
            ]
        );

        $this->actingAs($attacker)
            ->get($url)
            ->assertForbidden();
    }

    public function test_path_traversal_attempt_is_rejected(): void
    {
        $user = $this->createAdminUser([
            'email' => 'traversal.user@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        $url = URL::temporarySignedRoute(
            'workspace.reporting.exports.download',
            now()->addMinutes(5),
            [
                'path' => Crypt::encryptString('exports/reporting/'.$user->id.'/../../../etc/passwd'),
                'name' => 'pwned',
            ]
        );

        $this->actingAs($user)
            ->get($url)
            ->assertForbidden();
    }

    public function test_invalid_encrypted_path_is_rejected(): void
    {
        $user = $this->createAdminUser([
            'email' => 'tamper.user@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        $url = URL::temporarySignedRoute(
            'workspace.reporting.exports.download',
            now()->addMinutes(5),
            [
                'path' => 'not-a-valid-encrypted-payload',
                'name' => 'whatever',
            ]
        );

        $this->actingAs($user)
            ->get($url)
            ->assertForbidden();
    }

    public function test_guest_cannot_download_even_with_valid_signed_url(): void
    {
        $owner = $this->createAdminUser([
            'email' => 'guest.test.owner@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        Storage::fake('local');
        $path = 'exports/reporting/'.$owner->id.'/file.xlsx';
        Storage::disk('local')->put($path, 'data');

        $url = URL::temporarySignedRoute(
            'workspace.reporting.exports.download',
            now()->addMinutes(5),
            [
                'path' => Crypt::encryptString($path),
                'name' => 'file.xlsx',
            ]
        );

        // Pas de actingAs : visiteur anonyme.
        $response = $this->get($url);
        $this->assertContains(
            $response->getStatusCode(),
            [302, 401, 403],
            'Une requete anonyme sur le download d export doit etre bloquee (401/403) ou redirigee vers login (302).'
        );
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $owner = $this->createAdminUser([
            'email' => 'unsigned.export@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        Storage::fake('local');
        $path = 'exports/reporting/'.$owner->id.'/unsigned.xlsx';
        Storage::disk('local')->put($path, 'PRIVATE');

        $this->actingAs($owner)
            ->get(route('workspace.reporting.exports.download', [
                'path' => Crypt::encryptString($path),
                'name' => 'reporting.xlsx',
            ]))
            ->assertForbidden();

        Storage::disk('local')->assertExists($path);
    }

    public function test_owner_cannot_download_after_reporting_permission_is_revoked(): void
    {
        $this->setRolePermissions(User::ROLE_ADMIN, ['planning.read', 'reporting.read']);

        $owner = $this->createAdminUser([
            'email' => 'revoked.reporting@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        Storage::fake('local');
        $path = 'exports/reporting/'.$owner->id.'/revoked-reporting.xlsx';
        Storage::disk('local')->put($path, 'PRIVATE');
        $url = $this->signedDownloadUrl($path);

        $this->setRolePermissions(User::ROLE_ADMIN, ['planning.read']);

        $this->actingAs($owner)
            ->get($url)
            ->assertForbidden();

        Storage::disk('local')->assertExists($path);
    }

    public function test_owner_cannot_download_after_planning_permission_is_revoked(): void
    {
        $this->setRolePermissions(User::ROLE_ADMIN, ['planning.read', 'reporting.read']);

        $owner = $this->createAdminUser([
            'email' => 'revoked.planning@anbg.test',
            'password' => Hash::make('Pass@12345'),
        ]);

        Storage::fake('local');
        $path = 'exports/reporting/'.$owner->id.'/revoked-planning.xlsx';
        Storage::disk('local')->put($path, 'PRIVATE');
        $url = $this->signedDownloadUrl($path);

        $this->setRolePermissions(User::ROLE_ADMIN, ['reporting.read']);

        $this->actingAs($owner)
            ->get($url)
            ->assertForbidden();

        Storage::disk('local')->assertExists($path);
    }

    private function signedDownloadUrl(string $path): string
    {
        return URL::temporarySignedRoute(
            'workspace.reporting.exports.download',
            now()->addMinutes(5),
            [
                'path' => Crypt::encryptString($path),
                'name' => 'reporting.xlsx',
            ]
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function setRolePermissions(string $role, array $permissions): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'role_permissions', 'key' => 'role_permissions_'.$role],
            ['value' => json_encode($permissions, JSON_UNESCAPED_SLASHES)]
        );

        app(RolePermissionSettings::class)->flush();
    }
}
