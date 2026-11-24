<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_and_view_own_private_signature(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'password' => Hash::make('Password-Current@123'),
        ]);

        $this->actingAs($user)
            ->put(route('workspace.profile.signature.update'), [
                'signature' => UploadedFile::fake()->image('signature.png', 400, 160),
                'current_password' => 'Password-Current@123',
                'signature_ownership_confirmed' => '1',
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertNotNull($user->signature_path);
        $this->assertMatchesRegularExpression('#^signatures/'.$user->id.'/[0-9a-f-]{36}\.png$#', (string) $user->signature_path);
        $this->assertSame(64, strlen((string) $user->signature_sha256));
        $this->assertNotNull($user->signature_updated_at);
        Storage::disk('local')->assertExists((string) $user->signature_path);

        $this->get(route('workspace.profile.signature.show'))
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');
    }

    public function test_registering_signature_requires_valid_password_consent_and_png(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'password' => Hash::make('Password-Current@123'),
        ]);

        $this->actingAs($user)
            ->from(route('workspace.profile.edit'))
            ->put(route('workspace.profile.signature.update'), [
                'signature' => UploadedFile::fake()->image('signature.jpg', 400, 160),
                'current_password' => 'incorrect',
            ])
            ->assertRedirect(route('workspace.profile.edit'))
            ->assertSessionHasErrors(['signature', 'current_password', 'signature_ownership_confirmed']);

        $user->refresh();
        $this->assertNull($user->signature_path);
        $this->assertSame([], Storage::disk('local')->allFiles('signatures'));
    }

    public function test_replacing_signature_preserves_previous_version_for_signed_document_history(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'password' => Hash::make('Password-Current@123'),
        ]);

        $payload = [
            'current_password' => 'Password-Current@123',
            'signature_ownership_confirmed' => '1',
        ];
        $this->actingAs($user)->put(route('workspace.profile.signature.update'), $payload + [
            'signature' => UploadedFile::fake()->image('signature.png', 400, 160),
        ])->assertRedirect(route('workspace.profile.edit'));
        $firstPath = (string) $user->fresh()->signature_path;

        $this->put(route('workspace.profile.signature.update'), $payload + [
            'signature' => UploadedFile::fake()->image('signature.png', 500, 180),
        ])->assertRedirect(route('workspace.profile.edit'));
        $secondPath = (string) $user->fresh()->signature_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_guest_cannot_register_or_view_a_signature(): void
    {
        Storage::fake('local');

        $this->put(route('workspace.profile.signature.update'))->assertRedirect(route('login'));
        $this->get(route('workspace.profile.signature.show'))->assertRedirect(route('login'));
    }
}
