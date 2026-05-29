<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAssignmentHistory;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UserLifecycleService
{
    /**
     * @return array<string, mixed>
     */
    public function deactivate(User $target, User $actor, ?int $replacementId = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($target, $actor, $replacementId, $reason): array {
            $target->refresh();

            $summary = $this->openAssignmentSummary($target);
            $replacement = $this->resolveReplacement($target, $replacementId);

            if ((int) $summary['total'] > 0 && ! $replacement instanceof User) {
                throw ValidationException::withMessages([
                    'transfer_to_user_id' => 'Impossible de desactiver : des taches ouvertes existent et aucun repreneur actif du meme perimetre n a ete trouve.',
                ]);
            }

            $transfer = $replacement instanceof User
                ? $this->transferOpenAssignments($target, $replacement)
                : $this->emptyTransferStats();

            $payload = ['is_active' => false];
            if (Schema::hasColumn('users', 'deactivated_at')) {
                $payload['deactivated_at'] = now();
            }
            if (Schema::hasColumn('users', 'deactivated_by')) {
                $payload['deactivated_by'] = (int) $actor->id;
            }
            if (Schema::hasColumn('users', 'deactivation_reason')) {
                $payload['deactivation_reason'] = $this->normalizeReason($reason, 'Desactivation du compte par Super Admin.');
            }
            if (Schema::hasColumn('users', 'tasks_transferred_to')) {
                $payload['tasks_transferred_to'] = $replacement?->id;
            }

            $target->forceFill($payload)->save();

            return [
                'status' => 'deactivated',
                'reason' => $payload['deactivation_reason'] ?? $this->normalizeReason($reason, 'Desactivation du compte par Super Admin.'),
                'open_assignments_before' => $summary,
                'replacement_user_id' => $replacement?->id,
                'replacement_user_name' => $replacement?->name,
                'transfers' => $transfer,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(User $target, User $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($target, $actor, $reason): array {
            $payload = ['is_active' => true];
            foreach (['deactivated_at', 'deactivated_by', 'deactivation_reason', 'tasks_transferred_to'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $payload[$column] = null;
                }
            }

            $target->forceFill($payload)->save();

            return [
                'status' => 'activated',
                'reason' => $this->normalizeReason($reason, 'Reactivation du compte par Super Admin.'),
                'actor_user_id' => (int) $actor->id,
            ];
        });
    }

    /**
     * Trace un changement de role/rattachement et transfere les taches ouvertes
     * avant que le nouveau perimetre soit applique au compte.
     *
     * @param  array<string, mixed>  $nextAttributes
     * @return array<string, mixed>|null
     */
    public function recordAssignmentChange(User $target, User $actor, array $nextAttributes, ?int $replacementId = null, ?string $reason = null): ?array
    {
        return DB::transaction(function () use ($target, $actor, $nextAttributes, $replacementId, $reason): ?array {
            $target->refresh();

            $previous = $this->assignmentSnapshot($target);
            $next = $this->assignmentSnapshotFromAttributes($target, $nextAttributes);

            if ($previous === $next) {
                return null;
            }

            $normalizedReason = trim((string) $reason);
            if ($normalizedReason === '') {
                throw ValidationException::withMessages([
                    'motif' => 'Le motif est obligatoire pour un changement de role, de service ou de direction.',
                ]);
            }

            $summary = $this->openAssignmentSummary($target);
            $replacement = null;
            if ((int) $summary['total'] > 0 || ($replacementId !== null && $replacementId > 0)) {
                $replacement = $this->resolveReplacement($target, $replacementId);
            }

            if ((int) $summary['total'] > 0 && ! $replacement instanceof User) {
                throw ValidationException::withMessages([
                    'transfer_to_user_id' => 'Impossible de changer le rattachement : des taches ouvertes existent et aucun repreneur actif du meme perimetre n a ete trouve.',
                ]);
            }

            $transfer = $replacement instanceof User
                ? $this->transferOpenAssignments($target, $replacement)
                : $this->emptyTransferStats();

            $historyId = null;
            if (Schema::hasTable('user_assignment_histories')) {
                $history = UserAssignmentHistory::query()->create([
                    'user_id' => (int) $target->id,
                    'changed_by' => (int) $actor->id,
                    'previous_role' => $previous['role'],
                    'new_role' => $next['role'],
                    'previous_custom_role_code' => $previous['custom_role_code'],
                    'new_custom_role_code' => $next['custom_role_code'],
                    'previous_direction_id' => $previous['direction_id'],
                    'new_direction_id' => $next['direction_id'],
                    'previous_service_id' => $previous['service_id'],
                    'new_service_id' => $next['service_id'],
                    'previous_unite_dg_id' => $previous['unite_dg_id'],
                    'new_unite_dg_id' => $next['unite_dg_id'],
                    'transfer_to_user_id' => $replacement?->id,
                    'open_assignments_before' => $summary,
                    'transfer_summary' => $transfer,
                    'reason' => $normalizedReason,
                    'changed_at' => now(),
                ]);
                $historyId = (int) $history->id;
            }

            return [
                'status' => 'assignment_changed',
                'reason' => $normalizedReason,
                'history_id' => $historyId,
                'previous_scope' => $previous,
                'new_scope' => $next,
                'open_assignments_before' => $summary,
                'replacement_user_id' => $replacement?->id,
                'replacement_user_name' => $replacement?->name,
                'transfers' => $transfer,
            ];
        });
    }

    /**
     * @return array{total:int,actions_responsable:int,actions_rmo:int,sous_actions:int,objectifs_operationnels:int}
     */
    public function openAssignmentSummary(User $target): array
    {
        $targetId = (int) $target->id;

        $actionOwnerCount = $this->actionsOwnedBy($targetId)->count();
        $rmoCount = $this->actionResponsibleRows($targetId)->count();
        $subActionCount = $this->openSubActionsFor($targetId)->count();
        $objectiveCount = $this->openOperationalObjectivesFor($targetId)->count();

        return [
            'total' => $actionOwnerCount + $rmoCount + $subActionCount + $objectiveCount,
            'actions_responsable' => $actionOwnerCount,
            'actions_rmo' => $rmoCount,
            'sous_actions' => $subActionCount,
            'objectifs_operationnels' => $objectiveCount,
        ];
    }

    public function suggestedReplacement(User $target): ?User
    {
        return $this->resolveReplacement($target, null);
    }

    /**
     * @return array{actions_responsable:int,actions_rmo:int,sous_actions:int,objectifs_operationnels:int}
     */
    private function transferOpenAssignments(User $from, User $to): array
    {
        $this->ensureReplacementScope($from, $to);

        return [
            'actions_responsable' => $this->transferActionOwners((int) $from->id, (int) $to->id),
            'actions_rmo' => $this->transferActionResponsibleRows((int) $from->id, (int) $to->id),
            'sous_actions' => $this->transferSubActions((int) $from->id, (int) $to->id),
            'objectifs_operationnels' => $this->transferOperationalObjectives((int) $from->id, (int) $to->id),
        ];
    }

    private function resolveReplacement(User $target, ?int $replacementId): ?User
    {
        if ($replacementId !== null && $replacementId > 0) {
            $replacement = User::query()
                ->whereKey($replacementId)
                ->where('id', '!=', (int) $target->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if (! $replacement instanceof User) {
                throw ValidationException::withMessages([
                    'transfer_to_user_id' => 'Le repreneur selectionne doit etre un utilisateur actif.',
                ]);
            }

            $this->ensureReplacementScope($target, $replacement);

            return $replacement;
        }

        $query = User::query()
            ->where('id', '!=', (int) $target->id)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if ($target->service_id !== null) {
            $query->where('service_id', (int) $target->service_id);
        } elseif ($target->direction_id !== null) {
            $query->where('direction_id', (int) $target->direction_id);
        } else {
            return null;
        }

        return $query
            ->orderByRaw(
                'CASE WHEN role = ? THEN 0 WHEN role = ? THEN 1 WHEN role = ? THEN 2 ELSE 3 END',
                [User::ROLE_AGENT, User::ROLE_SERVICE, User::ROLE_DIRECTION]
            )
            ->orderBy('id')
            ->first();
    }

    private function ensureReplacementScope(User $from, User $to): void
    {
        if ($from->service_id !== null && (int) $to->service_id !== (int) $from->service_id) {
            throw ValidationException::withMessages([
                'transfer_to_user_id' => 'Le repreneur doit appartenir au meme service ou a la meme unite que le compte desactive.',
            ]);
        }

        if ($from->service_id === null && $from->direction_id !== null && (int) $to->direction_id !== (int) $from->direction_id) {
            throw ValidationException::withMessages([
                'transfer_to_user_id' => 'Le repreneur doit appartenir a la meme direction que le compte desactive.',
            ]);
        }
    }

    /**
     * @return array{role:?string,custom_role_code:?string,direction_id:?int,service_id:?int,unite_dg_id:?int}
     */
    private function assignmentSnapshot(User $user): array
    {
        return [
            'role' => $this->nullableString($user->role),
            'custom_role_code' => $this->nullableString($user->custom_role_code),
            'direction_id' => $this->nullableInt($user->direction_id),
            'service_id' => $this->nullableInt($user->service_id),
            'unite_dg_id' => $this->nullableInt($user->unite_dg_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{role:?string,custom_role_code:?string,direction_id:?int,service_id:?int,unite_dg_id:?int}
     */
    private function assignmentSnapshotFromAttributes(User $user, array $attributes): array
    {
        $snapshot = $this->assignmentSnapshot($user);

        foreach (['role', 'custom_role_code'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $snapshot[$key] = $this->nullableString($attributes[$key]);
            }
        }

        foreach (['direction_id', 'service_id', 'unite_dg_id'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $snapshot[$key] = $this->nullableInt($attributes[$key]);
            }
        }

        return $snapshot;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function transferActionOwners(int $fromUserId, int $toUserId): int
    {
        $actionIds = $this->actionsOwnedBy($fromUserId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($actionIds === []) {
            return 0;
        }

        $payload = ['responsable_id' => $toUserId];
        if (Schema::hasColumn('actions', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('actions')
            ->whereIn('id', $actionIds)
            ->update($payload);

        return count($actionIds);
    }

    private function transferActionResponsibleRows(int $fromUserId, int $toUserId): int
    {
        if (! Schema::hasTable('action_responsables')) {
            return 0;
        }

        $rows = $this->actionResponsibleRows($fromUserId)
            ->get(['action_responsables.id', 'action_responsables.action_id', 'action_responsables.is_primary']);

        $count = 0;
        foreach ($rows as $row) {
            $existing = DB::table('action_responsables')
                ->where('action_id', (int) $row->action_id)
                ->where('user_id', $toUserId)
                ->first(['id', 'is_primary']);

            if ($existing !== null) {
                if ((bool) $row->is_primary && ! (bool) $existing->is_primary) {
                    $payload = ['is_primary' => true];
                    if (Schema::hasColumn('action_responsables', 'updated_at')) {
                        $payload['updated_at'] = now();
                    }

                    DB::table('action_responsables')
                        ->where('id', (int) $existing->id)
                        ->update($payload);
                }

                DB::table('action_responsables')
                    ->where('id', (int) $row->id)
                    ->delete();
                $count++;

                continue;
            }

            $payload = ['user_id' => $toUserId];
            if (Schema::hasColumn('action_responsables', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('action_responsables')
                ->where('id', (int) $row->id)
                ->update($payload);
            $count++;
        }

        return $count;
    }

    private function transferSubActions(int $fromUserId, int $toUserId): int
    {
        $ids = $this->openSubActionsFor($fromUserId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        $payload = ['agent_id' => $toUserId];
        if (Schema::hasColumn('sous_actions', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('sous_actions')
            ->whereIn('id', $ids)
            ->update($payload);

        return count($ids);
    }

    private function transferOperationalObjectives(int $fromUserId, int $toUserId): int
    {
        $ids = $this->openOperationalObjectivesFor($fromUserId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        $payload = ['responsable_id' => $toUserId];
        if (Schema::hasColumn('pao_objectifs_operationnels', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('pao_objectifs_operationnels')
            ->whereIn('id', $ids)
            ->update($payload);

        return count($ids);
    }

    private function actionsOwnedBy(int $userId): Builder
    {
        $query = DB::table('actions')
            ->where('responsable_id', $userId);

        $this->applyOpenActionFilters($query);

        return $query;
    }

    private function actionResponsibleRows(int $userId): Builder
    {
        if (! Schema::hasTable('action_responsables')) {
            return DB::table('users')->whereRaw('1 = 0');
        }

        $query = DB::table('action_responsables')
            ->join('actions', 'actions.id', '=', 'action_responsables.action_id')
            ->where('action_responsables.user_id', $userId);

        $this->applyOpenActionFilters($query, 'actions');

        return $query;
    }

    private function openSubActionsFor(int $userId): Builder
    {
        if (! Schema::hasTable('sous_actions') || ! Schema::hasColumn('sous_actions', 'agent_id')) {
            return DB::table('users')->whereRaw('1 = 0');
        }

        $query = DB::table('sous_actions')
            ->where('agent_id', $userId);

        if (Schema::hasColumn('sous_actions', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (Schema::hasColumn('sous_actions', 'statut')) {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('statut')
                    ->orWhereNotIn('statut', [
                        'validee_chef',
                        'validee',
                        'terminee',
                        'termine',
                        'cloturee',
                        'annulee',
                        'annule',
                    ]);
            });
        }

        return $query;
    }

    private function openOperationalObjectivesFor(int $userId): Builder
    {
        if (! Schema::hasTable('pao_objectifs_operationnels')) {
            return DB::table('users')->whereRaw('1 = 0');
        }

        $query = DB::table('pao_objectifs_operationnels')
            ->where('responsable_id', $userId);

        if (Schema::hasColumn('pao_objectifs_operationnels', 'statut_realisation')) {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('statut_realisation')
                    ->orWhereNotIn('statut_realisation', ['termine', 'annule']);
            });
        }

        return $query;
    }

    private function applyOpenActionFilters(Builder $query, string $table = 'actions'): void
    {
        if (Schema::hasColumn('actions', 'deleted_at')) {
            $query->whereNull($table.'.deleted_at');
        }

        if (Schema::hasColumn('actions', 'date_fin_reelle')) {
            $query->whereNull($table.'.date_fin_reelle');
        }

        if (Schema::hasColumn('actions', 'statut_dynamique')) {
            $query->where(function (Builder $builder) use ($table): void {
                $builder->whereNull($table.'.statut_dynamique')
                    ->orWhereNotIn($table.'.statut_dynamique', ActionTrackingService::completedActionStatuses());
            });
        }

        if (Schema::hasColumn('actions', 'statut')) {
            $query->where(function (Builder $builder) use ($table): void {
                $builder->whereNull($table.'.statut')
                    ->orWhereNotIn($table.'.statut', ['termine', 'annule']);
            });
        }
    }

    /**
     * @return array{actions_responsable:int,actions_rmo:int,sous_actions:int,objectifs_operationnels:int}
     */
    private function emptyTransferStats(): array
    {
        return [
            'actions_responsable' => 0,
            'actions_rmo' => 0,
            'sous_actions' => 0,
            'objectifs_operationnels' => 0,
        ];
    }

    private function normalizeReason(?string $reason, string $fallback): string
    {
        $normalized = trim((string) $reason);

        return $normalized !== '' ? $normalized : $fallback;
    }
}
