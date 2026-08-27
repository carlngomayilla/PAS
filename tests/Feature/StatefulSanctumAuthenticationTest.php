<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class StatefulSanctumAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanctum_stateful_stack_and_csrf_middleware_are_enabled(): void
    {
        $apiMiddleware = app('router')->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(EnsureFrontendRequestsAreStateful::class, $apiMiddleware);
        $this->assertSame(
            PreventRequestForgery::class,
            config('sanctum.middleware.validate_csrf_token')
        );
        $this->assertContains('localhost:3000', config('sanctum.stateful'));
        $this->assertContains('127.0.0.1:3000', config('sanctum.stateful'));
    }

    public function test_api_authentication_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_api_token_mismatch_is_json_without_an_accept_header(): void
    {
        $request = Request::create('/api/v1/logout', 'POST');

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame([
            'message' => 'Votre session a expire. Rechargez la page puis reessayez.',
            'csrf_expired' => true,
        ], $response->getData(true));
    }

    public function test_inactive_stateful_account_loses_its_session_without_deleting_personal_tokens(): void
    {
        $user = User::factory()->create();
        $personalToken = $user->createToken('mobile-client');

        $this->actingAs($user, 'web');
        $user->forceFill(['is_active' => false])->save();

        $response = $this->withSession(['stateful_marker' => 'present'])
            ->withHeader('Origin', 'http://localhost:3000')
            ->get('/api/v1/me');

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte desactive.')
            ->assertSessionMissing('stateful_marker');

        $this->assertTrue(Auth::guard('web')->guest());
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $personalToken->accessToken->getKey(),
        ]);
    }

    public function test_suspended_stateful_account_loses_its_session_without_deleting_personal_tokens(): void
    {
        $user = User::factory()->create();
        $personalToken = $user->createToken('mobile-client');

        $this->actingAs($user, 'web');
        $user->forceFill([
            'suspended_until' => now()->addDay(),
            'suspension_reason' => 'Verification de securite',
        ])->save();

        $response = $this->withSession(['stateful_marker' => 'present'])
            ->withHeader('Origin', 'http://127.0.0.1:3000')
            ->get('/api/v1/me');

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Compte temporairement suspendu.')
            ->assertSessionMissing('stateful_marker');

        $this->assertTrue(Auth::guard('web')->guest());
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $personalToken->accessToken->getKey(),
        ]);
    }

    public function test_bearer_logout_deletes_only_the_current_personal_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-client');
        $otherToken = $user->createToken('other-client');

        $this->withHeader('Authorization', 'Bearer '.$currentToken->plainTextToken)
            ->post('/api/v1/logout')
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->getKey(),
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->getKey(),
        ]);
    }

    public function test_stateful_logout_invalidates_session_and_preserves_personal_tokens(): void
    {
        $user = User::factory()->create();
        $personalToken = $user->createToken('mobile-client');

        $this->actingAs($user, 'web');

        $response = $this->withSession(['stateful_marker' => 'present'])
            ->withHeader('Origin', 'http://localhost:3000')
            ->post('/api/v1/logout');

        $response
            ->assertOk()
            ->assertJsonStructure(['message'])
            ->assertSessionMissing('stateful_marker');

        $this->assertTrue(Auth::guard('web')->guest());
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $personalToken->accessToken->getKey(),
        ]);
    }

    public function test_expired_password_is_json_without_accept_for_bearer_and_keeps_token(): void
    {
        $user = User::factory()->create([
            'password_changed_at' => now()->subDays(91),
        ]);
        $personalToken = $user->createToken('expired-password-client');

        $this->withHeader('Authorization', 'Bearer '.$personalToken->plainTextToken)
            ->get('/api/v1/me')
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('code', 'password_expired');

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $personalToken->accessToken->getKey(),
        ]);
    }

    public function test_expired_password_stateful_session_can_still_logout(): void
    {
        $user = User::factory()->create([
            'password_changed_at' => now()->subDays(91),
        ]);
        $personalToken = $user->createToken('mobile-client');

        $this->actingAs($user, 'web');

        $this->withHeader('Origin', 'http://localhost:3000')
            ->get('/api/v1/me')
            ->assertForbidden()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('code', 'password_expired');

        $this->assertTrue(Auth::guard('web')->check());

        $this->withSession(['stateful_marker' => 'present'])
            ->withHeader('Origin', 'http://localhost:3000')
            ->post('/api/v1/logout')
            ->assertOk()
            ->assertSessionMissing('stateful_marker');

        $this->assertTrue(Auth::guard('web')->guest());
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $personalToken->accessToken->getKey(),
        ]);
    }

    public function test_same_origin_proxy_headers_preserve_stateful_dashboard_session(): void
    {
        config()->set('sanctum.stateful', ['pas.anbg.ga']);

        $user = User::factory()->create([
            'role' => User::ROLE_DG,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($user, 'web')
            ->withHeaders([
                'Origin' => 'https://pas.anbg.ga',
                'Referer' => 'https://pas.anbg.ga/dashboard-pilot',
                'Host' => '127.0.0.1',
                'X-Forwarded-Host' => 'pas.anbg.ga',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/api/v1/dashboard/overview');

        $response
            ->assertOk()
            ->assertJsonPath('scope.user_role', User::ROLE_DG)
            ->assertJsonMissingPath('filter_options.responsibles.0.email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
