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
     *     request_deadline: bool,
     *     review_deadline_chef: bool,
     *     review_deadline_controller: bool,
     *     review_deadline_final: bool,
     *     apply_deadline: bool,
     *     submit_financing: bool,
     *     review_financing_daf: bool,
     *     review_financing_dg: bool
     * }  $permissions
     * @return array{
     *     role_label: string,
     *     next_step: array{eyebrow: string, title: string, message: string, anchor: string, action_label: string, tone: string},
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
                'Date approuvee',
                "Appliquer l'echeance validee",
                'La decision finale est acquise. Seul un controleur peut maintenant modifier la date planifiee.',
                '#action-echeances',
                'Appliquer la date',
                'success'
            );
        }

        if (($permissions['review_deadline_final'] ?? false)
            && in_array($activeDeadlineRequest?->status, [
                DeadlineExtensionRequest::STATUS_TRANSMISE_VALIDATION_FINALE,
                DeadlineExtensionRequest::STATUS_TRANSMISE_DG,
            ], true)
        ) {
            return $this->step(
                'Report echeance',
                'Rendre la decision finale',
                "Le dossier a recu les avis hierarchique et de controle. La date ne changera qu'apres cette decision puis son application par un controleur.",
                '#action-echeances',
                'Traiter le report',
                'warning'
            );
        }

        if (($permissions['review_deadline_controller'] ?? false)
            && $activeDeadlineRequest?->status === DeadlineExtensionRequest::STATUS_TRANSMISE_CONTROLE
        ) {
            return $this->step(
                'Report echeance',
                'Controler la demande de report',
                'Verifier la justification et la piece avant transmission au DG ou au Chef Planification.',
                '#action-echeances',
                'Controler le report',
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
                'Report echeance',
                'Donner un avis hierarchique',
                'Le chef de service doit viser, refuser ou demander un complement avant le controle.',
                '#action-echeances',
                'Examiner le report',
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

        if (($permissions['review_controller'] ?? false)
            && in_array($validationStatus, ['validee_chef', 'soumise_controle'], true)
        ) {
            return $this->step(
                'Controle final',
                "Valider ou renvoyer l'execution",
                "La performance ne devient officielle qu'apres la decision du controleur.",
                '#action-validation',
                'Ouvrir le controle',
                'info'
            );
        }

        if (($permissions['review_chef'] ?? false) && $validationStatus === 'soumise_chef') {
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
            $isCorrection = in_array($validationStatus, ['correction_demandee', 'correction_controle', 'rejetee_chef'], true);

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

        if (in_array($validationStatus, ['validee_controle', 'validee_direction'], true)) {
            return $this->step(
                'Dossier cloture',
                'Consulter le resultat officiel',
                "L'execution a ete validee par le controle. Les resultats, les preuves et les decisions restent consultables.",
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
        $isClosed = in_array((string) $action->statut_validation, ['validee_controle', 'validee_direction'], true);

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

    private function activeDeadlineRequest(Action $action): ?DeadlineExtensionRequest
    {
        return $action->deadlineExtensionRequests->first(
            static fn (DeadlineExtensionRequest $request): bool => in_array((string) $request->status, [
                DeadlineExtensionRequest::STATUS_SOUMISE,
                DeadlineExtensionRequest::STATUS_EN_ANALYSE,
                DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE,
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
