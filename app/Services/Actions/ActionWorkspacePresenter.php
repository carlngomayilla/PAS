<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

class ActionWorkspacePresenter
{
    /**
     * @param  array{
     *     track_action: bool,
     *     track_sub_actions: bool,
     *     review_chef: bool,
     *     review_controller: bool,
     *     review_planification?: bool,
     *     request_deadline: bool,
     *     review_deadline_chef: bool,
     *     review_deadline_director: bool,
     *     review_deadline_final: bool,
     *     apply_deadline: bool,
     *     submit_financing: bool,
     *     review_financing_daf: bool,
     *     review_financing_dg: bool
     * }  $permissions
     * @return array{
     *     role_label: string,
     *     next_step: array{eyebrow: string, title: string, message: string, anchor: string, action_label: string, tone: string},
     *     readiness_checks: list<array{label: string, value: string, state: string, anchor: string}>,
     *     hierarchy: list<array{label: string, value: string}>,
     *     deadline: array{date: ?Carbon, label: string, state: string, days: ?int},
     *     configuration: array{configured: bool, target_label: string, proof_label: string, rmo_label: string},
     *     active_deadline_request: ?DeadlineExtensionRequest
     * }
     */
    public function present(Action $action, User $user, array $permissions): array
    {
        $activeDeadlineRequest = $this->activeDeadlineRequest($action);

        return [
            'role_label' => $user->roleLabel(),
            'next_step' => $this->nextStep($action, $permissions, $activeDeadlineRequest),
            'readiness_checks' => $this->readinessChecks($action),
            'hierarchy' => $this->hierarchy($action),
            'deadline' => $this->deadline($action),
            'configuration' => $this->configuration($action),
            'active_deadline_request' => $activeDeadlineRequest,
        ];
    }

    /**
     * @param  array<string, bool>  $permissions
     * @return array{eyebrow: string, title: string, message: string, anchor: string, action_label: string, tone: string}
     */
    private function nextStep(
        Action $action,
        array $permissions,
        ?DeadlineExtensionRequest $activeDeadlineRequest
    ): array {
        $validationStatus = (string) ($action->statut_validation ?: 'non_soumise');

        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            return $this->step(
                'Configuration',
                'Parametrage requis dans le PTA',
                "L'execution et le report restent indisponibles tant que la cible, le responsable et les echeances ne sont pas completes.",
                '#action-fiche',
                'Verifier la fiche',
                'warning'
            );
        }

        if (($permissions['apply_deadline'] ?? false)
            && $activeDeadlineRequest?->status === DeadlineExtensionRequest::STATUS_APPROUVEE
        ) {
            return $this->step(
                'Modification approuvee',
                'Appliquer la décision historique',
                'Ce dossier provient de l’ancien circuit. La DG peut appliquer les paramètres déjà approuvés.',
                '#action-echeances',
                'Appliquer la modification',
                'success'
            );
        }

        if (($permissions['review_deadline_final'] ?? false)
            && $activeDeadlineRequest?->status === DeadlineExtensionRequest::STATUS_TRANSMISE_DG
        ) {
            return $this->step(
                'Modification d’action',
                'Rendre l’accord final',
                'Le chef de service et le directeur ont donné leur accord. Une validation DG applique automatiquement et atomiquement les seuls paramètres demandés par le RMO.',
                '#action-echeances',
                'Décider et appliquer',
                'warning'
            );
        }

        if (($permissions['review_deadline_director'] ?? false)
            && $activeDeadlineRequest?->status === DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION
        ) {
            return $this->step(
                'Modification d’action',
                'Donner l’accord du directeur',
                'Vérifier les paramètres demandés, la justification et la pièce avant transmission à la DG.',
                '#action-echeances',
                'Examiner la demande',
                'warning'
            );
        }

