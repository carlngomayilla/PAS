<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre A14 : tous les FormRequests de planification refusent un agent simple,
 * meme si le controleur final oubliait un `denyUnless*`. La 5e barriere
 * (FormRequest::authorize) bloque a l entree.
 */
class FormRequestAgentBlockedTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_post_store_pas(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->from(route('workspace.pas.index'))
            ->post(route('workspace.pas.store'), [
                'titre' => 'Pas agent',
                'periode_debut' => 2026,
                'periode_fin' => 2028,
                'axes' => [
                    [
                        'code' => 'AXE',
                        'libelle' => 'Test axe',
                        'objectifs' => [
                            // date_echeance obligatoire selon la nouvelle logique métier
                            ['code' => 'OS', 'libelle' => 'OS test', 'date_echeance' => '2027-12-31'],
                        ],
                    ],
                ],
            ])
            ->assertForbidden();
    }

    public function test_agent_cannot_post_store_pao(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->from(route('workspace.pao.index'))
            ->post(route('workspace.pao.store'), [
                'pas_axe_id' => 1,
                'pas_objectif_id' => 1,
                'direction_id' => 1,
                'annee' => 2026,
                'objectifs_operationnels' => [
                    ['libelle' => 'OS', 'service_id' => 1, 'echeance' => '2026-06-30'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_agent_cannot_post_store_pta(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->from(route('workspace.pta.index'))
            ->post(route('workspace.pta.store'), [
                'objectif_operationnel_id' => 1,
                'service_id' => 1,
                'actions' => [
                    [
                        'libelle' => 'Action',
                        'date_debut' => '2026-01-01',
                        'mode_evaluation' => 'sous_actions',
                        'rmo_ids' => [$agent->id],
                    ],
                ],
            ])
            ->assertForbidden();
    }
}
