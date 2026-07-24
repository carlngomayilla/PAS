<?php

namespace App\Http\Requests;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewDeadlineExtensionByDgRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');

        return $user instanceof User
            && $deadlineExtensionRequest instanceof DeadlineExtensionRequest
            && $deadlineExtensionRequest->action !== null
            && $user->can('reviewDeadlineExtensionByDg', $deadlineExtensionRequest->action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    DeadlineExtensionRequest::DECISION_APPROUVER,
                    DeadlineExtensionRequest::DECISION_REJETER,
                    DeadlineExtensionRequest::DECISION_COMPLEMENT,
                ]),
            ],
            'approved_deadline' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $decision = (string) $this->input('decision');
                $comment = trim((string) $this->input('comment', ''));

                if (
                    in_array($decision, [DeadlineExtensionRequest::DECISION_REJETER, DeadlineExtensionRequest::DECISION_COMPLEMENT], true)
                    && $comment === ''
                ) {
                    $validator->errors()->add('comment', 'Un commentaire est requis pour un rejet ou une demande de complement.');
                }

                $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');
                if (! $deadlineExtensionRequest instanceof DeadlineExtensionRequest) {
                    return;
                }

                $approvedDeadline = trim((string) $this->input('approved_deadline', ''));
                if ($decision !== DeadlineExtensionRequest::DECISION_APPROUVER || $approvedDeadline === '') {
                    return;
                }

                if (Carbon::parse($approvedDeadline)->startOfDay()->lessThanOrEqualTo(Carbon::parse($deadlineExtensionRequest->old_deadline)->startOfDay())) {
                    $validator->errors()->add('approved_deadline', 'L echeance approuvee doit etre posterieure a l echeance actuelle.');
                }
            },
        ];
    }
}
