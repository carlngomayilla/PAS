<?php

namespace App\Console\Commands;

use App\Models\Action;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purge les utilisateurs inactifs et seede des actions de demonstration.
 *
 *  1. Soft-delete des comptes `is_active = false` (User utilise SoftDeletes).
 *     Avant la suppression, leurs actions sont reassignees au premier
 *     utilisateur actif du meme service. A defaut, au premier actif de la meme
 *     direction. A defaut, responsable_id = null (sera flague par le centre
 *     d'alertes pour reaffectation manuelle).
 *
 *  2. Creation de 2 actions de demonstration par utilisateur actif, rattachees
 *     au PTA actif du service (statut `valide` ou `verrouille`). Si le service
 *     n'a pas de PTA exploitable, on signale et on saute.
 *
 *  Usage :
 *      php artisan anbg:cleanup-and-seed --dry-run
 *      php artisan anbg:cleanup-and-seed
 */
class CleanupInactiveUsersCommand extends Command
{
    protected $signature = 'anbg:cleanup-and-seed
                            {--dry-run : Simule sans ecrire en base}';

    protected $description = 'Soft-delete les comptes inactifs en reassignant leurs actions, puis cree 2 actions de demonstration par utilisateur actif.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? '[DRY-RUN] Simulation, aucune ecriture.' : 'Execution reelle.');
        $this->newLine();

        $stats = [
            'inactifs_traites' => 0,
            'inactifs_soft_deletes' => 0,
            'actions_reassignees' => 0,
            'actions_sans_repreneur' => 0,
            'rmos_reassignes' => 0,
            'actions_creees' => 0,
            'services_sans_pta' => 0,
        ];

        DB::beginTransaction();

        try {
            // ── 1. Purge des utilisateurs inactifs ──
            $inactives = User::query()
                ->where('is_active', false)
                ->whereNull('deleted_at')
                ->get();

            $this->info(sprintf('%d utilisateur(s) inactif(s) trouve(s).', $inactives->count()));

            foreach ($inactives as $inactive) {
                $stats['inactifs_traites']++;

                $repreneur = $this->resolveRepreneur($inactive);
                $repreneurLabel = $repreneur?->name ?? '(aucun repreneur)';

                $this->line(sprintf(
                    '  - %s (%s) -> repreneur : %s',
                    $inactive->name,
                    $inactive->email,
                    $repreneurLabel
                ));

                $actionsAsResponsible = Action::where('responsable_id', $inactive->id)->get();
                foreach ($actionsAsResponsible as $action) {
                    if (! $dryRun) {
                        $action->responsable_id = $repreneur?->id;
                        $action->save();
                    }
                    $stats['actions_reassignees']++;
                    if ($repreneur === null) {
                        $stats['actions_sans_repreneur']++;
                    }
                }

                // Reassigner les RMO (pivot action_responsables) — on bascule l'id.
                if (DB::getSchemaBuilder()->hasTable('action_responsables')) {
                    $rmoCount = DB::table('action_responsables')
                        ->where('user_id', $inactive->id)
                        ->count();
                    if ($rmoCount > 0) {
                        if (! $dryRun) {
                            if ($repreneur !== null) {
                                // On reattribue les lignes de l'inactif au repreneur,
                                // en evitant les conflits sur l'unique (action_id, user_id).
                                $existingForRepreneur = DB::table('action_responsables')
                                    ->where('user_id', $repreneur->id)
                                    ->pluck('action_id')
                                    ->all();

                                DB::table('action_responsables')
                                    ->where('user_id', $inactive->id)
                                    ->whereIn('action_id', $existingForRepreneur)
                                    ->delete();

                                DB::table('action_responsables')
                                    ->where('user_id', $inactive->id)
                                    ->update(['user_id' => $repreneur->id]);
                            } else {
                                // Pas de repreneur : on detache simplement.
                                DB::table('action_responsables')
                                    ->where('user_id', $inactive->id)
                                    ->delete();
                            }
                        }
                        $stats['rmos_reassignes'] += $rmoCount;
                    }
                }

                // Diluer les autres FK indirectes (auditeurs, soumissionnaires…).
                if (! $dryRun) {
                    Action::where('soumise_par', $inactive->id)
                        ->update(['soumise_par' => $repreneur?->id]);
                    Action::where('evalue_par', $inactive->id)
                        ->update(['evalue_par' => $repreneur?->id]);
                }

                if (! $dryRun) {
                    $inactive->delete(); // soft-delete (SoftDeletes trait)
                }
                $stats['inactifs_soft_deletes']++;
            }

            $this->newLine();

            // ── 2. Creation de 2 actions de demo par utilisateur actif ──
            $services = Service::query()
                ->with(['direction:id,code,libelle'])
                ->orderBy('code')
                ->get();

            $this->info(sprintf('%d service(s) a traiter.', $services->count()));

            $defaultStart = Carbon::today();
            $defaultEnd = Carbon::today()->addWeeks(4);

            foreach ($services as $service) {
                $pta = $this->resolveServicePta($service);
                if ($pta === null) {
                    $this->warn(sprintf(
                        '  ! Service %s : aucun PTA valide/verrouille, services saute.',
                        $service->code
                    ));
                    $stats['services_sans_pta']++;
                    continue;
                }

                $activeUsers = User::query()
                    ->where('service_id', $service->id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get();

                if ($activeUsers->isEmpty()) {
                    $this->warn(sprintf('  ! Service %s : aucun utilisateur actif.', $service->code));
                    continue;
                }

                foreach ($activeUsers as $user) {
                    for ($i = 1; $i <= 2; $i++) {
                        $payload = [
                            'exercice_id' => $pta->exercice_id,
                            'pta_id' => $pta->id,
                            'pao_id' => $pta->pao_id,
                            'objectif_operationnel_id' => $pta->objectif_operationnel_id,
                            'libelle' => sprintf('Action demo %d - %s', $i, $user->name),
                            'description' => sprintf(
                                'Action de demonstration generee automatiquement pour %s (service %s).',
                                $user->name,
                                $service->code
                            ),
                            'type_cible' => 'qualitative',
                            'priorite' => 'moyenne',
                            'date_debut' => $defaultStart->toDateString(),
                            'date_fin' => $defaultEnd->toDateString(),
                            'date_echeance' => $defaultEnd->toDateString(),
                            'responsable_id' => $user->id,
                            'frequence_execution' => 'hebdomadaire',
                            'resultat_attendu' => 'Resultat de demonstration : libre.',
                            'contexte_action' => 'operationnel',
                            'origine_action' => 'INTERNE',
                            'financement_requis' => false,
                            'statut' => 'non_demarre',
                        ];

                        if (! $dryRun) {
                            Action::create($payload);
                        }
                        $stats['actions_creees']++;
                    }
                }

                $this->line(sprintf(
                    '  + Service %s : %d action(s) creee(s) (%d utilisateurs)',
                    $service->code,
                    $activeUsers->count() * 2,
                    $activeUsers->count()
                ));
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('[DRY-RUN] Rollback effectue, aucune ecriture en base.');
            } else {
                DB::commit();
                $this->info('Commit effectue.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Echec : '.$e->getMessage());
            $this->error('Trace : '.$e->getTraceAsString());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== Rapport ===');
        foreach ($stats as $key => $value) {
            $this->line(sprintf('  %s : %d', $key, $value));
        }

        return self::SUCCESS;
    }

    private function resolveRepreneur(User $inactive): ?User
    {
        $sameService = User::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $inactive->id)
            ->when($inactive->service_id !== null, fn ($q) => $q->where('service_id', $inactive->service_id))
            ->orderBy('id')
            ->first();

        if ($sameService !== null) {
            return $sameService;
        }

        if ($inactive->direction_id !== null) {
            return User::query()
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('id', '!=', $inactive->id)
                ->where('direction_id', $inactive->direction_id)
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    private function resolveServicePta(Service $service): ?Pta
    {
        return Pta::query()
            ->where('service_id', $service->id)
            ->whereIn('statut', ['valide', 'verrouille'])
            ->orderByDesc('id')
            ->first();
    }
}
