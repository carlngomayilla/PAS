<?php

namespace App\Http\Requests;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewDeadlineExtensionByDirectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');

        return $user instanceof User
            && $deadlineExtensionRequest instanceof DeadlineExtensionRequest
            && $deadlineExtensionRequest->action !== null
            && $user->can('reviewDeadlineExtensionByDirector', $deadlineExtensionRequest->action);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    DeadlineExtensionRequest::AVIS_FAVORABLE,
                    DeadlineExtensionRequest::AVIS_DEFAVORABLE,
                    DeadlineExtensionRequest::AVIS_COMPLEMENT,
                ]),
            ],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (in_array((string) $this->input('decision'), [
                    DeadlineExtensionRequest::AVIS_DEFAVORABLE,
                    DeadlineExtensionRequest::AVIS_COMPLEMENT,
                ], true) && trim((string) $this->input('comment', '')) === '') {
                    $validator->errors()->add('comment', 'Un commentaire est requis pour un refus ou une demande de complément.');
                }
            },
        ];
    }
}