        if (($permissions['review_deadline_chef'] ?? false)
            && in_array($activeDeadlineRequest?->status, [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
            ], true)
        ) {
            return $this->step(
                'Modification d’action',
                'Valider au niveau du service',
                'Le chef de service doit valider, refuser ou demander un complément avant l’accord du directeur.',
                '#action-echeances',
                'Examiner la demande',
                'warning'
            );
        }

        if ($permissions['review_financing_dg'] ?? false) {
            return $this->step(
                'Decision financiere',
                'Rendre la decision finale DG',
                'La DAF a instruit le besoin, confirme le montant et transmis son avis. La decision DG cloture le circuit financier.',
                '#action-financement',
                'Statuer sur le financement',
                'warning'
            );
        }

        if ($permissions['review_financing_daf'] ?? false) {
            return $this->step(
                'Instruction DAF',
                'Controler le dossier financier',
                'Verifier le besoin, la source, le montant et les pieces avant retour au RMO ou transmission a la DG.',
                '#action-financement',
                'Instruire le dossier',
                'info'
            );
        }

        if ($permissions['submit_financing'] ?? false) {
            $isCorrection = in_array($action->financementStatus(), [
                Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                Action::FINANCEMENT_REJETE_DAF,
            ], true);

            return $this->step(
                $isCorrection ? 'Correction financiere' : 'Dossier financier',
                $isCorrection ? 'Completer puis resoumettre a la DAF' : 'Soumettre le besoin a la DAF',
                $isCorrection
                    ? 'Repondez au dernier avis DAF et joignez une nouvelle piece justificative avant la resoumission.'
                    : 'Confirmez la source, le commentaire du RMO et la piece justificative avant envoi officiel.',
                '#action-financement',
                $isCorrection ? 'Corriger le dossier' : 'Soumettre a la DAF',
                $isCorrection ? 'warning' : 'primary'
            );
        }

        if (($permissions['review_planification'] ?? false)
            && in_array($validationStatus, ['validee_chef', 'soumise_planification', 'attente_validation_planification'], true)
        ) {
            return $this->step(
                'Contrôle Planification',
                "Viser ou renvoyer l'execution",
                'La Planification vérifie la cohérence du résultat, puis transmet le dossier au SCIQ pour le visa final.',
                '#action-validation',
                'Ouvrir le contrôle',
                'info'
            );
        }

        if (($permissions['review_planification'] ?? false) && $validationStatus === 'retour_sciq') {
            return $this->step(
                'Arbitrage Planification',
                'Examiner le retour SCIQ',
                'Acceptez le retour vers le Chef ou contestez-le pour demander un réexamen SCIQ.',
                '#action-validation',
                'Traiter le retour SCIQ',
                'warning'
            );
        }

        if (($permissions['review_controller'] ?? false)
            && in_array($validationStatus, ['soumise_controle', 'attente_validation_sciq', 'reexamen_sciq'], true)
        ) {
            return $this->step(
                'Validation finale SCIQ',
                'Clôturer par le SCIQ',
                'Le chef et la Planification ont visé le dossier. Le SCIQ donne le dernier visa ou renvoie une correction motivée.',
                '#action-validation',
                'Ouvrir la validation finale',
                'warning'
            );
        }

        if (($permissions['review_chef'] ?? false)
            && in_array($validationStatus, ['soumise_chef', 'attente_validation_chef', 'retour_planification'], true)
        ) {
            return $this->step(
                'Visa hierarchique',
                "Verifier l'execution soumise",
                'Comparer les resultats, les justificatifs et la cible avant de viser ou de demander une correction.',
                '#action-validation',
                'Examiner la soumission',
                'info'
            );
        }

        if (($permissions['track_action'] ?? false) || ($permissions['track_sub_actions'] ?? false)) {
            $isCorrection = in_array($validationStatus, ['correction_demandee', 'retour_chef', 'correction_controle', 'retour_sciq', 'correction_planification', 'retour_planification', 'rejetee_chef'], true);

            return $this->step(
                $isCorrection ? 'Correction attendue' : 'Execution',
                $isCorrection ? 'Corriger puis resoumettre' : "Mettre a jour l'avancement",
                $isCorrection
                    ? 'Le dossier a ete rouvert. Repondez au motif, actualisez les resultats et joignez la preuve attendue.'
                    : 'Enregistrez les resultats realises et les justificatifs avant la soumission au chef de service.',
                '#action-validation',
                $isCorrection ? 'Traiter la correction' : 'Faire le suivi',
                $isCorrection ? 'warning' : 'primary'
            );
        }

