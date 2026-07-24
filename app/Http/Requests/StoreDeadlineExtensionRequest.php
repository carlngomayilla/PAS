<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\SousAction;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class StoreDeadlineExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $action = $this->route('action');

        return $user instanceof User
            && $action instanceof Action
            && $user->can('requestDeadlineExtension', $action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
            'sous_action_id' => ['nullable', 'integer', 'exists:sous_actions,id'],
            'requested_deadline' => ['required', 'date_format:Y-m-d', 'after:today'],
            'motif' => ['required', 'string', 'min:5', 'max:255'],
            'justification' => ['required', 'string', 'min:10', 'max:5000'],
            'piece_justificative' => [
                'required',
                'file',
                'max:'.$documentPolicy->maxUploadKilobytes(),
                $documentPolicy->mimesRule(),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $action = $this->route('action');
                if (! $action instanceof Action) {
                    return;
                }

                $sousAction = $this->selectedSousAction($action, $validator);
                if (! $this->canRequestSelectedTarget($action, $sousAction)) {
                    $validator->errors()->add(
                        'sous_action_id',
                        'Un agent affecte a une sous-action peut demander un report uniquement pour cette sous-action.'
                    );

                    return;
                }

                $referenceDeadline = $this->referenceDeadline($action, $sousAction);
                if ($referenceDeadline === null) {
                    $validator->errors()->add('requested_deadline', 'Aucune echeance de reference n est definie pour cet element.');

                    return;
                }

                $requestedDeadline = $this->input('requested_deadline');
                if (! is_string($requestedDeadline) || trim($requestedDeadline) === '') {
                    return;
                }

                if (Carbon::parse($requestedDeadline)->startOfDay()->lessThanOrEqualTo($referenceDeadline)) {
                    $validator->errors()->add('requested_deadline', 'La nouvelle echeance doit etre posterieure a l echeance actuelle.');
                }
            },
        ];
    }

    private function selectedSousAction(Action $action, Validator $validator): ?SousAction
    {
        $sousActionId = (int) $this->input('sous_action_id', 0);
        if ($sousActionId <= 0) {
            return null;
        }

        $sousAction = $action->sousActions()->whereKey($sousActionId)->first();
        if (! $sousAction instanceof SousAction) {
            $validator->errors()->add('sous_action_id', 'La sous-action selectionnee ne correspond pas a cette action.');

            return null;
        }

        return $sousAction;
    }

    private function referenceDeadline(Action $action, ?SousAction $sousAction): ?Carbon
    {
        $deadline = $sousAction?->date_fin
            ?? $action->date_fin
            ?? $action->date_echeance
            ?? $action->echeance_cible;

        return $deadline === null ? null : Carbon::parse($deadline)->startOfDay();
    }

    private function canRequestSelectedTarget(Action $action, ?SousAction $sousAction): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->isAgent() || $action->isResponsible($user)) {
            return true;
        }

        return $sousAction instanceof SousAction
            && (int) $sousAction->agent_id === (int) $user->id;
    }
}
