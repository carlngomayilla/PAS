<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardProfileInteractionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_sees_profile_interactions_on_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Interactions disponibles pour ce profil');
        $response->assertSee('Espace de travail (interactions utilisables)');
        $response->assertSee('Profil utilisateur');
    }

    public function test_seeded_service_user_can_open_dashboard_without_scope_error(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'robert.ekomi@anbg.ga')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Tableau de bord strategique et operationnel');
        $response->assertSee('Diagramme de Gantt compact');
    }
}
