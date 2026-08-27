<?php

namespace Tests\Feature;

use App\Models\Exercice;
use App\Models\JournalAudit;
use App\Services\ExerciceContext;
use App\Services\PtaSuiviService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class ExerciceContextWorkflowTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_super_admin_manages_exercises_and_active_year_controls_default_context(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.exercises.index'))
            ->assertOk()
            ->assertSee('Exercices et periodes');

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.exercises.store'), [
                'annee' => 2031,
                'libelle' => 'Exercice pilote 2031',
                'date_debut' => '2031-02-01',
                'date_fin' => '2032-01-31',
                'statut' => Exercice::STATUT_OUVERT,
                'motif' => 'Creation exercice pilote',
            ])
            ->assertRedirect(route('workspace.super-admin.exercises.index'));

        $exercise = Exercice::query()->where('annee', 2031)->firstOrFail();

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.exercises.update', $exercise), [
                'annee' => 2031,
                'libelle' => 'Exercice actif 2031',
                'date_debut' => '2031-02-01',
                'date_fin' => '2032-02-15',
                'statut' => Exercice::STATUT_OUVERT,
                'motif' => 'Ajustement periode officielle',
            ])
            ->assertRedirect(route('workspace.super-admin.exercises.index'));

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.exercises.activate', $exercise), [
                'motif' => 'Definition exercice actif global',
            ])
            ->assertRedirect(route('workspace.super-admin.exercises.index'));

        $this->assertTrue((bool) $exercise->fresh()->is_active);
        $this->assertSame(1, Exercice::query()->where('is_active', true)->count());
        $this->assertSame(2031, app(ExerciceContext::class)->selectedYear());

        $updatedExercise = $exercise->fresh();
        $this->assertSame('Exercice actif 2031', $updatedExercise->libelle);
        $this->assertSame('2032-02-15', $updatedExercise->date_fin?->format('Y-m-d'));
        $this->assertTrue((bool) $updatedExercise->is_active);

        $this->assertGreaterThanOrEqual(
            3,
            JournalAudit::query()
                ->where('module', 'super_admin')
                ->whereIn('action', ['exercise_create', 'exercise_update', 'exercise_activate'])
                ->count()
        );
    }

    public function test_archived_exercise_cannot_be_activated(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $exercise = Exercice::query()->create([
            'annee' => 2032,
            'libelle' => 'Exercice archive',
            'date_debut' => '2032-01-01',
            'date_fin' => '2032-12-31',
            'statut' => Exercice::STATUT_ARCHIVE,
            'is_active' => false,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.exercises.activate', $exercise), [
                'motif' => 'Tentative activation archive',
            ])
            ->assertSessionHasErrors('statut');

        $this->assertFalse((bool) $exercise->fresh()->is_active);
    }

    public function test_active_exercise_year_is_resolved_once_per_context_instance(): void
    {
        Exercice::query()->create([
            'annee' => 2034,
            'libelle' => 'Exercice actif 2034',
            'date_debut' => '2034-01-01',
            'date_fin' => '2034-12-31',
            'statut' => Exercice::STATUT_OUVERT,
            'is_active' => true,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $context = app(ExerciceContext::class);
        $this->assertSame(2034, $context->selectedYear());
        $this->assertSame(2034, $context->selectedYear());
        $this->assertSame(2034, $context->selectedYear());

        $activeExerciseQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'exercices')
                && str_contains(strtolower($query['query']), 'is_active'))
            ->values();

        DB::disableQueryLog();

        $this->assertCount(1, $activeExerciseQueries);
    }

    public function test_array_query_values_are_ignored_without_array_to_string_warnings(): void
    {
        Exercice::query()->create([
            'annee' => 2035,
            'libelle' => 'Exercice actif 2035',
            'date_debut' => '2035-01-01',
            'date_fin' => '2035-12-31',
            'statut' => Exercice::STATUT_OUVERT,
            'is_active' => true,
        ]);
        $request = Request::create('/dashboard', 'GET', [
            'exercice' => ['2031'],
            'trimestre' => ['1'],
        ]);
        $this->app->instance('request', $request);

        $context = app(ExerciceContext::class);

        $this->assertSame(2035, $context->selectedYear());
        $this->assertNull($context->selectedQuarter());
        $this->assertSame('all', app(PtaSuiviService::class)->normalizePeriod(['q1']));
    }
}
