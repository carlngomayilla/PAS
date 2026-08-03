<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\SousAction;
use App\Models\User;
use Illuminate\Validation\Validator;

class ResubmitDeadlineExtensionRequest extends StoreDeadlineExtensionRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deadlineExtensionRequest = $this->deadlineExtensionRequest();
        $action = $deadlineExtensionRequest?->action;

        return $user instanceof User
            && $action instanceof Action
            && (int) $deadlineExtensionRequest->requested_by === (int) $user->id
            && $deadlineExtensionRequest->status === DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE
            && $user->can('requestDeadlineExtension', $action);
    }

    protected function prepareForValidation(): void
    {
        $deadlineExtensionRequest = $this->deadlineExtensionRequest();
        if (! $deadlineExtensionRequest instanceof DeadlineExtensionRequest) {
            return;
        }

        $this->merge([
            'sous_action_id' => $deadlineExtensionRequest->sous_action_id,
        ]);
    }

    protected function actionForValidation(): ?Action
    {
        return $this->deadlineExtensionRequest()?->action;
    }

    protected function selectedSousAction(Action $action, Validator $validator): ?SousAction
    {
        $deadlineExtensionRequest = $this->deadlineExtensionRequest();

        return $deadlineExtensionRequest?->sousAction;
    }

    private function deadlineExtensionRequest(): ?DeadlineExtensionRequest
    {
        $request = $this->route('deadlineExtensionRequest');

        return $request instanceof DeadlineExtensionRequest ? $request : null;
    }
}
