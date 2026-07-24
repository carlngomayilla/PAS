<?php

namespace App\Http\Requests;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewDeadlineExtensionByChefRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');

        return $user instanceof User
            && $deadlineExtensionRequest instanceof DeadlineExtensionRequest
            && $deadlineExtensionRequest->action !== null
            && $user->can('reviewDeadlineExtensionByChef', $deadlineExtensionRequest->action);
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
                    DeadlineExtensionRequest::AVIS_FAVORABLE,
                    DeadlineExtensionRequest::AVIS_DEFAVORABLE,
                    DeadlineExtensionRequest::AVIS_COMPLEMENT,
                ]),
            ],
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
                    in_array($decision, [DeadlineExtensionRequest::AVIS_DEFAVORABLE, DeadlineExtensionRequest::AVIS_COMPLEMENT], true)
                    && $comment === ''
                ) {
                    $validator->errors()->add('comment', 'Un commentaire est requis pour un avis defavorable ou une demande de complement.');
                }
            },
        ];
    }
}
