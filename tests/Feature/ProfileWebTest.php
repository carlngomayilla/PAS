<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ProfileWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_security_is_collapsed_but_opens_for_expiry_or_validation_errors(): void
    {
        $user = User::factory()->create(['password_changed_at' => now()]);
        $response = $this->actingAs($user)->get(route('workspace.profile.edit'))->assertOk();
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*data-profile-security-options[^>]*\\sopen(?:\\s|>)/u', $response->getContent());

        $user->update(['password_changed_at' => null]);
        $response = $this->get(route('workspace.profile.edit'))->assertOk();
        $this->assertMatchesRegularExpression('/<details[^>]*data-profile-security-options[^>]*\\sopen(?:\\s|>)/u', $response->getContent());

        $user->update(['password_changed_at' => now()]);
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['password' => ['Mot de passe refusé.']]));
        $response = $this->withSession(['errors' => $errors])->get(route('workspace.profile.edit'))->assertOk();
        $this->assertMatchesRegularExpression('/<details[^>]*data-profile-security-options[^>]*\\sopen(?:\\s|>)/u', $response->getContent());
    }

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
            ->assertSee('data-profile-page-header', false)
            ->assertDontSee('<section class="showcase-panel mb-4 app-screen-block">', false)
            ->assertSee('Règles de mot de passe')
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

    public function test_temporary_password_profile_update_requires_new_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password-Old@123'),
            'password_changed_at' => null,
        ]);

        $this->actingAs($user)
            ->from(route('workspace.profile.edit'))
            ->put(route('workspace.profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertNull($user->password_changed_at);
        $this->assertTrue(Hash::check('Password-Old@123', $user->password));
    }

    public function test_temporary_password_renewal_requires_at_least_eight_characters(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password-Old@123'),
            'password_changed_at' => null,
        ]);

        $this->actingAs($user)
            ->from(route('workspace.profile.edit'))
            ->put(route('workspace.profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'current_password' => 'Password-Old@123',
                'password' => 'abc1234',
                'password_confirmation' => 'abc1234',
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertNull($user->password_changed_at);
        $this->assertTrue(Hash::check('Password-Old@123', $user->password));
    }

    public function test_temporary_password_user_can_renew_password_from_profile(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password-Old@123'),
            'password_changed_at' => null,
        ]);

        $this->actingAs($user)
            ->put(route('workspace.profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'current_password' => 'Password-Old@123',
                'password' => 'Password-New@123',
                'password_confirmation' => 'Password-New@123',
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('Password-New@123', $user->password));
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
