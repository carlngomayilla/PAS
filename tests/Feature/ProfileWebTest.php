<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileWebTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0n8AAAAASUVORK5CYII=';

    public function test_authenticated_user_can_open_profile_page(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.profile.edit'))
            ->assertOk()
            ->assertSee('Mon profil')
            ->assertSee($user->email);
    }

    public function test_user_can_update_his_profile_with_photo_and_password(): void
    {
        Storage::fake('public');

        $oldPath = 'profils/old-photo.png';
        Storage::disk('public')->put($oldPath, 'old-photo-content');

        $user = User::factory()->create([
            'name' => 'Nom Initial',
            'email' => 'profil.initial@anbg.test',
            'role' => User::ROLE_SERVICE,
            'password' => Hash::make('Password-Old@123'),
            'profile_photo_path' => $oldPath,
        ]);

        $this->actingAs($user)
            ->put(route('workspace.profile.update'), [
                'name' => 'Nom Modifie',
                'email' => 'profil.modifie@anbg.test',
                'profile_photo' => UploadedFile::fake()->createWithContent(
                    'avatar-new.png',
                    (string) base64_decode(self::TINY_PNG, true)
                ),
                'current_password' => 'Password-Old@123',
                'password' => 'Password-New@123',
                'password_confirmation' => 'Password-New@123',
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('Nom Modifie', $user->name);
        $this->assertSame('profil.modifie@anbg.test', $user->email);
        $this->assertNotNull($user->profile_photo_path);
        $this->assertNotSame($oldPath, $user->profile_photo_path);
        $this->assertTrue(Hash::check('Password-New@123', $user->password));

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists((string) $user->profile_photo_path);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password-Old@123'),
        ]);

        $this->actingAs($user)
            ->from(route('workspace.profile.edit'))
            ->put(route('workspace.profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'Password-New@123',
                'password_confirmation' => 'Password-New@123',
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('Password-Old@123', $user->password));
    }

    public function test_profile_photo_url_uses_public_storage_path(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'profile_photo_path' => 'profils/avatar demo.png',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.profile.edit', [], false))
            ->assertOk()
            ->assertSee('/storage/profils/avatar%20demo.png', false);
    }
}
