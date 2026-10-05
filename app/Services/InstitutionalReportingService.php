<?php

namespace App\Services;

use App\Models\InstitutionalReport;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionalReportingService
{
    public function canView(User $user): bool
    {
        return $user->hasPermission('reporting.read');
    }

    public function canSubmit(User $user): bool
    {
        return $this->canView($user) && ! $user->hasRole(User::ROLE_AUDITEUR, User::ROLE_INVITE_LECTURE);
    }

    public function canViewReport(User $user, InstitutionalReport $report): bool
    {
        if (! $this->canView($user)) {
            return false;
        }

        if ($this->hasGlobalReviewScope($user) || (int) $report->submitted_by === (int) $user->id) {
            return true;
        }

        if ($user->service_id !== null && (int) $report->service_id === (int) $user->service_id) {
            return true;
        }

        return $user->direction_id !== null && (int) $report->direction_id === (int) $user->direction_id;
    }

    public function canAmend(User $user, InstitutionalReport $report): bool
    {
        return $this->canSubmit($user)
            && (int) $report->submitted_by === (int) $user->id
            && in_array($report->status, [InstitutionalReport::STATUS_DRAFT, InstitutionalReport::STATUS_RETURNED], true);
    }

    public function canReview(User $user, InstitutionalReport $report): bool
    {
        return match ((string) $report->status) {
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => $user->hasRole(User::ROLE_SCIQ, User::ROLE_SCIQ_SUIVI_GLOBAL),
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => $user->hasRole(User::ROLE_PLANIFICATION),
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => $user->hasRole(User::ROLE_CHEF_UNITE_SCIQ),
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => $user->hasRole(User::ROLE_CHEF_PLANIFICATION),
            default => false,
        };
    }

    public function canReviewAnything(User $user): bool
    {
        return $user->hasRole(
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_PLANIFICATION,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): InstitutionalReport
    {
        if (! $this->canSubmit($actor)) {
            abort(403, 'Votre profil ne peut pas deposer de rapport institutionnel.');
        }

        if ((string) ($payload['report_type'] ?? '') === InstitutionalReport::TYPE_MEETING) {
            throw ValidationException::withMessages([
                'report_type' => 'Le module Réunions et PV a été retiré.',
            ]);
        }

        $scope = $this->resolveSubmissionScope($payload, $actor);

        return InstitutionalReport::query()->create([
            'report_type' => (string) $payload['report_type'],
            'meeting_type' => null,
            'title' => trim((string) $payload['title']),
            'summary' => $this->nullableText($payload['summary'] ?? null),
            'direction_id' => $scope['direction_id'],
            'service_id' => $scope['service_id'],
            'responsible_id' => null,
            'held_at' => null,
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $actor->id,
            'review_history' => [$this->historyEntry($actor, 'created', 'Rapport institutionnel créé.')],
        ]);
    }

    public function submit(InstitutionalReport $report, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canAmend($actor, $lockedReport)) {
                abort(403, 'Seul le deposant peut soumettre ce rapport dans son etat actuel.');
            }

            if (! $lockedReport->justificatifs()->exists()) {
                throw ValidationException::withMessages([
                    'attachment' => 'Ajoutez le compte rendu, le rapport ou la piece justificative avant la soumission.',
                ]);
            }

            $lockedReport->forceFill([
                'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                'submitted_at' => now(),
                'returned_at' => null,
                'review_history' => $this->appendHistory($lockedReport, $actor, 'submitted_sciq', 'Soumis au SCIQ pour verification.'),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resubmit(InstitutionalReport $report, array $payload, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $payload, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canAmend($actor, $lockedReport)) {
                abort(403, 'Ce rapport ne peut plus etre corrige avec votre profil.');
            }

            if (! $lockedReport->justificatifs()->exists()) {
                throw ValidationException::withMessages([
                    'attachment' => 'Une piece jointe est requise avant la nouvelle soumission.',
                ]);
            }

            $lockedReport->forceFill([
                'summary' => $this->nullableText($payload['summary'] ?? $lockedReport->summary),
                'recommendations' => $this->nullableText($payload['recommendations'] ?? $lockedReport->recommendations),
                'difficulties' => $this->nullableText($payload['difficulties'] ?? $lockedReport->difficulties),
                'observations' => $this->nullableText($payload['observations'] ?? $lockedReport->observations),
                'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                'submitted_at' => now(),
                'returned_at' => null,
                'review_history' => $this->appendHistory($lockedReport, $actor, 'resubmitted', 'Correction deposee et transmise au SCIQ.'),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    public function review(InstitutionalReport $report, string $decision, string $note, User $actor): InstitutionalReport
    {
        return DB::transaction(function () use ($report, $decision, $note, $actor): InstitutionalReport {
            $lockedReport = InstitutionalReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! $this->canReview($actor, $lockedReport)) {
                abort(403, 'Ce rapport n est pas dans votre file de verification.');
            }

            $nextStatus = $decision === 'return'
                ? InstitutionalReport::STATUS_RETURNED
                : $this->nextStatus((string) $lockedReport->status);
            $event = $decision === 'return' ? 'returned' : 'approved';
            $message = $decision === 'return'
                ? 'Retour au deposant pour correction.'
                : 'Verification realisee: transmission a l etape suivante.';

            $lockedReport->forceFill([
                'status' => $nextStatus,
                'verified_at' => $nextStatus === InstitutionalReport::STATUS_VERIFIED ? now() : null,
                'returned_at' => $decision === 'return' ? now() : null,
                'review_history' => $this->appendHistory($lockedReport, $actor, $event, $message, $note),
            ])->save();

            return $lockedReport->refresh();
        }, attempts: 3);
    }

    /**
     * @return Builder<InstitutionalReport>
     */
    public function visibleQuery(User $user): Builder
    {
        $query = InstitutionalReport::query()
            ->where('report_type', '!=', InstitutionalReport::TYPE_MEETING);

        if ($this->hasGlobalReviewScope($user)) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->where('submitted_by', $user->id);
            if ($user->service_id !== null) {
                $scope->orWhere('service_id', $user->service_id);
            }
            if ($user->direction_id !== null) {
                $scope->orWhere('direction_id', $user->direction_id);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<InstitutionalReport>
     */
    public function filteredVisibleQuery(User $user, array $filters): Builder
    {
        $query = $this->visibleQuery($user);
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $reportsQuery) => $reportsQuery
                ->whereLike('title', $search)
                ->orWhereLike('summary', $search)
                ->orWhereLike('actual_agenda', $search)
                ->orWhereLike('decisions', $search));
        }

        foreach (['direction_id', 'service_id'] as $field) {
            $value = $this->positiveInteger($filters[$field] ?? null);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $year = $this->positiveInteger($filters['year'] ?? null);
        if ($year !== null) {
            $query->whereYear('scheduled_at', $year);
        }
        $month = $this->positiveInteger($filters['month'] ?? null);
        if ($month !== null && $month <= 12) {
            $query->whereMonth('scheduled_at', $month);
        }
        $quarter = $this->positiveInteger($filters['quarter'] ?? null);
        if ($quarter !== null && $quarter <= 4) {
            $quarterYear = $year ?? now()->year;
            $quarterStart = Carbon::create($quarterYear, (($quarter - 1) * 3) + 1, 1)->startOfQuarter();
            $query->whereBetween('scheduled_at', [$quarterStart, $quarterStart->copy()->endOfQuarter()]);
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, [
            InstitutionalReport::STATUS_DRAFT,
            InstitutionalReport::STATUS_SUBMITTED_SCIQ,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING,
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            InstitutionalReport::STATUS_VERIFIED,
            InstitutionalReport::STATUS_RETURNED,
        ], true)) {
            $query->where('status', $status);
        }

        return $query;
    }

    /** @return array{total:int,pending:int,verified:int} */
    public function summaryFor(User $user, array $filters = []): array
    {
        $reports = $this->filteredVisibleQuery($user, $filters);

        return [
            'total' => (clone $reports)->count(),
            'pending' => (clone $reports)->whereIn('status', [
                InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING,
                InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
                InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            ])->count(),
            'verified' => (clone $reports)->where('status', InstitutionalReport::STATUS_VERIFIED)->count(),
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            InstitutionalReport::STATUS_DRAFT => 'Programme / brouillon',
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => 'Verification SCIQ',
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => 'Verification Planification',
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => 'Validation Chef SCIQ',
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => 'Validation Chef Planification',
            InstitutionalReport::STATUS_VERIFIED => 'Verifie',
            InstitutionalReport::STATUS_RETURNED => 'Correction demandee',
            default => $status,
        };
    }

    /**
     * @return list<array{status:string,label:string,actor:string,description:string}>
     */
    public function verificationSteps(): array
    {
        return [
            [
                'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
                'label' => 'Contrôle SCIQ',
                'actor' => 'SCIQ',
                'description' => 'Vérifie le dossier, les pièces et le périmètre déclaré.',
            ],
            [
                'status' => InstitutionalReport::STATUS_SUBMITTED_PLANNING,
                'label' => 'Vérification Planification',
                'actor' => 'Planification',
                'description' => 'Confirme la cohérence avec le suivi PAS, PAO et PTA.',
            ],
            [
                'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
                'label' => 'Validation Chef SCIQ',
                'actor' => 'Chef SCIQ',
                'description' => 'Appose le visa hiérarchique SCIQ lorsque le contrôle est conforme.',
            ],
            [
                'status' => InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
                'label' => 'Validation Chef Planification',
                'actor' => 'Chef Planification',
                'description' => 'Donne le visa final du circuit institutionnel.',
            ],
        ];
    }

    /**
     * @return list<array{status:string,label:string,actor:string,description:string,state:string}>
     */
    public function verificationProgress(InstitutionalReport $report): array
    {
        $steps = $this->verificationSteps();
        $currentIndex = collect($steps)->search(
            static fn (array $step): bool => $step['status'] === $report->status
        );

        return collect($steps)
            ->values()
            ->map(function (array $step, int $index) use ($currentIndex, $report): array {
                $state = 'pending';

                if ($report->status === InstitutionalReport::STATUS_RETURNED) {
                    $state = 'returned';
                } elseif ($report->status === InstitutionalReport::STATUS_VERIFIED || ($currentIndex !== false && $index < $currentIndex)) {
                    $state = 'done';
                } elseif ($currentIndex !== false && $index === $currentIndex) {
                    $state = 'current';
                }

                return [...$step, 'state' => $state];
            })
            ->all();
    }

    /** @return array{label:string,description:string,tone:string} */
    public function nextAction(InstitutionalReport $report): array
    {
        return match ($report->status) {
            InstitutionalReport::STATUS_DRAFT => [
                'label' => 'Soumettre au SCIQ',
                'description' => 'Le déposant doit joindre les pièces puis transmettre le dossier au SCIQ.',
                'tone' => 'info',
            ],
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => [
                'label' => 'Contrôle attendu du SCIQ',
                'description' => 'Un contrôleur SCIQ doit valider le dossier ou demander une correction motivée.',
                'tone' => 'warning',
            ],
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => [
                'label' => 'Vérification attendue de la Planification',
                'description' => 'La Planification doit contrôler la cohérence du rapport avec les données de suivi.',
                'tone' => 'warning',
            ],
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => [
                'label' => 'Visa attendu du Chef SCIQ',
                'description' => 'Le Chef SCIQ doit confirmer le contrôle avant transmission finale.',
                'tone' => 'warning',
            ],
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => [
                'label' => 'Visa final attendu du Chef Planification',
                'description' => 'Le Chef Planification doit valider le dossier pour le classer comme vérifié.',
                'tone' => 'warning',
            ],
            InstitutionalReport::STATUS_RETURNED => [
                'label' => 'Correction attendue du déposant',
                'description' => 'Le déposant doit corriger le résumé ou les pièces puis resoumettre le dossier.',
                'tone' => 'danger',
            ],
            InstitutionalReport::STATUS_VERIFIED => [
                'label' => 'Circuit terminé',
                'description' => 'Le rapport est vérifié. Les pièces, décisions et dates restent consultables dans l’historique.',
                'tone' => 'success',
            ],
            default => [
                'label' => 'Statut à examiner',
                'description' => 'Le statut du dossier doit être vérifié avant toute action.',
                'tone' => 'neutral',
            ],
        };
    }

    /** @return array{at?:string,user?:string,role?:string,event?:string,message?:string,note?:string|null}|null */
    public function latestReturnEntry(InstitutionalReport $report): ?array
    {
        return collect(is_array($report->review_history) ? $report->review_history : [])
            ->reverse()
            ->first(static fn (array $entry): bool => ($entry['event'] ?? null) === 'returned');
    }

    private function hasGlobalReviewScope(User $user): bool
    {
        return $user->hasGlobalReadAccess() || $user->hasRole(
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_UNITE_SCIQ,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{direction_id:?int,service_id:?int}
     */
    private function resolveSubmissionScope(array $payload, User $actor): array
    {
        $directionId = $this->positiveInteger($payload['direction_id'] ?? null);
        $serviceId = $this->positiveInteger($payload['service_id'] ?? null);

        if ($serviceId !== null) {
            $service = Service::query()->findOrFail($serviceId);
            if ($directionId !== null && $directionId !== (int) $service->direction_id) {
                throw ValidationException::withMessages(['service_id' => 'Le service selectionne ne correspond pas a la direction.']);
            }
            $directionId = (int) $service->direction_id;
        }

        if ($directionId === null) {
            $directionId = $actor->direction_id;
        }
        if ($serviceId === null && ! $this->hasGlobalReviewScope($actor)) {
            $serviceId = $actor->service_id;
        }

        if ($directionId === null) {
            throw ValidationException::withMessages(['direction_id' => 'Choisissez une direction pour ce rapport.']);
        }

        if (! $this->hasGlobalReviewScope($actor)) {
            if ($actor->direction_id !== null && $directionId !== (int) $actor->direction_id) {
                throw ValidationException::withMessages(['direction_id' => 'Vous ne pouvez deposer un rapport que dans votre direction.']);
            }
            if ($actor->service_id !== null && $serviceId !== null && $serviceId !== (int) $actor->service_id && ! $actor->hasRole(User::ROLE_DIRECTION)) {
                throw ValidationException::withMessages(['service_id' => 'Vous ne pouvez deposer un rapport que pour votre service.']);
            }
        }

        return ['direction_id' => $directionId, 'service_id' => $serviceId];
    }

    private function nextStatus(string $status): string
    {
        return match ($status) {
            InstitutionalReport::STATUS_SUBMITTED_SCIQ => InstitutionalReport::STATUS_SUBMITTED_PLANNING,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING => InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
            InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF => InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF => InstitutionalReport::STATUS_VERIFIED,
            default => throw ValidationException::withMessages(['report' => 'Etape de verification invalide.']),
        };
    }

    /** @return array{at:string,user_id:int,user:string,role:string,event:string,message:string,note:?string} */
    private function historyEntry(User $actor, string $event, string $message, ?string $note = null): array
    {
        return [
            'at' => Carbon::now()->toIso8601String(),
            'user_id' => (int) $actor->id,
            'user' => (string) $actor->name,
            'role' => (string) $actor->roleLabel(),
            'event' => $event,
            'message' => $message,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function appendHistory(InstitutionalReport $report, User $actor, string $event, string $message, ?string $note = null): array
    {
        $history = is_array($report->review_history) ? $report->review_history : [];
        $history[] = $this->historyEntry($actor, $event, $message, $note);

        return $history;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