        if (in_array($validationStatus, ['validee_controle', 'validee_planification', 'validee_direction', 'achevee_validee'], true)) {
            return $this->step(
                'Dossier cloture',
                'Consulter le resultat officiel',
                "L'execution a ete validee et cloturee. Les resultats, les preuves et les decisions restent consultables.",
                '#action-validation',
                'Voir le resultat',
                'success'
            );
        }

        if ($activeDeadlineRequest instanceof DeadlineExtensionRequest) {
            return $this->step(
                'Report echeance',
                'Suivre la demande en cours',
                "Le dossier poursuit son circuit. Aucune date n'est modifiee avant la decision finale et l'application par un controleur.",
                '#action-echeances',
                'Voir le circuit',
                'info'
            );
        }

        return $this->step(
            'Consultation',
            "Consulter le dossier d'execution",
            "Votre profil dispose d'un acces en lecture aux resultats, justificatifs, decisions et evenements de cette action.",
            '#action-fiche',
            'Parcourir la fiche',
            'neutral'
        );
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function hierarchy(Action $action): array
    {
        $pta = $action->pta;
        $pao = $action->pao ?? $pta?->pao;
        $operationalObjective = $action->objectifOperationnel ?? $pta?->objectifOperationnel;
        $pas = $pao?->pas ?? $operationalObjective?->pas;
        $strategicAxis = $operationalObjective?->pasAxe ?? $operationalObjective?->pasObjectif?->pasAxe;
        $strategicObjective = $operationalObjective?->pasObjectif ?? $pao?->pasObjectif;

        return array_values(array_filter([
            $this->hierarchyItem('PAS', $pas?->titre),
            $this->hierarchyItem('Axe strategique', $this->codedLabel($strategicAxis?->code, $strategicAxis?->libelle)),
            $this->hierarchyItem('Objectif strategique', $this->codedLabel($strategicObjective?->code, $strategicObjective?->libelle)),
            $this->hierarchyItem('PAO', $pao?->titre),
            $this->hierarchyItem('Objectif operationnel', $operationalObjective?->libelle ?? $pao?->objectif_operationnel),
            $this->hierarchyItem('PTA', $pta?->titre),
        ]));
    }

    /**
     * @return array{date: ?Carbon, label: string, state: string, days: ?int}
     */
    private function deadline(Action $action): array
    {
        $deadline = $action->echeance_cible ?? $action->date_echeance ?? $action->date_fin;
        if ($deadline === null) {
            return ['date' => null, 'label' => 'Non definie', 'state' => 'missing', 'days' => null];
        }

        $deadlineDate = Carbon::parse($deadline)->startOfDay();
        $days = (int) now()->startOfDay()->diffInDays($deadlineDate, false);
        $isClosed = in_array((string) $action->statut_validation, ['validee_controle', 'validee_planification', 'validee_direction'], true);

        if ($isClosed) {
            return ['date' => $deadlineDate, 'label' => 'Dossier cloture', 'state' => 'closed', 'days' => $days];
        }

        if ($days < 0) {
            return ['date' => $deadlineDate, 'label' => abs($days).' j de retard', 'state' => 'late', 'days' => $days];
        }

        if ($days === 0) {
            return ['date' => $deadlineDate, 'label' => "Echeance aujourd'hui", 'state' => 'urgent', 'days' => 0];
        }

        if ($days <= 7) {
            return ['date' => $deadlineDate, 'label' => $days.' j restants', 'state' => 'warning', 'days' => $days];
        }

        return ['date' => $deadlineDate, 'label' => $days.' j restants', 'state' => 'planned', 'days' => $days];
    }

