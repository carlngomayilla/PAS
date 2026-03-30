<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferentielUtilisateurProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0n8AAAAASUVORK5CYII=';

    public function test_admin_can_create_user_with_profile_photo(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'direction_id' => null,
            'service_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('workspace.referentiel.utilisateurs.store'), [
            'name' => 'Profil Test',
            'email' => 'profil.test@anbg.test',
            'role' => User::ROLE_CABINET,
            'password' => 'Password-Test@123',
            'password_confirmation' => 'Password-Test@123',
            'profile_photo' => UploadedFile::fake()->createWithContent(
                'avatar.png',
                (string) base64_decode(self::TINY_PNG, true)
            ),
        ]);

        $response->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $created = User::query()->where('email', 'profil.test@anbg.test')->firstOrFail();
        $this->assertNotNull($created->profile_photo_path);
        Storage::disk('public')->assertExists((string) $created->profile_photo_path);
    }

    public function test_admin_can_replace_then_remove_profile_photo(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'direction_id' => null,
            'service_id' => null,
        ]);

        $oldPath = 'profils/old-avatar.jpg';
        Storage::disk('public')->put($oldPath, 'old-photo');

        $target = User::factory()->create([
            'name' => 'User Photo',
            'email' => 'user.photo@anbg.test',
            'role' => User::ROLE_CABINET,
            'direction_id' => null,
            'service_id' => null,
            'profile_photo_path' => $oldPath,
        ]);

        $this->actingAs($admin)->put(route('workspace.referentiel.utilisateurs.update', $target), [
            'name' => 'User Photo',
            'email' => 'user.photo@anbg.test',
            'role' => User::ROLE_CABINET,
            'profile_photo' => UploadedFile::fake()->createWithContent(
                'avatar-new.png',
                (string) base64_decode(self::TINY_PNG, true)
            ),
        ])->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $target->refresh();
        $this->assertNotNull($target->profile_photo_path);
        $this->assertNotSame($oldPath, $target->profile_photo_path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists((string) $target->profile_photo_path);

        $newPath = (string) $target->profile_photo_path;

        $this->actingAs($admin)->put(route('workspace.referentiel.utilisateurs.update', $target), [
            'name' => 'User Photo',
            'email' => 'user.photo@anbg.test',
            'role' => User::ROLE_CABINET,
            'remove_profile_photo' => '1',
        ])->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $target->refresh();
        $this->assertNull($target->profile_photo_path);
        Storage::disk('public')->assertMissing($newPath);
    }
}
