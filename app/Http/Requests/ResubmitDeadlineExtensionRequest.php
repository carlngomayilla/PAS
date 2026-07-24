<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class ResubmitDeadlineExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');
        $action = $deadlineExtensionRequest instanceof DeadlineExtensionRequest
            ? $deadlineExtensionRequest->action
            : null;

        return $user instanceof User
            && $action instanceof Action
            && (int) $deadlineExtensionRequest->requested_by === (int) $user->id
            && $deadlineExtensionRequest->status === DeadlineExtensionRequest::STATUS_COMPLEMENT_DEMANDE
            && $user->can('requestDeadlineExtension', $action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $documentPolicy = app(DocumentPolicySettings::class);

        return [
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
                $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');
                $requestedDeadline = $this->input('requested_deadline');
                if (! $deadlineExtensionRequest instanceof DeadlineExtensionRequest
                    || ! is_string($requestedDeadline)
                    || trim($requestedDeadline) === '') {
                    return;
                }

                if (Carbon::parse($requestedDeadline)->startOfDay()->lessThanOrEqualTo(
                    Carbon::parse($deadlineExtensionRequest->old_deadline)->startOfDay()
                )) {
                    $validator->errors()->add(
                        'requested_deadline',
                        'La nouvelle echeance doit etre posterieure a l echeance actuelle.'
                    );
                }
            },
        ];
    }
}