    /**
     * @return array{configured: bool, target_label: string, proof_label: string, rmo_label: string}
     */
    private function configuration(Action $action): array
    {
        $rmoNames = $action->responsables->pluck('name')->filter()->values();
        if ($rmoNames->isEmpty() && $action->responsable?->name) {
            $rmoNames->push($action->responsable->name);
        }

        $targetLabel = match (true) {
            $action->isQuantitative() => number_format((float) ($action->quantite_cible ?? 0), 0, ',', ' ').' '.trim((string) $action->unite_cible),
            $action->isComposee() => $action->sousActions->count().' sous-action(s)',
            default => 'Validation qualitative',
        };

        return [
            'configured' => (string) ($action->statut_parametrage ?? '') !== 'a_parametrer',
            'target_label' => trim($targetLabel),
            'proof_label' => $action->justificatif_obligatoire ? 'Obligatoire' : 'Selon execution',
            'rmo_label' => $rmoNames->isNotEmpty() ? $rmoNames->join(', ') : 'Non attribue',
        ];
    }

    /**
     * @return list<array{label: string, value: string, state: string, anchor: string}>
     */
    private function readinessChecks(Action $action): array
    {
        $validationStatus = (string) ($action->statut_validation ?: 'non_soumise');
        $progress = max(0.0, min(100.0, (float) ($action->progression_reelle ?? 0)));
        $proofCount = $this->executionProofCount($action);
        $returnReason = $this->latestReturnReason($action, $validationStatus);

        return [
            $this->readinessItem(
                'Resultat declare',
                $this->progressLabel($action, $progress),
                $progress > 0.0 ? 'success' : 'warning',
                '#action-validation'
            ),
            $this->readinessItem(
                'Justificatif d\'execution',
                $this->proofLabel($action, $proofCount),
                $action->justificatif_obligatoire && $proofCount === 0 ? 'warning' : ($proofCount > 0 ? 'success' : 'neutral'),
                '#action-justificatifs'
            ),
            $this->readinessItem(
                'Dernier retour',
                $returnReason,
                $this->isCorrectionStatus($validationStatus) ? 'warning' : 'success',
                '#action-discussion'
            ),
            $this->readinessItem(
                'Circuit officiel',
                $this->workflowLabel($validationStatus),
                in_array($validationStatus, ['validee_controle', 'validee_planification', 'validee_direction', 'achevee_validee'], true) ? 'success' : 'info',
                '#action-validation'
            ),
        ];
    }

    private function executionProofCount(Action $action): int
    {
        return $action->justificatifs
            ->whereIn('categorie', ['execution_quantitative', 'execution_non_quantitative', 'execution_mixte', 'final'])
            ->count();
    }

    private function progressLabel(Action $action, float $progress): string
    {
        if ($action->isComposee()) {
            $total = $action->sousActions->count();
            $validated = $action->sousActions
                ->whereIn('validation_status', ['validee', 'validee_planification'])
                ->count();

            return $validated.'/'.$total.' sous-action(s) visee(s) - '.number_format($progress, 0, ',', ' ').' %';
        }

        if ($action->isQuantitative()) {
            $realized = number_format((float) ($action->quantite_realisee ?? 0), 0, ',', ' ');
            $target = number_format((float) ($action->quantite_cible ?? 0), 0, ',', ' ');
            $unit = trim((string) ($action->unite_cible ?? ''));

            return trim($realized.'/'.$target.' '.$unit).' - '.number_format($progress, 0, ',', ' ').' %';
        }

        return number_format($progress, 0, ',', ' ').' % renseignes';
    }

    private function proofLabel(Action $action, int $proofCount): string
    {
        if ($proofCount > 0) {
            return $proofCount.' piece(s) d\'execution jointe(s)';
        }

        return $action->justificatif_obligatoire
            ? 'Piece obligatoire manquante'
            : 'Aucune piece obligatoire detectee';
    }

    private function latestReturnReason(Action $action, string $validationStatus): string
    {
        if (! $this->isCorrectionStatus($validationStatus)) {
            return 'Aucun retour correctif actif';
        }

        $reason = match ($validationStatus) {
            'correction_demandee', 'rejetee_chef', 'retour_chef' => $action->motif_validation_chef,
            'correction_controle', 'retour_sciq' => $action->controle_comment,
            'correction_planification', 'retour_planification' => $this->latestPlanificationReturnReason($action),
            default => null,
        };

        $normalizedReason = trim((string) $reason);

        return $normalizedReason !== '' ? $normalizedReason : 'Motif de correction a renseigner';
    }

    private function latestPlanificationReturnReason(Action $action): ?string
    {
        $log = $action->actionLogs->firstWhere('type_evenement', 'action_rejetee_planification');
        $details = is_array($log?->details) ? $log->details : [];
        $reason = trim((string) ($details['motif'] ?? ''));

        return $reason !== '' ? $reason : null;
    }

    private function workflowLabel(string $validationStatus): string
    {
        return match ($validationStatus) {
            'non_soumise' => 'A soumettre par le responsable',
            'soumise_chef', 'attente_validation_chef' => 'Visa Chef attendu',
            'validee_chef', 'soumise_planification', 'attente_validation_planification' => 'Validation Planification attendue',
            'soumise_controle', 'attente_validation_sciq' => 'Visa final SCIQ attendu',
            'reexamen_sciq' => 'Réexamen SCIQ attendu',
            'correction_demandee', 'rejetee_chef', 'retour_chef' => 'Correction demandée par le Chef',
            'correction_controle', 'retour_sciq' => 'Correction demandée par le SCIQ',
            'correction_planification' => 'Correction demandée par la Planification',
            'retour_planification' => 'Arbitrage Chef attendu après retour Planification',
            'validee_controle', 'validee_planification', 'validee_direction', 'achevee_validee' => 'Achevée et validée',
            default => 'Circuit a consulter',
        };
    }

    private function isCorrectionStatus(string $validationStatus): bool
    {
        return in_array($validationStatus, [
            'correction_demandee',
            'retour_chef',
            'correction_controle',
            'retour_sciq',
            'correction_planification',
            'retour_planification',
            'rejetee_chef',
        ], true);
    }

    /**
     * @return array{label: string, value: string, state: string, anchor: string}
     */
    private function readinessItem(string $label, string $value, string $state, string $anchor): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'state' => $state,
            'anchor' => $anchor,
        ];
    }

    private function activeDeadlineRequest(Action $action): ?DeadlineExtensionRequest
    {
        return $action->deadlineExtensionRequests->first(
            static fn (DeadlineExtensionRequest $request): bool => in_array((string) $request->status, [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
                DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DIRECTION,
                DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
                DeadlineExtensionRequest::STATUS_APPROUVEE,
            ], true)
        );
    }

    /**
     * @return array{eyebrow: string, title: string, message: string, anchor: string, action_label: string, tone: string}
     */
    private function step(
        string $eyebrow,
        string $title,
        string $message,
        string $anchor,
        string $actionLabel,
        string $tone
    ): array {
        return [
            'eyebrow' => $eyebrow,
            'title' => $title,
            'message' => $message,
            'anchor' => $anchor,
            'action_label' => $actionLabel,
            'tone' => $tone,
        ];
    }

    /**
     * @return array{label: string, value: string}|null
     */
    private function hierarchyItem(string $label, ?string $value): ?array
    {
        $normalizedValue = trim((string) $value);

        return $normalizedValue === '' ? null : ['label' => $label, 'value' => $normalizedValue];
    }

    private function codedLabel(?string $code, ?string $label): ?string
    {
        $parts = array_filter([trim((string) $code), trim((string) $label)]);

        return $parts === [] ? null : implode(' - ', $parts);
    }
}
